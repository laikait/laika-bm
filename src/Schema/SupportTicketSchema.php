<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Model\Contract\SchemaAbstract;

class SupportTicketSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'support_tickets';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->bigId('ticket_id');
            $t->string('ticket_number', 50);
            $t->unsignedBigInteger('client_relid')->comment('clients -> cid');
            $t->unsignedInteger('department_relid')->comment('support_departments -> dep_id');
            $t->unsignedBigInteger('service_relid')->nullable()->default(null);
            $t->unsignedBigInteger('assigned_staff_relid')->nullable()->default(null)->comment('staffs -> sid');
            $t->string('subject');
            $t->unsignedInteger('status_relid')->comment('support_ticket_statuses -> status_id');
            $t->unsignedInteger('priority_relid')->comment('support_priorities -> priority_id');
            $t->timestamp('last_reply_at')->nullable()->default(null);
            $t->enum('opened_by', ['client','staff','system'])->default('client')->comment('client, staff, system');
            $t->timestamps('ticket_created_at', 'ticket_updated_at');

            // Indexes
            $t->unique('ticket_number');
            $t->index('client_relid');
            $t->index('department_relid');
            $t->index('service_relid');
            $t->index('assigned_staff_relid');
            $t->index('status_relid');
            $t->index('priority_relid');
            $t->index('ticket_created_at');
            $t->index('ticket_updated_at');
        });
    }
}
