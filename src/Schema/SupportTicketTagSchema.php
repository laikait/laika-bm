<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Core\Abstracts\SchemaAbstract;

class SupportTicketTagSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'support_ticket_tags';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->id('tag_id');
            $t->unsignedBigInteger('ticket_relid')->comment('support_tickets -> ticket_id');
            $t->string('tag', 60);

            // Index
            $t->unique(['ticket_relid', 'tag'], 'ticket_tag');
        });
    }
}
