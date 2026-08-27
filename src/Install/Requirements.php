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

namespace LBM\Install;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

/**
 * What this server needs before LBM can be installed.
 *
 * Reports honestly and blocks on anything required. Letting somebody past a
 * missing PDO driver only moves the failure to a later step where it is harder
 * to explain - and possibly to a half-migrated database.
 */
class Requirements
{
    /** @var string Minimum PHP Version */
    public const PHP = '8.1.0';

    /**
     * @var array<string,string> Required Extensions => Why
     *
     * bcmath is not optional: Laika\Core\Helper\Math throws without it, and
     * every money calculation in the app goes through Math to avoid float
     * drift on invoice totals.
     */
    public const EXTENSIONS = [
        'pdo'      =>  'Database access',
        'json'     =>  'Permissions and settings are stored as JSON',
        'mbstring' =>  'Multi-byte safe string handling',
        'openssl'  =>  'Password hashing, tokens and TLS for SMTP',
        'bcmath'   =>  'Exact money arithmetic - totals would drift without it',
    ];

    /** @var array<string,string> Optional Extensions => What They Add */
    public const OPTIONAL = [
        'curl'     =>  'Outbound calls from payment and provisioning modules',
        'fileinfo' =>  'MIME detection for ticket attachments',
        'gd'       =>  'Resizing uploaded logos',
        'zip'      =>  'Installing modules from an archive',
    ];

    /** @var string[] Directories That Must Be Writable, Relative to APP_PATH */
    public const PATHS = [
        'lf-config',
        'lf-cache',
        'lf-logs',
        'lf-storage',
        'uploads',
        'template',
    ];

    /**
     * Run Every Check
     * @return array{passed:bool,php:array,extensions:array,paths:array,failed:int}
     */
    public function all(): array
    {
        $php = $this->php();
        $extensions = $this->extensions();
        $paths = $this->paths();

        $failed = 0;

        foreach ([$php, $extensions, $paths] as $group) {
            foreach ($group as $item) {
                if ($item['required'] && !$item['ok']) {
                    $failed++;
                }
            }
        }

        return [
            'passed'     =>  $failed === 0,
            'failed'     =>  $failed,
            'php'        =>  $php,
            'extensions' =>  $extensions,
            'paths'      =>  $paths,
        ];
    }

    /**
     * Whether Every Required Check Passes
     * @return bool
     */
    public function passed(): bool
    {
        return $this->all()['passed'];
    }

    /**
     * PHP Version
     * @return array<int,array>
     */
    public function php(): array
    {
        $ok = version_compare(PHP_VERSION, self::PHP, '>=');

        return [
            $this->item(
                'PHP ' . self::PHP . ' or newer',
                $ok,
                PHP_VERSION,
                true,
                $ok ? '' : 'This server runs PHP ' . PHP_VERSION . '.'
            ),
        ];
    }

    /**
     * Extensions
     * @return array<int,array>
     */
    public function extensions(): array
    {
        $items = [];

        foreach (self::EXTENSIONS as $name => $why) {
            $ok = extension_loaded($name);
            $items[] = $this->item($name, $ok, $ok ? 'loaded' : 'missing', true, $why);
        }

        // A PDO driver on its own is not enough - PDO with no driver at all
        // cannot reach any database, so at least one has to be present.
        $drivers = $this->drivers();
        $items[] = $this->item(
            'A PDO database driver',
            $drivers !== [],
            $drivers ? implode(', ', array_keys($drivers)) : 'none',
            true,
            'MySQL/MariaDB, PostgreSQL, SQLite or SQL Server'
        );

        foreach (self::OPTIONAL as $name => $why) {
            $ok = extension_loaded($name);
            $items[] = $this->item($name, $ok, $ok ? 'loaded' : 'not installed', false, $why);
        }

        return $items;
    }

    /**
     * Writable Directories
     * @return array<int,array>
     */
    public function paths(): array
    {
        $items = [];

        foreach (self::PATHS as $path) {
            $full = APP_PATH . '/' . $path;

            if (!is_dir($full)) {
                $items[] = $this->item($path, false, 'missing', true, 'Create this directory.');
                continue;
            }

            $ok = is_writable($full);
            $items[] = $this->item($path, $ok, $ok ? 'writable' : 'not writable', true,
                $ok ? '' : 'The web server user needs write access here.');
        }

        return $items;
    }

    /**
     * PDO Drivers Available On This Server
     *
     * Only drivers laika-model has a grammar for are offered - listing one it
     * cannot build SQL for would fail at the migrate step instead of here.
     * @return array<string,string> driver => label
     */
    public function drivers(): array
    {
        $supported = [
            'mysql'  =>  'MySQL / MariaDB',
            'pgsql'  =>  'PostgreSQL',
            'sqlite' =>  'SQLite',
            'sqlsrv' =>  'SQL Server',
        ];

        return array_intersect_key($supported, array_flip(\PDO::getAvailableDrivers()));
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Shape One Check For The View
     * @param string $label Label
     * @param bool $ok Passed
     * @param string $state Short State Text
     * @param bool $required Required
     * @param string $hint Explanation
     * @return array{label:string,ok:bool,state:string,required:bool,hint:string}
     */
    private function item(string $label, bool $ok, string $state, bool $required, string $hint = ''): array
    {
        return [
            'label'    =>  $label,
            'ok'       =>  $ok,
            'state'    =>  $state,
            'required' =>  $required,
            'hint'     =>  $hint,
        ];
    }
}
