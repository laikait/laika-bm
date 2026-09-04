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

use RuntimeException;
use LBM\Contract\MigrationAbstract;

/**
 * The first real migration in the product.
 *
 * Phase 21 built this mechanism and shipped `src/Migration/` empty, because the
 * change it was written for evaporated under checking and inventing one to
 * exercise the runner would have been worse than shipping it unexercised. This
 * is the change that finally arrived: Phase 24 needs to know when a service is
 * due to end.
 *
 * ---------------------------------------------------------------------------
 * WHY A COLUMN AND NOT `module_data`
 * ---------------------------------------------------------------------------
 * `client_services.module_data` is a serialize column and there is already
 * lifecycle state in it, so putting a scheduled cancellation there would have
 * needed no migration at all. It would also have been unqueryable: cron has to
 * ask "which services are due to end today", and answering that from a blob
 * means reading every service in the database and unserialising it in PHP on
 * every tick. A date the scheduler runs on is an indexed column or it is a
 * design that falls over on the first install with real numbers in it.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS IS A MIGRATION AT ALL
 * ---------------------------------------------------------------------------
 * `SchemaAbstract::up()` only ever calls `createIfNotExists`. A new TABLE
 * reaches an existing install on the next migrate; a new COLUMN never does. So
 * adding two columns to a table that has shipped since Phase 0 is precisely the
 * case this directory exists for, and the case an operator would otherwise meet
 * as "the update said success and the feature does not work".
 */
class M202609050100AddServiceCancellation extends MigrationAbstract
{
    /** @var string Ledger Key. Written once, never edited */
    protected string $id = '20260905_0100_add_service_cancellation';

    /** @var string What This Does */
    protected string $description
        = 'Add client_services.cancel_at and cancel_reason, so a cancellation can be scheduled.';

    /**
     * Whether This Install Needs It
     *
     * Probes for the column rather than for a version. A fresh install created
     * the table from ClientServiceSchema, which carries both columns already, so
     * this answers false there and is recorded `baselined` without run() ever
     * being called. An install that predates Phase 24 answers true exactly once.
     *
     * Only `cancel_at` is probed: the two columns are added by one migration, so
     * either both are present or neither is, and probing both would suggest a
     * partial state that this class cannot produce.
     * @return bool
     */
    public function applies(): bool
    {
        return $this->hasTable('client_services')
            && !$this->hasColumn('client_services', 'cancel_at');
    }

    /**
     * Add The Columns
     *
     * Nullable with no default in both dialects, because NULL is the meaningful
     * value here: it means "no cancellation is scheduled", which is true of
     * every row that already exists.
     *
     * The index matters as much as the column. Without it the daily sweep is a
     * full scan of every service an operator has ever sold.
     * @return void
     * @throws RuntimeException
     */
    public function run(): void
    {
        $on = $this->schema();

        // statement() returns false on success - it is (bool) PDO::exec() and
        // DDL returns 0 - so nothing here branches on the return value. A real
        // failure arrives as a PDOException and stops the run.
        match ($this->driver()) {
            'mysql' => $this->mysql(),
            'pgsql' => $this->pgsql(),
            default => throw new RuntimeException(
                'Unsupported driver for ' . $this->id() . ': ' . $this->driver()
            ),
        };

        unset($on);
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * MySQL And MariaDB
     *
     * One ALTER per column rather than one combined statement: MariaDB before
     * 10.3 will not take multiple ADD COLUMN clauses with IF NOT EXISTS, and a
     * migration that half-applies is worse than one that takes two statements.
     * @return void
     */
    private function mysql(): void
    {
        $on = $this->schema();

        $on->statement(
            'ALTER TABLE `client_services` ADD COLUMN `cancel_at` TIMESTAMP NULL DEFAULT NULL'
        );

        $on->statement(
            'ALTER TABLE `client_services` ADD COLUMN `cancel_reason` VARCHAR(255) NULL DEFAULT NULL'
        );

        $on->statement('CREATE INDEX `client_services_cancel_at_index` ON `client_services` (`cancel_at`)');
    }

    /**
     * PostgreSQL
     *
     * ALTER COLUMN ... TYPE is the trap on this engine, but ADD COLUMN is not:
     * the nullability and default are stated here exactly as in MySQL. The
     * difference is the quoting and that TIMESTAMP means something slightly
     * different - which does not matter for a column only ever compared against
     * a value this application wrote.
     * @return void
     */
    private function pgsql(): void
    {
        $on = $this->schema();

        $on->statement(
            'ALTER TABLE "client_services" ADD COLUMN "cancel_at" TIMESTAMP NULL DEFAULT NULL'
        );

        $on->statement(
            'ALTER TABLE "client_services" ADD COLUMN "cancel_reason" VARCHAR(255) NULL DEFAULT NULL'
        );

        $on->statement('CREATE INDEX "client_services_cancel_at_index" ON "client_services" ("cancel_at")');
    }
}
