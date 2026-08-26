<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Model\Contract\SchemaAbstract;

class ServerSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'servers';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->id('server_id');
            $t->unsignedInteger('group_relid')->nullable()->default(null)->comment('server_groups -> group_id');
            $t->string('name', 100);
            $t->string('hostname');
            $t->string('ip_address', 100);
            $t->serialize('ip_addresses');
            $t->string('module_name', 100)->comment('e.g. cpanel, plesk, directadmin');
            $t->string('username', 100)->nullable()->default(NULL)->comment('Encrypted');
            $t->string('password')->nullable()->default(NULL)->comment('Encrypted');
            $t->text('access_key')->nullable()->default(NULL)->comment('Encrypted');
            $t->smallInteger('port')->default(2083);
            $t->enum('use_ssl', ['yes', 'no'])->default('yes');
            $t->string('nameserver1')->nullable()->default(NULL);
            $t->string('nameserver2')->nullable()->default(NULL);
            $t->smallInteger('max_accounts')->unsigned()->nullable()->default(NULL);
            $t->smallInteger('active_accounts')->unsigned()->default(0);
            $t->unsignedInteger('disk_used')->nullable()->default(NULL)->comment('MB');
            $t->unsignedInteger('bandwidth_used')->nullable()->default(NULL)->comment('MB');
            $t->unsignedInteger('status_relid')->comment('server_statuses -> status_id');
            $t->timestamps();

            // Indexes
            $t->index('group_relid');
            $t->index('status_relid');
        });
    }
}
