<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Core\Exceptions\SchemaException;
use LBM\Model\InvoiceModel;
use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Service\Date;
use Laika\Model\Contract\SchemaAbstract;
use LBM\Support\Uid;

class InvoiceSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'invoices';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->bigId('invoice_id');
            $t->uid('uid');
            $t->string('invoice_number', 50);
            $t->unsignedBigInteger('client_relid');
            $t->unsignedInteger('currency_relid')->default(1);
            $t->unsignedInteger('status_relid')->default(1)->comment('invoice_statuses -> status_id');
            $t->decimal('subtotal', 18, 4)->default(0.0000);
            $t->decimal('discount', 18, 4)->default(0.0000);
            $t->decimal('tax', 7, 4)->default(0.0000);
            $t->decimal('total', 18, 4)->default(0.0000);
            $t->decimal('credit_applied', 18, 4)->default(0.0000);
            $t->decimal('amount_paid', 18, 4)->default(0.0000);
            $t->timestamp('invoice_due_date')->nullable()->default(NULL);
            $t->timestamp('invoice_paid_date')->nullable()->default(NULL);
            $t->string('payment_gateway')->nullable()->comment('slug value from payment_gateways');
            $t->text('terms')->nullable()->default(NULL);
            $t->timestamps('invoice_created_at', 'invoice_updated_at');

            // Indexes
            $t->unique('invoice_number');
            $t->index('client_relid');
            $t->index('currency_relid');
            $t->index('status_relid');
            $t->index('invoice_due_date');
            $t->index('invoice_paid_date');
            $t->index('payment_gateway');
            $t->index('invoice_created_at');
            $t->index('invoice_updated_at');
        });
    }

    public function seed(): void
    {
        // Seeds re-run on every app:migrate, not just on table creation,
        // so a bare insert() would collide on the second run.
        if ((new InvoiceModel())->count() > 0) {
            return;
        }

        $model = new InvoiceModel();
        $model->transaction(function (InvoiceModel $m) {
            try {
                $m->insert(Uid::stamp([
                    'client_relid' => 1,
                    'currency_relid' => 1,
                    'invoice_number' => 'inv-202507-12',
                    'status_relid' => 1,
                    'subtotal' => 45.4500,
                    'discount' => 45.4500 * 0.1,
                    'tax' => 45.4500 * 0.15,
                    'total' => 45.4500 + (45.4500 * 0.15) - (45.4500 * 0.1),
                    'credit_applied' => 0.0000,
                    'amount_paid' => 30.0000,
                    'invoice_due_date' => (Date::modify('+7 days'))->format('Y-m-d H:i:s'),
                    'payment_gateway' => 'credit-card'
                ]));
                $m->insert(Uid::stamp([
                    'client_relid' => 1,
                    'currency_relid' => 1,
                    'invoice_number' => 'inv-202608-9',
                    'status_relid' => 1,
                    'subtotal' => 48.4500,
                    'discount' => 48.4500 * 0.1,
                    'tax' => 48.4500 * 0.15,
                    'total' => 48.4500 + (48.4500 * 0.15) - (48.4500 * 0.1),
                    'credit_applied' => 0.0000,
                    'amount_paid' => 28.0000,
                    'invoice_due_date' => (Date::modify('+7 days'))->format('Y-m-d H:i:s'),
                    'payment_gateway' => 'credit-card'
                ]));
                $m->insert(Uid::stamp([
                    'client_relid' => 1,
                    'currency_relid' => 1,
                    'invoice_number' => 'inv-202613-19',
                    'status_relid' => 2,
                    'subtotal' => 25.0000,
                    'discount' => 25.0000 * 0.1,
                    'tax' => 25.0000 * 0.15,
                    'total' => 25.0000 + (25.0000 * 0.15) - (25.0000 * 0.1),
                    'credit_applied' => 0.0000,
                    'amount_paid' => 25.0000 + (25.0000 * 0.15) - (25.0000 * 0.1),
                    'invoice_paid_date' => Date::now()->format('Y-m-d H:i:s'),
                    'payment_gateway' => 'credit-card'
                ]));
            } catch (\Throwable $e) {
                throw new SchemaException($e->getMessage(), (int) $e->getCode(), $e);
            }
        });
    }
}
