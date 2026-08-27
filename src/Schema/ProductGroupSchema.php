<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Model\Contract\SchemaAbstract;

class ProductGroupSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'product_groups';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->id('group_id');
            $t->uid('uid');
            $t->string('group_slug');
            $t->string('group_name', 100);
            $t->text('description');
            $t->enum('is_active', ['yes', 'no'])->default('yes');
            $t->timestamps('group_created_at', 'group_updated_at');

            // Indexes
            $t->unique('group_slug');
            $t->unique('group_created_at');
        });
    }
}
