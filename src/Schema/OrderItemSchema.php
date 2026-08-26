<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Core\Abstracts\SchemaAbstract;

class OrderItemSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'order_items';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->bigId('order_item_id');
            $t->unsignedBigInteger('order_relid')->comment('orders -> oid');
            $t->enum('type', ['product', 'addon', 'domain']);
            $t->unsignedInteger('product_relid')->nullable()->default(NULL)->comment('products -> pid');
            $t->unsignedInteger('addon_relid')->nullable()->default(NULL)->comment('product_addons -> addon_id');
            $t->string('billing_cycle', 30)->nullable()->default(NULL);
            $t->string('domain')->nullable()->default(NULL);
            $t->unsignedInteger('quantity')->default(1);
            $t->decimal('amount', 18, 4);
            $t->unsignedBigInteger('service_relid')->nullable()->default(NULL)->comment('client_services -> service_id. populated after provisioning');
            $t->unsignedBigInteger('domain_relid')->nullable()->default(NULL)->comment('domains -> domain_id. populated after domain registration');
            $t->timestamps('order_item_created_at', 'order_item_updated_at');

            // Indexes
            $t->index('order_relid');
            $t->index('type');
            $t->index('product_relid');
            $t->index('addon_relid');
            $t->index('order_item_created_at');
            $t->index('order_item_updated_at');
        });
    }
}
