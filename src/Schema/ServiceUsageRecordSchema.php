<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Model\Contract\SchemaAbstract;

class ServiceUsageRecordSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'service_usage_records';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->bigId('sur_id')->comment('Service Usage Records ID');
            $t->uid('uid');
            $t->unsignedBigInteger('service_relid')->comment('client_services -> service_id');
            $t->string('metric_name', 60);
            $t->unsignedBigInteger('quantity');
            $t->timestamp('recorded_at');
            $t->enum('billed', ['yes', 'no'])->default('no');

            // Indexes
            $t->index('billed');
            $t->index(['service_relid', 'billed'], 'sur_service_billed');
        });
    }

}
