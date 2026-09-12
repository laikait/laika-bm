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
use RuntimeException;
use Laika\Model\Model;
use LBM\Model\ModuleModel;
use LBM\Model\ModuleSettingModel;
use LBM\Module\ModuleManager;
use LBM\Support\ModuleSettings;

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

    /**
     * @var string[] The Kinds Configured On a Page Of Their Own - Phase 40
     *
     * The rest already have a screen that IS their configuration: a gateway's
     * row on the gateways screen, a registrar's form, and a server module's
     * fields on each product. These three had nowhere.
     */
    public const CONFIGURE_TYPES = ['lookup', 'fraud', 'plugins'];

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

    ################################################################################
    /*======================= SETTINGS - PHASE 40 ================================*/
    ################################################################################
    //
    // For lookup, fraud and plugin modules, whose settings live in
    // `module_settings`, one row per declared field plus `mode`. What may be
    // stored, and how, is LBM\Support\ModuleSettings' business; this only
    // decides where it goes.

    /**
     * The Kinds Configured On Their Own Page
     *
     * A method rather than the constant, because a relay facade forwards
     * method calls and not constants.
     * @return string[]
     */
    public function configureTypes(): array
    {
        return self::CONFIGURE_TYPES;
    }

    /**
     * The Class a Module Declares Its Settings In, If It Can Be Asked
     *
     * Only a module that is LOADED can be: its classes are not autoloadable
     * otherwise, so a module switched off has nothing to draw.
     * @param string $uid Module Uid
     * @return ?string
     */
    public function configurableClass(string $uid): ?string
    {
        $module = $this->find($uid);

        if ($module === null || !ModuleManager::isLoaded((string) $module['uid'])) {
            return null;
        }

        $class = ModuleSettings::loadedClass((string) $module['type'], (string) $module['directory']);

        return $class !== null && ModuleSettings::configurable($class) ? $class : null;
    }

    /**
     * What Is Stored For a Module, Mode Aside
     * @param string $uid Module Uid
     * @return array<string,string>
     */
    public function storedSettings(string $uid): array
    {
        $stored = [];

        foreach ($this->settingRows($uid) as $key => $row) {
            if ($key !== 'mode' && is_string($row['setting_value'] ?? null)) {
                $stored[$key] = (string) $row['setting_value'];
            }
        }

        return $stored;
    }

    /**
     * Live Or Test
     * @param string $uid Module Uid
     * @return string
     */
    public function modeOf(string $uid): string
    {
        return ModuleSettings::mode($this->settingRows($uid)['mode']['setting_value'] ?? null);
    }

    /**
     * What a Module's Driver Is Constructed With
     *
     * Reads the settings table and nothing else - no manifest - because
     * LooksUpDomains asks on every public search.
     * @param string $uid Module Uid
     * @param string $class The Class Being Built
     * @return array<string,string>
     */
    public function settingsFor(string $uid, string $class): array
    {
        $settings = ModuleSettings::open(ModuleSettings::fields($class), $this->storedSettings($uid));
        $settings['mode'] = $this->modeOf($uid);

        return $settings;
    }

    /**
     * What The Configure Page May Show
     * @param string $uid Module Uid
     * @return array{fields: array, mode: string}
     */
    public function formFor(string $uid): array
    {
        $class = $this->configurableClass($uid);

        return [
            'fields' =>  $class === null
                ? []
                : ModuleSettings::forForm(ModuleSettings::fields($class), $this->storedSettings($uid)),
            'mode'   =>  $this->modeOf($uid),
        ];
    }

    /**
     * Save a Module's Settings
     * @param string $uid Module Uid
     * @param array $input The Form - `settings[...]`, `settings_clear[]`, `mode`
     * @return void
     * @throws RuntimeException
     */
    public function configure(string $uid, array $input): void
    {
        $module = $this->find($uid);

        if ($module === null) {
            throw new RuntimeException('That module is not installed.');
        }

        $class = $this->configurableClass($uid);

        if ($class === null) {
            throw new RuntimeException(
                'This module is switched off, or declares no settings, so there is nothing to save. Switch it on under Modules first.'
            );
        }

        $fields = ModuleSettings::fields($class);

        $values = ModuleSettings::merge(
            $fields,
            is_array($input['settings'] ?? null) ? $input['settings'] : [],
            $this->storedSettings($uid),
            is_array($input['settings_clear'] ?? null) ? $input['settings_clear'] : []
        );

        $values['mode'] = ModuleSettings::mode($input['mode'] ?? $this->modeOf($uid));

        $this->writeSettings((int) $module['module_id'], $values, $fields);
    }

    /**
     * Try a Module's Saved Settings Against Its Provider
     *
     * Builds the driver the same way its kind's builder does - its settings,
     * opened, and its mode - and asks it. Only Configurable matters here; the
     * kind's contract is its builder's check.
     * @param string $uid Module Uid
     * @return array{success: bool, message: string}
     */
    public function testConnection(string $uid): array
    {
        $class = $this->configurableClass($uid);

        if ($class === null) {
            return [
                'success' =>  false,
                'message' =>  'This module is switched off, or declares no settings, so it has no connection test.',
            ];
        }

        try {
            $driver = new $class($this->settingsFor($uid, $class));
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'The module could not be constructed: ' . $e->getMessage()];
        }

        return ModuleSettings::test($driver);
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
     * A Module's module_settings Rows, Keyed By setting_key
     *
     * By uid straight off the table - never through find(), which reads every
     * manifest on disk. Empty when the table is not there yet: a release
     * deployed and not yet migrated must not take a public search down.
     * @param string $uid Module Uid
     * @return array<string,array>
     */
    private function settingRows(string $uid): array
    {
        try {
            $module = $this->model()->where(['uid' => $uid])->first();

            if ($module === null) {
                return [];
            }

            $rows = [];

            foreach ((new ModuleSettingModel())->where(['module_relid' => (int) $module['module_id']])->get() as $row) {
                $rows[(string) $row['setting_key']] = $row;
            }

            return $rows;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Write a Module's Settings, Whole
     *
     * One transaction, so a save that fails part-way leaves the module on the
     * settings it had rather than half of the new ones. A key no longer in the
     * set - a cleared field - is deleted.
     * @param int $moduleId Module ID
     * @param array<string,string> $values Setting Key => Stored Value
     * @param array $fields From ModuleSettings::fields()
     * @return void
     */
    private function writeSettings(int $moduleId, array $values, array $fields): void
    {
        (new ModuleSettingModel())->transaction(function () use ($moduleId, $values, $fields): void {
            $existing = [];

            foreach ((new ModuleSettingModel())->where(['module_relid' => $moduleId])->get() as $row) {
                $existing[(string) $row['setting_key']] = (int) $row['ms_id'];
            }

            $now = $this->now();

            foreach ($values as $key => $value) {
                $data = [
                    'setting_value' =>  (string) $value,
                    'is_secret'     =>  !empty($fields[$key]['secret']) ? 'yes' : 'no',
                    'ms_updated_at' =>  $now,
                ];

                if (isset($existing[$key])) {
                    (new ModuleSettingModel())->where(['ms_id' => $existing[$key]])->update($data);
                    unset($existing[$key]);

                    continue;
                }

                (new ModuleSettingModel())->insert($data + [
                    'module_relid'  =>  $moduleId,
                    'setting_key'   =>  (string) $key,
                    'ms_created_at' =>  $now,
                ]);
            }

            foreach ($existing as $id) {
                (new ModuleSettingModel())->where(['ms_id' => $id])->delete();
            }
        });
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
