<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Core\Abstracts\SchemaAbstract;


class ClientServiceAddonSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'client_service_addons';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->bigId('csa_id')->comment('Client Service Addon ID');
            $t->unsignedBigInteger('service_relid')->comment('client_services -> service_id');
            $t->unsignedInteger('addon_relid')->comment('product_addons -> addon_id');
            $t->unsignedInteger('billing_cycle_relid')->comment('billing_cycles -> billing_cycle_id');
            $t->unsignedInteger('currency_relid')->comment('currencies -> currency_id');
            $t->decimal('amount', 18, 4);
            $t->unsignedInteger('status_relid')->comment('client_service_statuses -> status_id');
            $t->timestamp('next_due_date')->nullable()->default(null);
            $t->timestamp('csa_created_at');

            // Indexes
            $t->index('service_relid');
            $t->index('addon_relid');
            $t->index('billing_cycle_relid');
            $t->index('currency_relid');
            $t->index('status_relid');
            $t->index('next_due_date');
            $t->index('csa_created_at');
        });
    }
}
