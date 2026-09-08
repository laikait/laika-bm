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
 * What went wrong, where, and to whom.
 *
 * ---------------------------------------------------------------------------
 * THE FINDING THIS TABLE EXISTS FOR: NO SHIPPED INSTALL HAS EVER WRITTEN A LOG
 * ---------------------------------------------------------------------------
 * `Laika\Core\Exceptions\Handler::log()` opens with `if (!DEBUG) return;` and
 * `bin/verify-stage.php` forces DEBUG to a literal `false` in every release. So
 * on every installation an operator has ever run, an exception is rendered and
 * then dropped on the floor. The `lf-logs/` files on a development checkout
 * exist only because DEBUG is true there, which is precisely the machine where
 * nobody needs them.
 *
 * An operator whose site 500s has, today, nothing whatsoever to look at.
 * Fixing `Handler` is framework-end and out of bounds, so LBM captures its own.
 *
 * ---------------------------------------------------------------------------
 * THE THREE RULES, AND THE SECOND ONE IS NOT WHAT IT LOOKED LIKE
 * ---------------------------------------------------------------------------
 * 1. WRITING MUST NEVER BE ABLE TO TAKE THE REQUEST DOWN. The thing being
 *    logged may BE the database. Every write is wrapped and falls back to the
 *    file log; a logger that turns a handled error into a fatal is worse than
 *    no logger, because it converts a page somebody could still use into a
 *    white screen.
 *
 * 2. NO SECRETS. The plan for this phase said "store getTraceAsString(), never
 *    the argument values" - and that is wrong, which was found by running it
 *    rather than reading it. `getTraceAsString()` RENDERS SCALAR ARGUMENTS,
 *    truncated to `zend.exception_string_param_max_len` (15 by default):
 *
 *        #0 /app/Auth.php(31): signIn('admin', 'Sup3rSecret-Pas...')
 *
 *    and `zend.exception_ignore_args` is Off in PHP's development ini, which is
 *    what a great many shared hosts run. Fifteen characters of a password, in a
 *    table any role with `settings.read` can page through.
 *
 *    So `trace` is REBUILT from `getTrace()` using file, line, class and
 *    function only. The `args` key is never read - not filtered, not
 *    truncated, never touched - because a redaction is a regex somebody
 *    eventually gets wrong, and not reading a value cannot be got wrong.
 *
 *    `url` holds the PATH only. A query string carries reset tokens, one-time
 *    links and whatever an integration puts in it.
 *
 *    The request body is never stored at all. There is no column for it.
 *
 * 3. IT MUST PRUNE. `lf-logs/` has no rotation and one day's file on this
 *    checkout is already 428 KB. A log table that only grows is a table an
 *    operator eventually truncates by hand, at which point they lose the entry
 *    they were looking for along with everything else.
 *
 * ---------------------------------------------------------------------------
 * WHY MODULE ERRORS ARE HERE AND PROVISIONING RESULTS ARE NOT
 * ---------------------------------------------------------------------------
 * `provisioning_logs` already exists, purpose-built, and Phase 32 finally gives
 * it a writer: service, action, request, response, result. That is the record
 * of WHAT A MODULE WAS ASKED TO DO. This table is the record of SOMETHING
 * GOING WRONG - which for a module means the exception it threw, with
 * `module_relid` pointing at the row that names it.
 *
 * A module call that fails writes both, and they answer different questions:
 * one is "what happened to this customer's service", the other is "why is this
 * module broken".
 *
 * A NEW table, so `up()` creates it on installations that already exist and no
 * migration is needed.
 */
class ErrorLogSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'error_logs';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->bigId('log_id');
            $t->uid('uid');

            // error    - something threw
            // warning  - something reported a failure without throwing, which is
            //            a different event: a module returning success:false is
            //            working correctly and delivering bad news
            // critical - a fatal, caught at shutdown, where the request did not
            //            finish at all
            $t->enum('level', ['error', 'warning', 'critical'])->default('error');

            // Where it happened, which decides who should look at it: `app` is
            // ours, `module` is somebody else's code, `cron` and `job` run with
            // nobody watching and are the two an operator never sees otherwise.
            $t->enum('source', ['app', 'module', 'cron', 'job'])->default('app');

            $t->unsignedBigInteger('module_relid')->nullable()->default(NULL)
                ->comment('modules -> module_id. Set when the failure came from a module');

            $t->text('message');
            $t->string('exception_class', 191)->nullable()->default(NULL);
            $t->string('file', 500)->nullable()->default(NULL);
            $t->unsignedInteger('line')->nullable()->default(NULL);

            // REBUILT from getTrace() without arguments - see rule 2 above.
            $t->longText('trace')->nullable()->default(NULL);

            // The path, never the query string.
            $t->string('url', 500)->nullable()->default(NULL);
            $t->string('method', 10)->nullable()->default(NULL);

            // Who was looking at it. Nullable both ways and often both null: a
            // cron run has neither, and an anonymous visitor has neither.
            $t->unsignedBigInteger('staff_relid')->nullable()->default(NULL)->comment('staffs -> sid');
            $t->unsignedBigInteger('client_relid')->nullable()->default(NULL)->comment('clients -> cid');

            $t->timestamp('log_created_at');

            // Indexes. The first three are the filters on the viewer; the last
            // is what the pruning sweep and the keyset pagination both read.
            $t->index('source');
            $t->index('level');
            $t->index('module_relid');
            $t->index('log_created_at');
        });
    }
}
