<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Model\Contract\SchemaAbstract;

/**
 * Which schema changes this database has already received.
 *
 * The ledger behind LBM\Contract\MigrationAbstract. Presence of a row IS the
 * definition of "do not run this again", which is why the table exists at all:
 * up() cannot tell whether a change has been applied, only whether a table is
 * there.
 *
 * A NEW table, and that is what makes the whole mechanism bootstrap with no
 * special case. createIfNotExists creates it on installations that already
 * exist, on the same pass that would have created any other new table, so by
 * the time the migration pass runs there is always somewhere to write. See
 * TicketFeedbackSchema for the same distinction deciding a different design.
 *
 * ------------------------------------------------------------------------
 * There is deliberately no `failed` state
 * ------------------------------------------------------------------------
 * A row recording a failure would either collide on the unique key when the
 * migration is retried, or - if the runner updated it instead - permanently
 * mark a migration as seen and stop it ever running again. Neither is what
 * anybody wants from a failure. So the table is append-only and records only
 * what is done; a failure is reported on the screen and in the activity log,
 * and the absence of a row is what makes the next migrate try again.
 *
 * ------------------------------------------------------------------------
 * Two column decisions worth the words
 * ------------------------------------------------------------------------
 * `migration_key` is 191 rather than the usual 255. A UNIQUE index on a
 * utf8mb4 varchar(255) is 1020 bytes and exceeds the 767-byte limit on older
 * InnoDB row formats, which fails at CREATE TABLE with "Specified key was too
 * long" on exactly the older MySQL an operator is most likely to be running.
 * Ids are around 40 characters, so 191 costs nothing.
 *
 * No `uid` column, and no seed(). A ledger row is a fact, not an entity:
 * nothing addresses one by URL, nothing lists them for a client, and there is
 * no lookup data to seed. Adding a uid would only invite an Action to be
 * written against a table no business code may touch.
 */
class MigrationSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'migrations';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->bigId('migration_id');
            $t->string('migration_key', 191)->comment('MigrationAbstract::id(), YYYYMMDD_HHMM_snake_slug');
            $t->enum('state', ['applied', 'baselined'])->default('applied')->comment('applied = run() was called, baselined = it was already present');
            $t->string('description')->nullable()->default(NULL);
            $t->string('product_version', 20)->nullable()->default(NULL)->comment('Version::CURRENT when it ran');
            $t->timestamp('ran_at');

            // Indexes
            $t->unique('migration_key');
            $t->index('ran_at');
        });
    }
}
