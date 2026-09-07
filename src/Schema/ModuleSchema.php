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
 * What is installed in the app root's `modules/` directory.
 *
 * Until Phase 31 there was no such table, and `LBM\Action\Module::model()` said
 * so in as many words: it returned a Model pointed at `options`, because the
 * only thing being stored was one boolean per module under a
 * `module_enabled_<uid>` key. Everything else - name, version, author, what a
 * module provides - was re-read off disk on every request and could not be
 * asked a question about.
 *
 * ---------------------------------------------------------------------------
 * THIS TABLE IS NOT WHAT THE LOADER READS, AND CANNOT BE
 * ---------------------------------------------------------------------------
 * `ModuleManager::discover()` runs inside composer's `files` autoload, because
 * the dispatcher requires every route file before it matches a route - so a
 * module's routes must be registered earlier than that, and there is nowhere
 * earlier. At that moment there is no database connection, no `option()`, and
 * on a fresh checkout no schema at all.
 *
 * So the arrangement Phase 20.1 built stays exactly as it was: this table is
 * the record of truth, and `lf-storage/cache/lbm-modules.php` is a small
 * generated projection of the one column the loader needs. What changes is
 * only which side of that pair the truth lives in - scattered option rows
 * become rows here.
 *
 * ---------------------------------------------------------------------------
 * DISK STILL DECIDES WHAT EXISTS
 * ---------------------------------------------------------------------------
 * A row here is a record ABOUT a directory, never a substitute for one. A
 * module somebody deletes by hand stops appearing the moment it is gone, and
 * its row is left behind rather than acted on - which is the same rule
 * `Action\Module::enabledUids()` has held since 20.1, and it matters because
 * the row is otherwise the only trace such a module leaves.
 *
 * Keeping the row rather than deleting it is deliberate: a module disabled,
 * deleted and put back should come back switched off with its history intact,
 * and a directory that is missing this morning is very often a deploy that has
 * not finished rather than a decision anybody made.
 *
 * ---------------------------------------------------------------------------
 * WHAT IS DELIBERATELY NOT A COLUMN
 * ---------------------------------------------------------------------------
 * `settings` and `last_error` were both in the plan for this table and neither
 * is here, for the same reason: nothing would write them.
 *
 *   - A gateway's settings live on `payment_gateways.settings`, a server's on
 *     `servers`, a registrar's on `domain_registrars.credentials`. A second
 *     home for the same thing is the arrangement where two screens disagree
 *     and no one can say which is right.
 *   - A load error is per-request and transient - `ModuleManager::failed()`
 *     already answers it for the request that is rendering - so a column would
 *     mean a write on every page load. Phase 32 gives errors a table of their
 *     own with a `module_relid` pointing here, which is where that belongs.
 *
 * This project has now found five pairs of Phase 0 tables with a schema, a
 * model, seeded rows and no writer anywhere. Adding a sixth on purpose would
 * be a poor way to learn from that.
 *
 * A NEW table, so `up()` creates it on installations that already exist and no
 * migration is needed for the schema. Carrying the old `module_enabled_*`
 * option rows across IS a migration, because it changes data rather than shape.
 */
class ModuleSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'modules';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->bigId('module_id');

            // 191, not 255: the utf8mb4 limit for a column inside a UNIQUE key
            // on older MySQL row formats - the same reason GatewayCallbackSchema
            // caps event_ref and MigrationSchema caps migration_key.
            //
            // The uid is DERIVED from where the module sits (`gateways-stripe`),
            // never chosen, so it is the same string on every installation and
            // survives a module being disabled, deleted and put back.
            $t->string('uid', 191)->comment('type-directory, lowercased. gateways/Stripe -> gateways-stripe');

            $t->string('module_type', 50)->comment('One of ModuleManager::TYPES');
            $t->string('directory', 191)->comment('The directory name, case as it is on disk');

            // From the manifest. Refreshed whenever the listing reconciles, so
            // an operator who upgrades a module in place sees the new version
            // without anything else having to happen.
            $t->string('module_name')->comment('The manifest name, or the directory when it declares none');
            $t->string('version', 50)->nullable()->default(NULL);
            $t->string('author', 191)->nullable()->default(NULL);
            $t->text('description')->nullable()->default(NULL);
            $t->string('module_class', 255)->nullable()->default(NULL)
                ->comment('The class implementing this kind\'s contract, as the manifest declares it');

            // THE OPERATOR'S SWITCH, and the only column here that is not a
            // copy of something on disk. This is what replaces the
            // `module_enabled_<uid>` option rows.
            $t->enum('is_enabled', ['yes', 'no'])->default('no');

            // When we first saw it, which the options table could not hold at
            // all - an option row has no history and no date on it.
            $t->timestamp('installed_at')->nullable()->default(NULL);
            $t->timestamps('module_created_at', 'module_updated_at');

            // Indexes
            $t->unique('uid', 'modules_uid_unique');
            $t->index('module_type');
            $t->index('is_enabled');
        });
    }
}
