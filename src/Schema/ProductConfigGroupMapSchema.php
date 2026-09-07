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
 * Which products offer which configurable option groups.
 *
 * THE TABLE PHASE 0 LEFT OUT. `product_config_options` carries a
 * `config_group_relid` and nothing else - there is no `product_relid` on it, and
 * no map table anywhere, so five config tables shipped with no way to say which
 * product any of them belonged to. That is the whole reason configurable options
 * sat unreadable while addons - which got `product_addon_map` - did not.
 *
 * A NEW TABLE rather than a column on `product_config_options`, and that is not
 * only convenience. `up()` never adds a column to a table that already exists,
 * so a `product_relid` would have needed a migration to reach any install in the
 * world; a new table reaches every one of them on the next migrate. It is also
 * the better shape: one group of choices offered by six products is one row per
 * product, not six copies of the group.
 */
class ProductConfigGroupMapSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'product_config_group_map';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            // A surrogate key on a pivot table, for ProductAddonMapSchema's
            // reason: laika-model needs a single-column primary key for find(),
            // ordering and keyset pagination.
            $t->id('pcgm_id');
            $t->uid('uid');

            $t->unsignedInteger('product_relid')->comment('products -> pid');
            $t->unsignedInteger('config_group_relid')->comment('product_config_groups -> pcg_id');

            // Indexes
            //
            // The pair is unique, so a product cannot offer the same group
            // twice - which would put the same field on the order form twice
            // and let a customer answer it two different ways.
            $t->unique(['product_relid', 'config_group_relid'], 'pcg_map');
            $t->index('config_group_relid');
        });
    }
}
