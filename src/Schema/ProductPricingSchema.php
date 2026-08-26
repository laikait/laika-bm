<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Core\Abstracts\SchemaAbstract;

class ProductPricingSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'product_pricing';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->id('price_id');
            $t->unsignedInteger('product_relid')->comment('products -> pid');
            $t->unsignedInteger('currency_relid')->comment('currencies -> currency_id');
            $t->unsignedInteger('billing_cycle_relid')->comment('billing_cycles -> bc_id');
            $t->decimal('setup_fee', 18, 4)->default(0.0000);
            $t->decimal('price', 18, 4);
            $t->enum('is_active', ['yes', 'no'])->default('yes');
            $t->timestamps('price_created_at', 'price_updated_at');

            // Indexes
            $t->unique(['product_relid', 'currency_relid', 'billing_cycle_relid'], 'product_pricing');
            $t->index('product_relid');
            $t->index('currency_relid');
            $t->index('is_active');
            $t->index('billing_cycle_relid');
            $t->index('price_created_at');
            $t->index('price_updated_at');
        });
    }
}
