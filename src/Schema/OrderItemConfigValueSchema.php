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
 * What a customer chose, recorded against the order line that carries it.
 *
 * `client_service_config_values` has existed since Phase 0 and holds the same
 * answers against a SERVICE - but a service does not exist until the invoice is
 * paid and Provision runs, which can be days later. Between checking out and
 * being provisioned the choices have to live somewhere, and `order_items` has no
 * column and no serialize blob to put them in.
 *
 * So this is the order-side twin, column for column, and provisioning copies the
 * rows across. Deliberately identical: a shape that drifts from the one it is
 * copied into is how a chosen value arrives at the server subtly different from
 * the one the customer agreed to pay for.
 *
 * A NEW TABLE, not `order_items.type = 'config'`. Widening that enum is a change
 * to a table that already exists, which `up()` cannot make - and it would be the
 * wrong answer anyway: a configurable option is not a line of money. See
 * Action\ConfigOption for why the price folds into the plan's own line.
 *
 * NO PRICE COLUMN, for the same reason `client_service_config_values` has none.
 * The money is in `order_items.amount` on the line these hang off. Recording it
 * twice is recording it in two places that can disagree.
 */
class OrderItemConfigValueSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'order_item_config_values';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->bigId('oicv_id')->comment('Order Item Config Values ID');
            $t->uid('uid');
            $t->unsignedBigInteger('order_item_relid')->comment('order_items -> order_item_id');
            $t->unsignedInteger('pco_relid')->comment('product_config_options -> pco_id');
            $t->unsignedInteger('pcos_relid')->nullable()->default(null)->comment('product_config_option_subs -> pcos_id');
            $t->unsignedInteger('quantity')->nullable()->default(null);
            $t->string('text_value', 500)->nullable()->default(null);

            // Indexes
            $t->index('order_item_relid');
            $t->index('pco_relid');
            $t->index('pcos_relid');
        });
    }
}
