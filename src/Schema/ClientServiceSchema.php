<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Model\Contract\SchemaAbstract;

class ClientServiceSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'client_services';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->bigId('service_id');
            $t->uid('uid');
            $t->unsignedBigInteger('client_relid')->comment('clients -> cid');
            $t->unsignedInteger('product_relid')->comment('products -> pid');
            $t->unsignedInteger('server_relid')->nullable()->default(NULL)->comment('servers -> server_id');
            $t->string('domain')->nullable()->default(NULL);
            $t->string('username', 60)->nullable()->default(NULL);
            $t->string('password')->nullable()->default(NULL)->comment('Encrypted');
            $t->unsignedInteger('billing_cycle_relid')->comment('billing_cycles -> billing_cycle_id');
            $t->unsignedInteger('currency_relid')->comment('currencies -> currency_id');
            $t->decimal('amount', 18, 4);
            $t->timestamp('next_due_date')->nullable()->default(NULL);
            $t->timestamp('registration_date')->nullable()->default(NULL);
            $t->timestamp('termination_date')->nullable()->default(NULL);
            $t->unsignedInteger('status_relid')->default(1)->comment('client_service_statuses -> status_id');
            $t->string('suspension_reason')->nullable()->default(NULL);
            $t->serialize('module_data')->nullable()->default(NULL)->comment('Serialized Module Data');
            $t->timestamps();

            // Indexes
            $t->index('client_relid');
            $t->index('product_relid');
            $t->index('server_relid');
            $t->index('domain');
            $t->index('currency_relid');
            $t->index('status_relid');
            $t->index('billing_cycle_relid');
            $t->index('next_due_date');
        });
    }
}
