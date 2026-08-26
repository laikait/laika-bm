<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Core\Abstracts\SchemaAbstract;

class ProductConfigOptionSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'product_config_options';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->id('pco_id')->comment('Product Config Options');
            $t->unsignedInteger('config_group_relid')->comment('product_config_groups -> pcg_id');
            $t->enum('config_type', ['dropdown','radio','checkbox','text','quantity'])->default('dropdown')->comment('dropdown, radio, checkbox, text, quantity');
            $t->enum('is_required', ['yes', 'no']);

            // Indexes
            $t->index('config_group_relid');
        });
    }
}
