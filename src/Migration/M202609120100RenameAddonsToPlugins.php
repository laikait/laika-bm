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

namespace LBM\Migration;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Throwable;
use RuntimeException;
use Laika\Model\Model;
use LBM\Contract\MigrationAbstract;
use LBM\Module\ModuleManager;

/**
 * The sixth real migration, and the first that touches disk: Phase 39 renames
 * the `addons` kind of module `plugins`.
 *
 * ---------------------------------------------------------------------------
 * WHY IT HAS TO MOVE ANYTHING AT ALL
 * ---------------------------------------------------------------------------
 * Unzipping a release over an older tree adds `modules/plugins` and deletes
 * nothing, so an upgraded install still has `modules/addons` with the
 * operator's modules in it, and rows in `modules` under uids like
 * `addons-backup`. After this phase the loader never looks in `modules/addons`
 * - so left alone, every addon on such an install stops loading on the first
 * request after the update and the modules screen stops listing it. Nothing
 * errors. It is Phase 31's failure one kind along, and this class exists for
 * the same reason that one does.
 *
 * ---------------------------------------------------------------------------
 * WHAT IT DOES, IN ORDER - EVERY STEP SAFE TO REPEAT
 * ---------------------------------------------------------------------------
 *   1. Moves each entry of modules/addons into modules/plugins when nothing of
 *      that name is there yet. When modules/plugins does not exist at all the
 *      whole directory is renamed in one go.
 *   2. Renames each `addons-x` row to `plugins-x`, IN PLACE, so its id, its
 *      switch and its history survive - `error_logs.module_relid` points at
 *      that id, and a delete-and-insert would orphan every error the module
 *      ever logged.
 *   3. Rewrites the loader's cache. Phase 31's migration left it alone because
 *      the uids did not change; here they all do, and GlobalPipeline rebuilds
 *      the cache only when it is MISSING - so a cache naming `addons-x` would
 *      keep every moved module dormant until something else happened to clear
 *      it.
 *   4. Removes modules/addons once nothing is left in it but the guard files
 *      modules/plugins already has.
 *
 * `run()` is not wrapped in a transaction and could not usefully be: a
 * directory move cannot be rolled back with a database. So each step is written
 * to be repeated instead, and a run that stops part-way is simply run again.
 *
 * ---------------------------------------------------------------------------
 * A CLASH IS NAMED, AND NOTHING IS OVERWRITTEN
 * ---------------------------------------------------------------------------
 * `modules/addons/X` beside an existing `modules/plugins/X` is two directories
 * with one name, and only the operator knows which is the one they want. Both
 * are left exactly as they are, everything else is moved, and `run()` THROWS at
 * the end naming the clash. That is deliberate: a migration that throws records
 * nothing and is attempted again on the next update, so the clash is reported
 * on the update screen every time until it is resolved, and the next run then
 * finishes the job. Until then `modules/addons/X` does not load, and the
 * message says so.
 *
 * ---------------------------------------------------------------------------
 * WHEN A plugins-x ROW ALREADY EXISTS, IT STANDS
 * ---------------------------------------------------------------------------
 * That happens when a directory reached modules/plugins some other way - moved
 * by hand before the update, or the plugins half of a clash the operator
 * resolved by deleting the addons copy - and the modules screen has listed it
 * since. The obvious move is to merge the two rows' switches, and it is wrong:
 * in the second case the two rows describe DIFFERENT modules that happen to
 * share a directory name, and merging would switch the plugins one's code on
 * because the addons one used to be on. Nothing dropped on disk runs until an
 * operator says so, and the row describing what is actually there is the one an
 * operator has seen. The old row is deleted.
 *
 * With no plugins-x row, the old one is renamed even when its directory is not
 * on disk - so a module put back under plugins gets its history back, the rule
 * Phase 31 settled for a module taken away and returned.
 *
 * ---------------------------------------------------------------------------
 * NO MANIFEST IS READ
 * ---------------------------------------------------------------------------
 * Requiring somebody else's PHP in the middle of a migration would let a broken
 * module stop the update, which is the one place a failure has the least good
 * outcome - Phase 31's rule. Directories are moved by name, and the loader's
 * cache is projected from the table and the disk alone: the same list
 * `Action\Module::enabledUids()` produces, without executing anything.
 *
 * Model methods, not raw SQL: this moves rows and changes no shape, so the
 * `src/Migration` exception for DDL does not apply.
 */
