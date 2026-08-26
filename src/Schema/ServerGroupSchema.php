<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Core\Abstracts\SchemaAbstract;

class ServerGroupSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'server_groups';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->id('group_id');
            $t->string('group_name', 100);
            $t->enum('fill_type', ['sequentially','by_server','least_full'])->default('sequentially');
            $t->timestamp('group_created_at');

            $t->index('group_created_at');
        });
    }
}
