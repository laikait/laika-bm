<?php
/**
 * Laika Bill Manager
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: MIT
 * This file is part of Laika Bill Manager.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace LBM\Module;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use RuntimeException;
use Throwable;
use ZipArchive;
use Laika\Service\Upload;

/**
 * Installing a module from an uploaded archive.
 *
 * This is the first file upload anywhere in LBM, and it is also the only one
 * that installs code. Both facts shape everything below.
 *
 * ------------------------------------------------------------------------
 * The manifest is NOT executed here
 * ------------------------------------------------------------------------
 * `module.php` is PHP, so reading it to check what it declares would run the
 * uploaded code at upload time. That would break the property the whole design
 * rests on: **an uploaded module lands disabled, and a disabled module's
 * manifest is never read, its classes are never autoloadable and its routes do
 * not exist.** Nothing an operator uploads may run until they switch it on.
 *
 * So validation here is structural only - the archive is shaped like a module.
 * Whether the manifest is any good is answered by the Modules screen, which
 * already reads manifests defensively and shows the error beside a Disable
 * button. That is the right place for it and it already exists.
 *
 * ------------------------------------------------------------------------
 * The operator chooses the type; the archive does not
 * ------------------------------------------------------------------------
 * `modules/README.md` states the rule: the directory a module sits in decides
 * its kind, and the manifest is not asked, "so a module cannot claim to be
 * something it is not by editing a string". Honouring a `type` key from the
 * upload would hand back exactly that claim, so the kind comes from the form.
 *
 * ------------------------------------------------------------------------
 * A zip is attacker-controlled input
 * ------------------------------------------------------------------------
 * Every entry name is checked before anything is written: no absolute paths, no
 * `..`, no drive letters, no backslashes. Entry count and unpacked size are
 * capped, because the compressed size on disk says nothing about either.
 */
final class ModuleInstaller
{
    /** @var int Largest Archive Accepted, In Bytes */
    public const MAX_BYTES = 20971520;

    /** @var int Most Entries An Archive May Contain */
    public const MAX_ENTRIES = 2000;

    /** @var int Largest Total Unpacked Size, In Bytes */
    public const MAX_UNPACKED = 104857600;

    /** @var string[] Accepted Upload Extensions */
    public const EXTENSIONS = ['zip'];

    /**
     * Install a Module From an Uploaded Archive
     *
     * @param array $file One entry from $_FILES
     * @param string $type One of ModuleManager::TYPES
     * @return array{uid:string,name:string,type:string,path:string}
     * @throws RuntimeException With a message meant for the operator
     */
    public function install(array $file, string $type): array
    {
        $type = strtolower(trim($type));

        if (!in_array($type, ModuleManager::TYPES, true)) {
            throw new RuntimeException('Choose what kind of module this is.');
        }

        $this->checkUpload($file);

        $staging = $this->staging();

        try {
            $archive = $this->receive($file, $staging);
            $folder  = $this->inspect($archive);

            $this->unpack($archive, $staging . DS . 'unpacked');

            $source = $staging . DS . 'unpacked' . DS . $folder;
            $target = ModuleManager::path() . DS . $type . DS . $folder;

            if (is_dir($target)) {
                throw new RuntimeException(
                    'A module called ' . $folder . ' is already installed under ' . $type
                    . '. Remove it first - replacing one in place is not something this does.'
                );
            }

            if (!@rename($source, $target)) {
                throw new RuntimeException('Could not move the module into ' . $type . '. Check the directory is writable.');
            }

            return [
                'uid'  =>  ModuleManager::uid($type, $folder),
                'name' =>  $folder,
                'type' =>  $type,
                'path' =>  $target,
            ];
        } finally {
            // Whatever happened, nothing of the upload is left behind. A
            // rejected archive that stayed on disk would be an uploaded file
            // sitting inside the application with nobody responsible for it.
            $this->purge($staging);
        }
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Refuse a Bad Upload Before Touching The Disk
     * @param array $file One entry from $_FILES
     * @return void
     */
    private function checkUpload(array $file): void
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('Choose a module archive to upload.');
        }

