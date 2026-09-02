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

namespace LBM\Action;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Throwable;
use Laika\Model\Model;
use LBM\Module\ModuleManager;

/**
 * What is installed in the app root's `modules/` directory.
 *
 * This reads manifests and remembers which are switched on. It deliberately
 * does *not* load them - `LBM\Module\ModuleManager` does that, during composer's
 * autoload, long before this class is ever constructed.
 *
 * Keeping the two apart is what makes the admin screen safe: it lists every
 * module by reading manifests defensively, so a broken one shows up as broken
 * and can be switched off, rather than taking the application down before the
 * screen that would have fixed it can render.
 *
 * Modules live at the app root rather than inside this package because they are
 * the operator's own code: they must survive `composer update` and a
 * reinstall of vendor/.
 *
 * Enabled state is an option per module rather than a table, because it is one
 * boolean per installed directory and a table would need migrating, seeding and
 * cleaning up after a module somebody deleted by hand.
 */
class Module extends Action
{
    // These three describe the same directory layout ModuleManager walks, so
    // they are aliased from it rather than restated. They had been restated,
    // and TYPES drifted: this class listed `fraud` and `widgets` while the
    // loader listed `plugins`. The result was silent both ways - a module in
    // modules/fraud appeared on the admin screen and was never loaded, and one
    // in modules/plugins was loaded and never appeared.
    //
    // ModuleManager is the authority because it is the half that actually loads
    // modules, and it is already resolved by the time this class exists: it runs
    // during composer's autoload, long before any Action is constructed.

    /** @var string Where Modules Live, Below The App Root */
    public const ROOT = ModuleManager::ROOT;

    /** @var string The File That Makes a Directory a Module */
    public const MANIFEST = ModuleManager::MANIFEST;

    /** @var string[] The Kinds Of Module, Which Are Also The Subdirectories */
    public const TYPES = ModuleManager::TYPES;

    /** @var string Option Key Prefix For The Enabled Flag */
    public const OPTION = 'module_enabled_';

    /** @var array<string,array>|null Discovered Modules, Keyed By Uid */
    private ?array $modules = null;

    /**
     * There Is No Modules Table
     *
     * The base class needs a model to build on; nothing here reads or writes
     * one. Manifests come off disk and the enabled flag is an option.
     * @return Model
     */
    public function model(): Model
    {
        return (new Model())->table('options');
    }

    ####################################################################################
    /*================================= EXTERNAL API =================================*/
    ####################################################################################

