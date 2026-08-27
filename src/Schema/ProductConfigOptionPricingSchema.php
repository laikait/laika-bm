<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Model\Contract\SchemaAbstract;

class ProductConfigOptionPricingSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'product_config_option_pricing';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->id('pcop_id')->comment('Product Config Option Pricing ID');
            $t->uid('uid');
            $t->unsignedInteger('pcos_relid')->comment('product_config_option_subs -> pcos_id');
            $t->unsignedInteger('currency_relid')->comment('currencies -> currency_id');
            $t->unsignedInteger('billing_cycle_relid');
            $t->decimal('setup_fee', 18, 4)->default(0.0000);
            $t->decimal('price', 18, 4)->default(0.0000);

            // Indexes
            $t->unique(['pcos_relid', 'currency_relid', 'billing_cycle_relid'], 'pp_config');
            $t->index('pcos_relid');
            $t->index('currency_relid');
            $t->index('billing_cycle_relid');
        });
    }
}
