<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Model\Contract\SchemaAbstract;

class ProductAddonSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'product_addons';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->id('addon_id')->comment('Product Addon ID');
            $t->uid('uid');
            $t->string('addon_name', 150);
            $t->text('description');
            $t->enum('pricing_model', ['one_time','recurring'])->default('recurring');
            $t->enum('is_active', ['yes', 'no'])->default('yes');
            $t->timestamps('addon_created_at', 'addon_updated_at');

            // Indexes
            $t->unique('addon_name');
            $t->index('is_active');
            $t->index('addon_created_at');
            $t->index('addon_updated_at');
        });
    }
}