class M202609120100RenameAddonsToPlugins extends MigrationAbstract
{
    /** @var string Ledger Key. Written once, never edited */
    protected string $id = '20260912_0100_rename_addons_to_plugins';

    /** @var string What This Does */
    protected string $description
        = 'Move modules/addons into modules/plugins, and rename their rows so a module that was on stays on.';

    /** @var string The Kind That Was Renamed */
    private const FROM = 'addons';

    /** @var string What It Is Called Now */
    private const TO = 'plugins';

    /**
     * Whether This Install Needs It
     *
     * True where modules/addons is still on disk or a row still names the old
     * kind. A fresh install has neither - the release ships modules/plugins, and
     * bin/verify-stage.php refuses one carrying modules/addons - so this answers
     * false and is recorded `baselined`.
     * @return bool
     */
    public function applies(): bool
    {
        if (is_dir($this->from())) {
            return true;
        }

        return $this->hasTable('modules')
            && $this->table()->where(['module_type' => self::FROM])->exists();
    }

    /**
     * Move, Rename, Re-project, Tidy - And Name What Could Not Be Moved
     * @return void
     */
    public function run(): void
    {
        $problems = $this->moveEntries();

        $this->renameRows();
        $this->writeLoaderCache();
        $this->removeEmptied();

        if ($problems !== []) {
            throw new RuntimeException(implode(' ', $problems));
        }
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Step 1: Move Everything That Has Somewhere To Go
     *
     * An entry whose twin in modules/plugins is a byte-identical FILE is not a
     * clash - it is the guard index.php or .gitkeep both releases ship - and is
     * left for removeEmptied(). Anything else already there is.
     * @return string[] What could not be moved, in the operator's words
     */
    private function moveEntries(): array
    {
        $from = $this->from();
        $to = $this->to();

        if (!is_dir($from)) {
            return [];
        }

        if (!is_dir($to)) {
            $error = $this->move($from, $to);

            return $error === '' ? [] : ["modules/addons could not be renamed modules/plugins: {$error}"];
        }

        $problems = [];

        foreach ($this->entries($from) as $entry) {
            $source = $from . '/' . $entry;
            $target = $to . '/' . $entry;

            if (!file_exists($target)) {
                $error = $this->move($source, $target);

                if ($error !== '') {
                    $problems[] = "modules/addons/{$entry} could not be moved to modules/plugins/{$entry}: {$error}";
                }

                continue;
            }

            if ($this->sameFile($source, $target)) {
                continue;
            }

            $problems[] = "modules/addons/{$entry} was not moved, because modules/plugins/{$entry} already exists."
                . ' Nothing was overwritten. Keep one of the two and run the update again;'
                . " until then modules/addons/{$entry} does not load.";
        }

        return $problems;
    }

    /**
     * Step 2: Carry Every Row To The New Kind
     * @return void
     */
    private function renameRows(): void
    {
        foreach ($this->table()->where(['module_type' => self::FROM])->get() as $row) {
            $id = (int) $row['module_id'];
            $directory = (string) $row['directory'];

            if ($directory === '') {
                $directory = substr((string) $row['uid'], strlen(self::FROM) + 1);
            }

            // Still in modules/addons: one half of a clash. Its row describes a
            // directory that has not moved, and is dealt with when it does.
            if ($directory !== '' && is_dir($this->from() . '/' . $directory)) {
                continue;
            }

            $uid = ModuleManager::uid(self::TO, $directory);

            // A row for what is actually in modules/plugins stands - see the
            // class docblock for why the switches are not merged.
            if ($this->table()->where(['uid' => $uid])->exists()) {
                $this->table()->where(['module_id' => $id])->delete();

                continue;
            }

            $this->table()->where(['module_id' => $id])->update([
                'uid'                =>  $uid,
                'module_type'        =>  self::TO,
                'module_updated_at'  =>  date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * Step 3: The Loader's Cache, From The Table And The Disk
     *
     * Exactly `Action\Module::enabledUids()`: switched on, of a kind the loader
     * knows, and with a manifest on disk where the row says it is. A cache that
     * cannot be written fails the migration, so it is attempted again - a stale
     * one keeps every moved module dormant.
     * @return void
     */
    private function writeLoaderCache(): void
    {
        $enabled = [];

        foreach ($this->table()->where(['is_enabled' => 'yes'])->get() as $row) {
            $type = (string) $row['module_type'];
            $directory = (string) $row['directory'];

            if (!in_array($type, ModuleManager::TYPES, true) || $directory === '') {
                continue;
            }

            $uid = ModuleManager::uid($type, $directory);

            if ((string) $row['uid'] === $uid
                && is_file(ModuleManager::path() . '/' . $type . '/' . $directory . '/' . ModuleManager::MANIFEST)) {
                $enabled[] = $uid;
            }
        }

        if (!ModuleManager::writeCache($enabled)) {
            throw new RuntimeException(
                'The module loader cache could not be written to ' . ModuleManager::CACHE
                . ', so the moved modules would not load. Check that lf-storage/cache is writable and run the update again.'
            );
        }
    }

    /**
     * Step 4: Remove modules/addons Once Nothing Real Is Left In It
     *
     * Only when every entry is a guard file modules/plugins already has, byte
     * for byte. Anything else - the addons half of a clash, a file an operator
     * put there - keeps the directory, guard files and all.
     * @return void
     */
    private function removeEmptied(): void
    {
        $from = $this->from();

        if (!is_dir($from)) {
            return;
        }

        $duplicates = [];

        foreach ($this->entries($from) as $entry) {
            if (!$this->sameFile($from . '/' . $entry, $this->to() . '/' . $entry)) {
                return;
            }

            $duplicates[] = $from . '/' . $entry;
        }

        foreach ($duplicates as $file) {
            unlink($file);
        }

        rmdir($from);
    }

    /**
     * Rename, And Say Why Not
     *
     * try/catch rather than `@`: once lf-boot/app.php is loaded the error
     * handler promotes the warning to an ErrorException, and `@` does not stop
     * it.
     * @param string $source Path
     * @param string $target Path
     * @return string '' on success, otherwise the reason
     */
    private function move(string $source, string $target): string
    {
        try {
            return rename($source, $target) ? '' : 'the filesystem refused.';
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * Whether Two Paths Are The Same File, Byte For Byte
     * @param string $one Path
     * @param string $two Path
     * @return bool
     */
    private function sameFile(string $one, string $two): bool
    {
        return is_file($one) && is_file($two) && hash_file('sha256', $one) === hash_file('sha256', $two);
    }

    /**
     * A Directory's Entries, Without The Dots
     * @param string $directory Path
     * @return string[]
     */
    private function entries(string $directory): array
    {
        return array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
    }

    /** @return string Where The Old Kind Lived */
    private function from(): string
    {
        return ModuleManager::path() . '/' . self::FROM;
    }

    /** @return string Where It Lives Now */
    private function to(): string
    {
        return ModuleManager::path() . '/' . self::TO;
    }

    /**
     * The Modules Table, On This Migration's Own Connection
     * @return Model
     */
    private function table(): Model
    {
        return (new Model($this->connection()))->table('modules');
    }
}
