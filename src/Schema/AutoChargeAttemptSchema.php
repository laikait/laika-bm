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
 * Every time a saved card was charged for an invoice without the customer there
 * - Phase 48.
 *
 * Two jobs, like gateway_callbacks, and again they turn out to be one.
 *
 * **It is the claim.** `UNIQUE (invoice_relid, claim_day)` is what makes "the
 * scheduled charge tries an invoice at most once a day" true. Cron writes the
 * row BEFORE it asks the provider, and a second run - or a second cron process
 * overlapping the first - fails to insert and moves on. Reading "was it tried
 * today" and then charging is a race two processes both win, and the loser of
 * that race is a customer charged twice.
 *
 * `claim_day` is NULL for a charge a member of staff started. Both engines allow
 * any number of NULLs in a unique key, so staff are never blocked by the daily
 * claim - pressing the button is a decision, not a schedule - and their attempts
 * are still recorded, and still count as evidence.
 *
 * **It is the history.** An invoice whose card was declined three mornings
 * running says so on its screen, and the scheduled charge stops trying.
 *
 * A NEW table, so up() creates it on every install on the next migrate; no
 * migration is needed.
 */
class AutoChargeAttemptSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'auto_charge_attempts';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->bigId('attempt_id');
            $t->uid('uid');
            $t->unsignedBigInteger('invoice_relid')->comment('invoices -> invoice_id');
            $t->unsignedBigInteger('pm_relid')->nullable()->default(NULL)->comment('payment_methods -> pm_id');
            $t->enum('source', ['cron', 'staff'])->default('cron');
            $t->date('claim_day')->nullable()->default(NULL)
                ->comment('The day a SCHEDULED attempt claimed. NULL for one staff started');

            // What happened:
            //   claimed        - the row is written and the provider is being asked
            //   charged        - the money moved
            //   pending        - the provider took it on and will say when it clears
            //   failed         - refused: a decline, an expired card, an outage
            //   authentication - the bank wants the customer there; they were asked to pay
            $t->enum('outcome', ['claimed', 'charged', 'pending', 'failed', 'authentication'])->default('claimed');

            $t->string('message')->nullable()->default(NULL);
            $t->unsignedBigInteger('transaction_relid')->nullable()->default(NULL)
                ->comment('transactions -> tx_id. Set only when this attempt produced a payment');
            $t->timestamp('attempted_at');

            // Indexes
            $t->unique(['invoice_relid', 'claim_day'], 'auto_charge_claim');
            $t->index('pm_relid');
            $t->index('source');
            $t->index('outcome');
            $t->index('attempted_at');
        });
    }
}
