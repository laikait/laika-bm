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
use LBM\Model\ModuleModel;
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
 * the operator's own code: they must survive `composer update` and a reinstall
 * of vendor/.
 *
 * ---------------------------------------------------------------------------
 * WHERE THE STATE LIVES - CHANGED IN PHASE 31
 * ---------------------------------------------------------------------------
 * It used to be an option row per module, `module_enabled_<uid>`, and this
 * class said so in as many words: `model()` returned a Model pointed at
 * `options` under a docblock reading "There Is No Modules Table". Everything
 * else about a module - version, author, what it declares - was re-read off
 * disk on every request and could not be asked a question about.
 *
 * There is a `modules` table now. What has NOT changed, and cannot, is that the
 * loader still reads a generated file: `ModuleManager::discover()` runs inside
 * composer's `files` autoload, where there is no database, no `option()`, and
 * on a fresh checkout no schema at all. So the pairing Phase 20.1 built is
 * intact - one record of truth, one generated projection - and only the record
 * of truth has moved.
 *
 * ---------------------------------------------------------------------------
 * DISK DECIDES WHAT EXISTS; THE TABLE REMEMBERS WHAT WE KNOW ABOUT IT
 * ---------------------------------------------------------------------------
 * `all()` still scans the directory, and a module that is not there is not
 * listed whatever the table says. A row is a record ABOUT a directory and never
 * a substitute for one - which is the rule that stops a module somebody deleted
 * by hand sitting in the loader's cache for ever.
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

    /**
     * @var string The Option Key Prefix The Enabled Flag USED To Live Under
     *
     * Kept after Phase 31 moved the flag into the `modules` table, because the
     * migration that carries those rows across has to be able to find them -
     * and because somebody meeting `module_enabled_gateways-stripe` in an older
     * installation's options table deserves something to grep for.
     *
     * Nothing writes it any more.
     */
    public const OPTION = 'module_enabled_';

    /** @var array<string,array>|null Discovered Modules, Keyed By Uid */
    private ?array $modules = null;

    /**
     * The Modules Table
     * @return Model
     */
    public function model(): Model
    {
        return new ModuleModel();
    }

    protected function createdColumn(): ?string
    {
        return 'module_created_at';
    }

    protected function updatedColumn(): ?string
    {
        return 'module_updated_at';
    }

    ####################################################################################
    /*================================= EXTERNAL API =================================*/
    ####################################################################################

    /**
     * Every Module On Disk, With What The Table Knows About It
     *
     * Disk is the list; the table supplies the switch and whatever was recorded
     * when we last looked. A module with no row yet is reported disabled, which
     * is both the safe answer and the documented one - nothing an operator drops
     * on disk runs until they say so.
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

        $rows = $this->rows();

        foreach ($modules as $uid => $module) {
            $row = $rows[$uid] ?? null;

            $modules[$uid]['enabled'] = $row !== null && (string) $row['is_enabled'] === 'yes';
            $modules[$uid]['installed_at'] = $row['installed_at'] ?? null;

            // record() RETURNS the id, and it has to. Setting module_id from
            // $row and then reconciling underneath it reported 0 for a module
            // whose row had just been inserted one line further down - so
            // toggle() read that 0, took its own insert branch, and hit the
            // UNIQUE key on uid. Every module met for the first time crashed
            // the moment it was switched on.
            $modules[$uid]['module_id'] = $this->record($uid, $modules[$uid], $row);
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
        return (bool) ($this->all()[$uid]['enabled'] ?? false);
    }

    /**
     * Switch a Module On Or Off
     *
     * The table is the source of truth; the loader's cache is rewritten in the
     * same breath, so the change takes effect on the very next request rather
     * than whenever something else happens to rebuild it.
     *
     * A module with no row yet gets one here rather than being refused - the
     * table fills in as modules are met, not through an install step somebody
     * has to remember.
     * @param string $uid Module Uid
     * @param ?bool $enabled Null flips whatever it is now
     * @return bool The state it ended up in
     */
    public function toggle(string $uid, ?bool $enabled = null): bool
    {
        $module = $this->find($uid);

        // Not on disk, so there is nothing to switch. Answering false rather
        // than writing a row keeps the rule this class is built on: the table
        // records directories, it does not invent them.
        if ($module === null) {
            return false;
        }

        $state = $enabled ?? !$this->isEnabled($uid);
        $id = (int) ($module['module_id'] ?? 0);

        if ($id > 0) {
            $this->update($id, ['is_enabled' => $state ? 'yes' : 'no']);
        } else {
            // find() reconciles, so every module it can return already has a
            // row - which means arriving here at all says that insert failed:
            // a read-only database, or a release deployed but not yet migrated.
            //
            // Deliberately NOT wrapped. record() swallows its failures because
            // it is bookkeeping that happens while somebody looks at a list;
            // this is a button somebody pressed, and a switch that silently
            // updates no rows is the worst outcome available - the screen says
            // enabled, the loader never hears, and there is nothing to find.
            $this->insertRow($uid, $module, $state);
        }

        // rebuildCache() flushes first and then reads every module's state back
        // out of the table, so the write above is what it sees.
        //
        // NO PROCESS-WIDE SHADOW IS NEEDED ANY MORE. There used to be one -
        // a static `$written` array - because option() memoises per key into a
        // static with nothing that can clear it, so a second toggle in one
        // process re-read the value the key had the first time and wrote a
        // loader cache that disagreed with the options table. A table read has
        // no such cache, so flush() is the whole of it.
        $this->rebuildCache();

        return $state;
    }

    /**
     * How Many Modules Are Installed
     * @param array $where Ignored - the list is whatever is on disk
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
     * Which Modules Are Switched On
     *
     * Only ones actually on disk. A row left behind by a module somebody
     * deleted by hand would otherwise sit in the loader's cache for ever - and
     * that row is the only trace such a module leaves, which is exactly why it
     * is kept rather than deleted.
     * @return string[]
     */
    public function enabledUids(): array
    {
        $uids = [];

        foreach ($this->all() as $uid => $module) {
            if (!empty($module['enabled'])) {
                $uids[] = (string) $uid;
            }
        }

        return $uids;
    }

    /**
     * Write The Loader's Cache From The Table
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
     * Forget What Was Read Off Disk And Out Of The Table
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
     * Everything The Table Holds, Keyed By Uid
     *
     * Wrapped, because this is reached from `GlobalPipeline` on a request that
     * may be running before the table exists - during an install, or on the one
     * request between deploying a new release and migrating it. A modules screen
     * that 500s is a modules screen nobody can switch anything off from, which
     * is the failure this whole class is shaped around.
     * @return array<string,array>
     */
    private function rows(): array
    {
        try {
            $rows = $this->model()->get();
        } catch (Throwable) {
            return [];
        }

        $keyed = [];

        foreach ($rows as $row) {
            $keyed[(string) $row['uid']] = $row;
        }

        return $keyed;
    }

    /**
     * Bring One Module's Row Into Line With Its Manifest
     *
     * ONLY WHEN SOMETHING ACTUALLY DIFFERS. Without that guard every load of the
     * modules screen would write a row per module and move `module_updated_at`
     * on all of them - which turns "when did this last change", the one question
     * the column exists to answer, into "when was this page last opened".
     *
     * The manifest wins on every field it owns. `is_enabled` is not one of them:
     * the switch is the operator's and a module does not get a vote on it.
     * @param string $uid Module Uid
     * @param array $module What Is On Disk
     * @param ?array $row What The Table Holds, If Anything
     * @return int The row's id, or 0 if it could not be recorded
     */
    private function record(string $uid, array $module, ?array $row): int
    {
        try {
            if ($row === null) {
                return $this->insertRow($uid, $module, false);
            }

            $changed = [];

            foreach ($this->manifestColumns($module) as $column => $value) {
                if ((string) ($row[$column] ?? '') !== (string) $value) {
                    $changed[$column] = $value;
                }
            }

            if ($changed !== []) {
                $this->update((int) $row['module_id'], $changed);
            }

            return (int) $row['module_id'];
        } catch (Throwable) {
            // Reconciliation is bookkeeping, not behaviour. A read-only database
            // or a table that has not been migrated yet must not stop an
            // operator seeing what is installed.
            return (int) ($row['module_id'] ?? 0);
        }
    }

    /**
     * Record a Module We Have Not Met Before
     * @param string $uid Module Uid
     * @param array $module What Is On Disk
     * @param bool $enabled Whether It Is Switched On
     * @return int The new row's id
     */
    private function insertRow(string $uid, array $module, bool $enabled): int
    {
        return (int) $this->model()->insert($this->stamp(
            $this->manifestColumns($module) + [
                'uid'          =>  $uid,
                'is_enabled'   =>  $enabled ? 'yes' : 'no',
                'installed_at' =>  $this->now(),
            ],
            true
        ));
    }

    /**
     * The Columns A Manifest Owns
     *
     * Everything here is a copy of something on disk, so it is safe to overwrite
     * from the manifest on every reconcile. `is_enabled` and `installed_at` are
     * deliberately absent: one belongs to the operator, the other to the first
     * time we saw the directory.
     * @param array $module What Is On Disk
     * @return array<string,?string>
     */
    private function manifestColumns(array $module): array
    {
        return [
            'module_type'   =>  (string) $module['type'],
            'directory'     =>  (string) $module['directory'],
            'module_name'   =>  (string) $module['name'],
            'version'       =>  ((string) ($module['version'] ?? '')) ?: null,
            'author'        =>  ((string) ($module['author'] ?? '')) ?: null,
            'description'   =>  ((string) ($module['description'] ?? '')) ?: null,
            'module_class'  =>  ((string) ($module['class'] ?? '')) ?: null,
        ];
    }

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
            'uid'         =>  $this->uid($type, $directory),
            'type'        =>  $type,
            'directory'   =>  $directory,
            'path'        =>  dirname($file),
            'name'        =>  $directory,
            'version'     =>  '',
            'author'      =>  '',
            'description' =>  '',
            'class'       =>  '',
            'error'       =>  null,
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
     * disabled, deleted and put back - and so the row for `gateways/Stripe` is
     * keyed the same on every install.
     * @param string $type Subdirectory
     * @param string $directory Module Directory
     * @return string
     */
    private function uid(string $type, string $directory): string
    {
        return strtolower($type . '-' . preg_replace('/[^a-zA-Z0-9]+/', '-', $directory));
    }
}
