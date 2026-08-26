<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Core\Abstracts\SchemaAbstract;

class ProvisioningLogSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'provisioning_logs';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->bigId('log_id');
            $t->unsignedBigInteger('service_relid')->comment('client_services -> service_id');
            $t->enum('creator_type', ['staff', 'client', 'system']);
            $t->unsignedBigInteger('creator_relid')->nullable()->comment('staff/client/null -> sid/cid/system');
            $t->enum('action', ['create','suspend','unsuspend','terminate'])->comment('create, suspend, unsuspend, terminate');
            $t->enum('result', ['success','failure','pending'])->comment('success, failure, pending');
            $t->serialize('request_data')->nullable();
            $t->serialize('response_data')->nullable();
            $t->timestamp('log_created_at');

            // Indexes
            $t->index('service_relid');
            $t->index(['creator_type', 'creator_relid'], 'provisioned by');
            $t->index('creator_relid');
            $t->index('log_created_at');
        });
    }
}
