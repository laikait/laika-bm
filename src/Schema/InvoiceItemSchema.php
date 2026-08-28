<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Model\Contract\SchemaAbstract;

class InvoiceItemSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'invoice_items';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->bigId('invoice_item_id');
            $t->uid('uid');
            $t->unsignedBigInteger('invoice_relid')->comment('invoices -> invoice_id');
            $t->unsignedInteger('item_type_relid')->default(1)->comment('invoice_item_types -> type_id');
            $t->string('description', 500);
            $t->decimal('quantity', 18, 4)->default(1.0000);
            $t->decimal('unit_price', 18, 4);
            $t->decimal('tax', 7, 4)->default(0.0000);
            $t->decimal('discount', 18, 4)->default(0.0000);
            $t->decimal('total', 18, 4)->comment('quantity * unit_price - discount');
            $t->unsignedBigInteger('service_relid')->nullable()->default(NULL)->comment('client_services -> service_id');
            $t->unsignedBigInteger('domain_relid')->nullable()->default(NULL)->comment('domains -> domain_id');
            $t->timestamp('period_start')->nullable()->default(NULL);
            $t->timestamp('period_end')->nullable()->default(NULL);
            $t->timestamps('invoice_item_created_at', 'invoice_item_updated_at');

            // Indexes
            $t->index('invoice_relid');
            $t->index('item_type_relid');
            $t->index('service_relid');
            $t->index('domain_relid');
            $t->index('invoice_item_created_at');
            $t->index('invoice_item_updated_at');
        });
    }
}
