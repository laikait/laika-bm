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

use Laika\Model\Model;
use LBM\Action\Module as ModuleAction;
use LBM\Contract\MigrationAbstract;
use LBM\Module\ModuleManager;

/**
 * The fourth real migration: Phase 31 moves the module switch out of `options`
 * and into the `modules` table.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS A MIGRATION AND THE TABLE ITSELF IS NOT
 * ---------------------------------------------------------------------------
 * `modules` is a NEW table, so `up()` creates it on every installation that
 * already exists and no migration is needed for the SHAPE. What needs one is
 * the DATA: an installation that has been running for a year holds a
 * `module_enabled_<uid>` row for every module it has ever met, and after this
 * phase nothing in the product will ever read those rows again.
 *
 * Left alone they would be far worse than clutter. Every module on such an
 * install would read as switched off on the first request after the update - so
 * a payment gateway stops existing, a provisioning module stops provisioning,
 * and the Modules screen shows every switch in the OFF position with nothing to
 * explain it. That is what this class exists to prevent, which is why the
 * carry-across is not optional tidying.
 *
 * ---------------------------------------------------------------------------
 * IT RUNS ONLY WHERE THERE IS SOMETHING TO CARRY
 * ---------------------------------------------------------------------------
 * `applies()` asks whether any such option row exists. A fresh install has
 * none, so this records `baselined` and `run()` is never called - the design
 * Phase 21 built, and the reason no version arithmetic appears anywhere.
 *
 * ---------------------------------------------------------------------------
 * MODEL METHODS, NOT RAW SQL
 * ---------------------------------------------------------------------------
 * `src/Migration` is the one place in this product where raw DDL is permitted,
 * and `bin/verify-stage.php` enforces it. That permission exists for DDL, which
 * has no model-layer expression. This migration moves ROWS, which does - so it
 * uses the model layer like everything else, and the exception stays as narrow
 * as it was written to be.
 *
 * ---------------------------------------------------------------------------
 * THE CASE OF THE UID, WHICH IS NOT COSMETIC
 * ---------------------------------------------------------------------------
 * `Action\Module::uid()` lowercases, so `gateways/Stripe` is `gateways-stripe`.
 * Some option rows on this development install do NOT follow that -
 * `module_enabled_gateways-RefundProbe` - because they were written by harnesses
 * that built the key by hand rather than through `toggle()`.
 *
 * MySQL's default collation is case-insensitive, so such a row answered
 * `option_bool('module_enabled_gateways-refundprobe')` perfectly well and
 * nobody ever noticed. PostgreSQL's is not, and `modules.uid` is UNIQUE - so
 * carrying two spellings across would either collide or, worse, produce two
 * rows for one directory on one engine and one row on the other. Every key is
 * lowercased on the way in.
 *
 * ---------------------------------------------------------------------------
 * A ROW NAMING A MODULE THAT IS NOT THERE IS DROPPED
 * ---------------------------------------------------------------------------
 * A decision rather than an omission. The option key is the only trace a
 * hand-deleted module leaves, and this install alone has two of them, left by
 * harnesses whose fixtures were removed.
 *
 * Writing those into `modules` would put rows in the table for directories that
 * do not exist - which `Action\Module::all()` would never list, because disk
 * decides what exists. A row nothing can ever show is how a table starts
 * collecting things nobody dares delete.
 *
 * The old rows go either way. Keeping them would leave two places claiming to
 * know whether a module is switched on, and the first person to find the stale
 * one would believe it.
 *
 * ---------------------------------------------------------------------------
 * THE LOADER'S CACHE IS NOT TOUCHED, DELIBERATELY
 * ---------------------------------------------------------------------------
 * It already holds the right list: it was projected from these very option
 * rows, through the same on-disk filter this migration applies, so rewriting it
 * from the table would produce the same file. Leaving it alone means one less
 * thing that can fail half-way through a migration, and `GlobalPipeline`
 * rebuilds it from the table if it ever goes missing.
 */
class M202609080100MoveModuleStateIntoTable extends MigrationAbstract
{
    /** @var string Ledger Key. Written once, never edited */
    protected string $id = '20260908_0100_move_module_state_into_table';