        // INI_SIZE and FORM_SIZE are the two an operator can actually act on,
        // and "no file was uploaded" would be a baffling thing to tell somebody
        // who watched a 40MB file upload.
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new RuntimeException('That archive is larger than this server accepts. Check upload_max_filesize and post_max_size.');
        }

        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('The upload did not complete. Try again.');
        }

        if ((int) ($file['size'] ?? 0) > self::MAX_BYTES) {
            throw new RuntimeException('Module archives are limited to ' . (int) round(self::MAX_BYTES / 1048576) . 'MB.');
        }

        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));

        if (!in_array($extension, self::EXTENSIONS, true)) {
            throw new RuntimeException('A module is uploaded as a .zip archive.');
        }
    }

    /**
     * A Private Directory To Work In
     * @return string
     */
    private function staging(): string
    {
        $path = APP_PATH . DS . 'lf-storage' . DS . 'lbm' . DS . 'module-upload-' . bin2hex(random_bytes(8));

        if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
            throw new RuntimeException('Could not create a working directory for the upload.');
        }

        return $path;
    }

    /**
     * Move The Upload Into Staging
     *
     * Below lf-storage, which is not web-servable, rather than anywhere under
     * the document root. The archive is untrusted until it has been inspected
     * and it must not be reachable over HTTP for even one request.
     *
     * @param array $file One entry from $_FILES
     * @param string $staging Working Directory
     * @return string Path To The Stored Archive
     */
    private function receive(array $file, string $staging): string
    {
        $stored = Upload::init($file)->single($staging, 'module', [
            'maxsize'    =>  self::MAX_BYTES,
            'extensions' =>  self::EXTENSIONS,
        ]);

        if ($stored === false || !is_file($stored)) {
            throw new RuntimeException('The upload could not be saved. Check that lf-storage is writable.');
        }

        return $stored;
    }

    /**
     * Read The Archive And Decide Whether It Is a Module
     *
     * Nothing is written during this - the archive is only listed. Rejecting
     * before extraction is the whole point: an archive that fails here has
     * never had a single byte of it placed on disk as a file.
     *
     * @param string $archive Path To The Stored Archive
     * @return string The Single Top-Level Directory Name
     */
    private function inspect(string $archive): string
    {
        $zip = new ZipArchive();

        if ($zip->open($archive) !== true) {
            throw new RuntimeException('That file is not a readable zip archive.');
        }

        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new RuntimeException('That archive contains too many files to be a module.');
            }

            $roots    = [];
            $unpacked = 0;
            $manifest = false;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);

                if ($stat === false) {
                    throw new RuntimeException('That archive could not be read.');
                }

                $name = (string) $stat['name'];
                $this->checkEntry($name);

                $unpacked += (int) $stat['size'];

                if ($unpacked > self::MAX_UNPACKED) {
                    throw new RuntimeException('That archive unpacks to more than this accepts.');
                }

                $parts = explode('/', trim($name, '/'));
                $roots[$parts[0]] = true;

                if (count($parts) === 2 && $parts[1] === ModuleManager::MANIFEST) {
                    $manifest = true;
                }
            }

            // Exactly one, because the directory name becomes the module's name
            // and its uid. An archive of loose files, or of several modules, has
            // no single answer to "what is this called".
            if (count($roots) !== 1) {
                throw new RuntimeException(
                    'A module archive holds exactly one directory, and this holds ' . count($roots) . '.'
                );
            }

            $folder = (string) array_key_first($roots);

            if (!$manifest) {
                throw new RuntimeException(
                    'No ' . ModuleManager::MANIFEST . ' inside ' . $folder . '. That file is what makes a directory a module.'
                );
            }

            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $folder)) {
                throw new RuntimeException('The module directory name may only use letters, numbers, hyphens and underscores.');
            }

            return $folder;
        } finally {
            $zip->close();
        }
    }

    /**
     * Refuse an Entry That Would Write Outside The Target
     *
     * Checked on the listing, before extraction, because extractTo() writes as
     * it goes - finding the bad entry afterwards is finding it too late.
     *
     * @param string $name Entry Name From The Archive
     * @return void
     */
    private function checkEntry(string $name): void
    {
        if ($name === '' || str_contains($name, "\0")) {
            throw new RuntimeException('That archive contains an unusable file name.');
        }

        // Backslashes are checked as well as slashes: a zip written on Windows
        // can carry them, and a check that only looked at '/' would read
        // '..\..\lf-config' as one harmless-looking segment.
        $normalised = str_replace('\\', '/', $name);

        if (str_starts_with($normalised, '/') || preg_match('#^[A-Za-z]:#', $normalised)) {
            throw new RuntimeException('That archive contains an absolute path: ' . $name);
        }

        foreach (explode('/', $normalised) as $segment) {
            if ($segment === '..') {
                throw new RuntimeException('That archive tries to write outside the module directory: ' . $name);
            }
        }
    }

    /**
     * Extract, After It Has Been Accepted
     * @param string $archive Path To The Stored Archive
     * @param string $into Directory To Unpack Into
     * @return void
     */
    private function unpack(string $archive, string $into): void
    {
        if (!is_dir($into) && !mkdir($into, 0755, true) && !is_dir($into)) {
            throw new RuntimeException('Could not create a directory to unpack into.');
        }

        $zip = new ZipArchive();

        if ($zip->open($archive) !== true) {
            throw new RuntimeException('That file is not a readable zip archive.');
        }

        $ok = $zip->extractTo($into);
        $zip->close();

        if (!$ok) {
            throw new RuntimeException('The archive could not be unpacked.');
        }
    }

    /**
     * Remove The Working Directory, Whatever Is In It
     * @param string $path Directory To Remove
     * @return void
     */
    private function purge(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        try {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($items as $item) {
                /** @var \SplFileInfo $item */
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }

            rmdir($path);
        } catch (Throwable) {
            // Cleanup is best effort. Failing to tidy a temp directory is not a
            // reason to tell the operator their module did not install, and the
            // directory is below lf-storage where nothing can reach it anyway.
        }
    }
}
