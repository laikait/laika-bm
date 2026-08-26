<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Core\Abstracts\SchemaAbstract;

class SupportTicketAttachmentSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'support_ticket_attachments';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->bigId('attachment_id');
            $t->unsignedBigInteger('reply_relid')->comment('support_ticket_replies -> reply_id');
            $t->string('filename');
            $t->string('path');
            $t->string('mime', 60)->nullable()->default(NULL);
            $t->unsignedInteger('size')->nullable()->comment('In Byte');
            $t->timestamp('attachment_created_at');

            // Indexes
            $t->index('reply_relid');
            $t->index('attachment_created_at');
        });
    }
}
