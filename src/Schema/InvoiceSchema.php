<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Service\Date;
use Laika\Model\Contract\SchemaAbstract;

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
}
