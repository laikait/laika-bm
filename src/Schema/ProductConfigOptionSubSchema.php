<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Model\Contract\SchemaAbstract;

class ProductConfigOptionSubSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'product_config_option_subs';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->id('pcos_id')->comment('Product Config Option Subs ID');
            $t->uid('uid');
            $t->unsignedInteger('pco_relid')->comment('product_config_options -> pco_id');
            $t->string('option_name');
            $t->enum('is_active', ['yes', 'no'])->default('yes');

            // Indexes
            $t->index('pco_relid');
            $t->index('is_active');
        });
    }
}
