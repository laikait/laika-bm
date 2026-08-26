<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Model\Contract\SchemaAbstract;

class ProductUsageTierSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'product_usage_tiers';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->id('pst_id')->comment('Product Usage Tiers ID');
            $t->unsignedInteger('product_relid')->comment('products -> id');
            $t->unsignedInteger('currency_relid')->comment('currencies -> currency_id');
            $t->string('metric_name', 60)->comment('e.g. "bandwidth_gb", "api_calls"');
            $t->unsignedBigInteger('units_from');
            $t->unsignedBigInteger('units_to')->nullable()->default(NULL)->comment('NULL = unlimited');
            $t->decimal('unit_price', 18, 8);
            $t->timestamp('pst_created_at');

            // Indexes
            $t->index('product_relid');
            $t->index('currency_relid');
            $t->index('pst_created_at');
        });
    }
}
