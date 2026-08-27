<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Model\Contract\SchemaAbstract;

class ProductSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'products';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->id('pid');
            $t->uid('uid');
            $t->string('product_slug');
            $t->unsignedInteger('group_relid')->comment('product_groups -> group_id');
            $t->string('product_name');
            $t->text('description')->nullable()->default(NULL);
            $t->unsignedInteger('type_relid')->comment('product_types -> product_type_id');
            $t->enum('pricing_model', ['one_time','recurring','usage','free'])->default('recurring')->comment('one_time, recurring, usage, free');
            $t->decimal('setup_fee', 18, 6)->default(0.0000);
            $t->decimal('tax_rate', 7, 4)->default(0.0000)->comment('% Applied');
            $t->unsignedInteger('welcome_email_relid')->nullable()->default(NULL)->comment('email_templates -> et_id');
            $t->string('module_name', 100)->nullable()->default(NULL)->comment('Provisioning Module');
            $t->serialize('module_config')->nullable()->default(NULL)->comment('Server Config Params');
            $t->enum('stock_control', ['yes', 'no'])->default('no');
            $t->unsignedInteger('stock_qty')->nullable()->default(NULL);
            $t->enum('is_featured', ['yes', 'no'])->default('no');
            $t->unsignedInteger('status_relid')->comment('product_statuses -> status_id');
            $t->timestamps('product_created_at', 'product_updated_at');

            // Indexes
            $t->unique('product_slug');
            $t->index('welcome_email_relid');
            $t->index('status_relid');
            $t->index('group_relid');
            $t->index('type_relid');
            $t->index('product_created_at');
            $t->index('product_updated_at');
        });
    }
}
