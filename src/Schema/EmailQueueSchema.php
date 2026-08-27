<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Model\Contract\SchemaAbstract;

class EmailQueueSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'email_queue';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->bigId('queue_id');
            $t->uid('uid');
            $t->unsignedBigInteger('client_relid')->comment('clients -> cid');
            $t->unsignedInteger('template_relid')->comment('email_templates -> et_id');
            $t->string('to_email', 150);
            $t->string('from_name', 150)->nullable()->default(NULL);
            $t->string('from_email', 150);
            $t->string('reply_to', 150)->nullable()->default(NULL);
            $t->string('subject');
            $t->longText('body_html');
            $t->longText('body_plain');
            $t->unsignedInteger('status_relid')->comment('email_queue_statuses -> status_id');
            $t->unsignedInteger('attempts')->default(0);
            $t->text('error_message')->nullable()->default(NULL);
            $t->timestamp('sent_at')->nullable()->default(NULL);
            $t->timestamp('queue_created_at');

            // Indexes
            $t->index('client_relid');
            $t->index('template_relid');
            $t->index('status_relid');
            $t->index('sent_at');
            $t->index('queue_created_at');
        });
    }
}