    /** @var string What This Does */
    protected string $description
        = 'Carry module_enabled_* option rows into the modules table, and remove them.';

    /**
     * Whether This Install Needs It
     *
     * True only where a `module_enabled_*` row is actually present. A fresh
     * install has none - nothing has ever written one - so this answers false
     * and is recorded `baselined` without `run()` being called at all.
     * @return bool
     */
    public function applies(): bool
    {
        if (!$this->hasTable('options') || !$this->hasTable('modules')) {
            return false;
        }

        return $this->legacyRows() !== [];
    }

    /**
     * Carry The Switch Across
     * @return void
     */
    public function run(): void
    {
        $onDisk = $this->onDisk();
        $existing = $this->existingUids();
        $now = date('Y-m-d H:i:s');

        foreach ($this->legacyRows() as $row) {
            $key = (string) $row['op_key'];

            // Lowercased on the way in - see the class docblock. Two spellings
            // of one module must not become two rows under a UNIQUE key.
            $uid = strtolower(substr($key, strlen(ModuleAction::OPTION)));

            // option_bool() matches only the literal string 'true'; 1, yes and
            // on all read back false. So the same test is applied here rather
            // than a looser one, or a module somebody had switched off with a
            // `0` in the column would come back on.
            $enabled = strtolower(trim((string) $row['op_value'])) === 'true';

            if ($uid !== '' && isset($onDisk[$uid]) && !isset($existing[$uid])) {
                $this->table('modules')->insert([
                    'uid'                =>  $uid,
                    'module_type'        =>  $onDisk[$uid]['type'],
                    'directory'          =>  $onDisk[$uid]['directory'],

                    // The directory name is the documented fallback when a
                    // manifest declares no name, and it is what goes in here:
                    // reading the manifest would mean executing a module's
                    // code in the middle of a migration, which is the one place
                    // a failure has the least good outcome. The next load of
                    // the modules screen reconciles the real metadata in.
                    'module_name'        =>  $onDisk[$uid]['directory'],
                    'is_enabled'         =>  $enabled ? 'yes' : 'no',
                    'installed_at'       =>  $now,
                    'module_created_at'  =>  $now,
                    'module_updated_at'  =>  $now,
                ]);

                $existing[$uid] = true;
            }

            $this->table('options')->where(['op_key' => $key])->delete();
        }
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * The Option Rows This Migration Exists To Move
     * @return array<int,array>
     */
    private function legacyRows(): array
    {
        return $this->table('options')
            ->where(['op_key' => ModuleAction::OPTION . '%'], 'LIKE')
            ->get();
    }

    /**
     * Which Modules Are Actually On Disk, Keyed By Uid
     *
     * Directory names only - no manifest is read. What is wanted here is
     * whether the directory exists, and requiring somebody else's PHP during a
     * migration would let a broken module stop the update.
     * @return array<string,array{type:string,directory:string}>
     */
    private function onDisk(): array
    {
        $found = [];

        foreach (ModuleManager::TYPES as $type) {
            $base = ModuleManager::path() . '/' . $type;

            if (!is_dir($base)) {
                continue;
            }

            foreach (glob($base . '/*/' . ModuleManager::MANIFEST) ?: [] as $file) {
                $directory = basename(dirname($file));

                $found[ModuleManager::uid($type, $directory)] = [
                    'type'      =>  $type,
                    'directory' =>  $directory,
                ];
            }
        }

        return $found;
    }

    /**
     * Uids The Table Already Holds
     *
     * Re-running this migration must not insert a second row for the same
     * module. It cannot normally happen - a migration that succeeds is recorded
     * and never runs again - but one that throws part-way through records
     * NOTHING and is retried next time, which is exactly the design, and this
     * is what makes the retry safe.
     * @return array<string,bool>
     */
    private function existingUids(): array
    {
        $uids = [];

        foreach ($this->table('modules')->get() as $row) {
            $uids[(string) $row['uid']] = true;
        }

        return $uids;
    }

    /**
     * A Model On This Migration's Own Connection
     * @param string $table Table Name
     * @return Model
     */
    private function table(string $table): Model
    {
        return (new Model($this->connection()))->table($table);
    }
}