    /**
     * Every Module On Disk
     * @return array<string,array>
     */
    public function all(array $where = [], string $direction = self::ASC, ?string $order = null): array
    {
        if ($this->modules !== null) {
            return $this->modules;
        }

        $modules = [];

        foreach (self::TYPES as $type) {
            foreach ($this->scan($type) as $module) {
                $modules[$module['uid']] = $module;
            }
        }

        uasort($modules, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $this->modules = $modules;
    }

    /**
     * One Module By Its Identifier
     * @param int|string|null $key Module Uid
     * @return ?array
     */
    public function find(int|string|null $key): ?array
    {
        if ($key === null || $key === '') {
            return null;
        }

        return $this->all()[(string) $key] ?? null;
    }

    /**
     * Modules Of One Kind
     * @param string $type One of TYPES
     * @return array
     */
    public function ofType(string $type): array
    {
        return array_filter(
            $this->all(),
            static fn(array $module): bool => $module['type'] === $type
        );
    }

    /**
     * Whether a Module Is Switched On
     * @param string $uid Module Uid
     * @return bool
     */
    public function isEnabled(string $uid): bool
    {
        return option_bool(self::OPTION . $uid);
    }

    /**
     * Switch a Module On Or Off
     *
     * The option is the source of truth; the loader's cache is rewritten in the
     * same breath, so the change takes effect on the very next request rather
     * than whenever something else happens to rebuild it.
     * @param string $uid Module Uid
     * @param ?bool $enabled Null flips whatever it is now
     * @return bool The state it ended up in
     */
    public function toggle(string $uid, ?bool $enabled = null): bool
    {
        $state = $enabled ?? !$this->isEnabled($uid);

        (new Setting())->put(self::OPTION . $uid, $state);

        $this->rebuildCache();

        return $state;
    }

    /**
     * How Many Modules Are Installed
     * @param array $where Ignored - modules are not queried
     * @return int
     */
    public function count(array $where = []): int
    {
        return count($this->all());
    }

    ################################################################################
    /*============================= THE LOADER'S VIEW ============================*/
    ################################################################################
    //
    // Enabled and loaded are different questions, and the admin screen needs both.
    // A module switched on a moment ago is enabled but not loaded until the next
    // request; one whose manifest throws is enabled and failed. Reporting only
    // "enabled" would show a green tick beside a module that is doing nothing.

    /**
     * Which Modules Are Switched On, According To The Options Table
     *
     * Only ones actually on disk. An option left behind by a module somebody
     * deleted by hand would otherwise sit in the cache forever - the option key
     * is the only trace such a module leaves.
     * @return string[]
     */
    public function enabledUids(): array
    {
        $uids = [];

        foreach (array_keys($this->all()) as $uid) {
            if ($this->isEnabled((string) $uid)) {
                $uids[] = (string) $uid;
            }
        }

        return $uids;
    }

    /**
     * Write The Loader's Cache From The Options Table
     *
     * `ModuleManager` runs during composer's autoload, where there is no
     * database and no `option()`, so it reads a generated file instead. This is
     * what generates it - called from here, where the database is open.
     * @return bool Whether it was written
     */
    public function rebuildCache(): bool
    {
        $this->flush();

        return ModuleManager::writeCache($this->enabledUids());
    }

    /**
     * Whether The Loader's Cache Exists
     * @return bool
     */
    public function cached(): bool
    {
        return ModuleManager::cached();
    }

    /**
     * Whether a Module Is Loaded In This Request
     * @param string $uid Module Uid
     * @return bool
     */
    public function isLoaded(string $uid): bool
    {
        return ModuleManager::isLoaded($uid);
    }

    /**
     * Why An Enabled Module Did Not Load
     * @param string $uid Module Uid
     * @return ?string
     */
    public function loadError(string $uid): ?string
    {
        return ModuleManager::failed()[$uid] ?? null;
    }

    /**
     * What One Loaded Module Registered With The Framework
     * @param string $uid Module Uid
     * @return string[]
     */
    public function loadedResources(string $uid): array
    {
        return ModuleManager::loaded()[$uid]['resources'] ?? [];
    }

    /**
     * Forget What Was Read Off Disk
     * @return void
     */
    public function flush(): void
    {
        $this->modules = null;
    }

    /**
     * The Kinds Of Module There Are
     *
     * A method rather than the constant, because a relay facade forwards method
     * calls and not constants.
     * @return string[]
     */
    public function types(): array
    {
        return self::TYPES;
    }

    /**
     * Where Modules Are Expected To Live
     * @return string
     */
    public function path(): string
    {
        return APP_PATH . self::ROOT;
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Read Every Manifest Of One Kind
     * @param string $type Subdirectory
     * @return array
     */
    private function scan(string $type): array
    {
        $base = $this->path() . '/' . $type;

        if (!is_dir($base)) {
            return [];
        }

        $modules = [];

        foreach (glob($base . '/*/' . self::MANIFEST) ?: [] as $file) {
            $module = $this->read($file, $type);

            if ($module !== null) {
                $modules[] = $module;
            }
        }

        return $modules;
    }

    /**
     * Read One Manifest
     *
     * A manifest is PHP that returns an array, so a broken one can throw or
     * fatal. Anything that goes wrong is reported as a module in an error state
     * rather than propagated: the whole point of this screen is to be reachable
     * when a module is broken, so somebody can switch it off.
     * @param string $file Manifest Path
     * @param string $type Subdirectory
     * @return ?array
     */
    private function read(string $file, string $type): ?array
    {
        $directory = basename(dirname($file));

        $module = [
            'uid'       =>  $this->uid($type, $directory),
            'type'      =>  $type,
            'directory' =>  $directory,
            'path'      =>  dirname($file),
            'name'      =>  $directory,
            'version'   =>  '',
            'author'    =>  '',
            'error'     =>  null,
        ];

        try {
            $manifest = require $file;

            if (!is_array($manifest)) {
                $module['error'] = 'The manifest did not return an array.';

                return $module;
            }

            $module['name'] = (string) ($manifest['name'] ?? $directory);
            $module['version'] = (string) ($manifest['version'] ?? '');
            $module['author'] = (string) ($manifest['author'] ?? '');
            $module['description'] = (string) ($manifest['description'] ?? '');
            $module['class'] = (string) ($manifest['class'] ?? '');
        } catch (Throwable $e) {
            $module['error'] = $e->getMessage();
        }

        return $module;
    }

    /**
     * A Stable Identifier For a Module
     *
     * Derived from where it sits rather than stored, so it survives being
     * disabled, deleted and put back - and so the enabled option key for
     * `gateways/Stripe` is the same on every install.
     * @param string $type Subdirectory
     * @param string $directory Module Directory
     * @return string
     */
    private function uid(string $type, string $directory): string
    {
        return strtolower($type . '-' . preg_replace('/[^a-zA-Z0-9]+/', '-', $directory));
    }
}
