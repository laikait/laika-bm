<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Core\Abstracts\SchemaAbstract;

class ProductConfigGroupSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'product_config_groups';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->id('pcg_id')->comment('Product Config Groups ID');
            $t->string('config_group_name', 100)->comment('e.g. ram_size, disk_size, dropdown');
            $t->text('description');
            $t->timestamp('pcg_created_at');

            // Indexes
            $t->unique('config_group_name');
            $t->index('pcg_created_at');
        });
    }
}
