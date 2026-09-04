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
 * Every callback a payment gateway has ever sent us.
 *
 * This table does two jobs, and it exists because they turn out to be the same
 * job.
 *
 * **It is the idempotency key.** `UNIQUE (gateway_relid, event_ref)` is what
 * makes "the same callback twice does not pay an invoice twice" true. Checking
 * for an existing row in PHP and then inserting is a race that two simultaneous
 * retries lose - and gateways retry, in parallel, on exactly the timescale that
 * loses it. Phase 20.4 learned the same thing about ticket feedback: the
 * database is what makes "once" true, not the action, because a module holds
 * the same model.
 *
 * **It is the evidence.** A callback that failed its signature check is not
 * noise to be dropped - it is somebody attempting to mark an invoice paid, and
 * the operator should be able to see it happened. So an unverified callback is
 * recorded exactly like a verified one, with `verified` = no and an `outcome`
 * saying what was done about it, which is nothing.
 *
 * ---------------------------------------------------------------------------
 * Why the unique key is not on `transactions`
 * ---------------------------------------------------------------------------
 * The obvious place to enforce this is `transactions.transaction_ref`, and it
 * does not work: `Transaction::refund()` deliberately copies the original
 * payment's reference onto the refund row, so a unique index there would make
 * every refund fail. A partial index (`WHERE type = 'payment'`) would express
 * it, and PostgreSQL supports one while MySQL does not - and both engines are
 * supported. A separate table sidesteps the whole question and carries the
 * payload as well.
 *
 * ---------------------------------------------------------------------------
 * Duplicates do not insert a second row
 * ---------------------------------------------------------------------------
 * They cannot - that is what the unique key is for - so a repeat bumps
 * `attempts` and `last_seen_at` on the row that is already there. The operator
 * still sees that the gateway sent it four times; there is simply one row per
 * event rather than one per delivery, which is the shape a question like "was
 * this invoice paid twice" wants to be asked in.
 *
 * `event_ref` is nullable on purpose. A callback that cannot be verified often
 * cannot be parsed either, so there is no reference to key it on - and both
 * MySQL and PostgreSQL allow any number of NULLs in a unique index, so those
 * rows always insert and never collide with each other.
 */
class GatewayCallbackSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'gateway_callbacks';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->bigId('callback_id');
            $t->uid('uid');
            $t->unsignedInteger('gateway_relid')->comment('payment_gateways -> gateway_id');

            // 191, not 255: the utf8mb4 limit for a column inside a UNIQUE key
            // on older MySQL row formats. The same reason MigrationSchema caps
            // migration_key.
            $t->string('event_ref', 191)->nullable()->default(NULL)
                ->comment('The gateway\'s own reference. NULL when the callback could not be parsed');

            $t->string('event_type', 100)->nullable()->default(NULL)->comment('payment.succeeded, refund.created, ...');
            $t->unsignedBigInteger('invoice_relid')->nullable()->default(NULL)->comment('invoices -> invoice_id');
            $t->unsignedBigInteger('transaction_relid')->nullable()->default(NULL)
                ->comment('transactions -> tx_id. Set only when this callback produced a payment');

            $t->enum('verified', ['yes', 'no'])->default('no')
                ->comment('Whether the driver could check the signature');

            // What was DONE, which is not the same as what arrived:
            //   applied    - a payment was recorded against an invoice
            //   duplicate  - already seen; nothing written
            //   unverified - signature check failed or was impossible
            //   ignored    - understood, but there was nothing to do
            //   rejected   - understood, and refused
            $t->enum('outcome', ['applied', 'duplicate', 'unverified', 'ignored', 'rejected'])
                ->default('rejected');

            $t->string('message')->nullable()->default(NULL);
            $t->unsignedInteger('attempts')->default(1)->comment('How many times the gateway has sent this event');
            $t->serialize('payload')->nullable()->default(NULL);
            $t->timestamps('first_seen_at', 'last_seen_at');

            // Indexes
            $t->unique(['gateway_relid', 'event_ref'], 'gateway_callback_event');
            $t->index('invoice_relid');
            $t->index('transaction_relid');
            $t->index('outcome');
            $t->index('verified');
            $t->index('first_seen_at');
            $t->index('last_seen_at');
        });
    }
}
