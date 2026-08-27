<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Model\Contract\SchemaAbstract;

class ProductAddonMapSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'product_addon_map';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            // A surrogate key on a pivot table, because ProductAddonMapModel
            // declares `map_id` and `uid` and laika-model needs a single-column
            // primary key for find(), ordering and keyset pagination.
            $t->id('map_id');
            $t->uid('uid');

            $t->unsignedInteger('product_relid')->comment('products -> pid');
            $t->unsignedInteger('addon_relid')->comment('product_addons -> addon_id');

            // Indexes
            //
            // The pair was the primary key. It stays enforced, as a unique
            // constraint, so a product still cannot map the same addon twice.
            $t->unique(['product_relid', 'addon_relid']);
        });
    }
}
