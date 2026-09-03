<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Model\Contract\SchemaAbstract;

/**
 * What a client thought of the support they got.
 *
 * A TABLE rather than columns on `support_tickets`, and that is forced rather
 * than preferred: up() only ever calls createIfNotExists, so a new column
 * reaches a database that does not exist yet and nothing else. A rating column
 * added to support_tickets would exist only on installations created after this
 * release, and the report would be permanently empty on every site already
 * running. A new table is created by migrate on every install, old ones
 * included.
 *
 * One row per ticket - ticket_relid is UNIQUE - so a second submission cannot
 * quietly stack a second opinion on top of the first, whatever the form does.
 *
 * client_relid and staff_relid are written here rather than read back through
 * the ticket, because both can move afterwards: a ticket can be reassigned, and
 * feedback is about whoever actually handled it at the time it was given. The
 * snapshot is the honest record.
 */
class TicketFeedbackSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'ticket_feedback';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->bigId('feedback_id');
            $t->uid('uid');
            $t->unsignedBigInteger('ticket_relid')->comment('support_tickets -> ticket_id');
            $t->unsignedBigInteger('client_relid')->comment('clients -> cid');
            $t->unsignedBigInteger('staff_relid')->nullable()->default(NULL)->comment('staffs -> sid, who was assigned when it was given');
            $t->tinyInteger('rating')->comment('1 to 5, low to high');
            $t->text('comment')->nullable()->default(NULL);
            $t->timestamps('feedback_created_at', 'feedback_updated_at');

            // Indexes
            $t->unique('ticket_relid');
            $t->index('client_relid');
            $t->index('staff_relid');
            $t->index('rating');
            $t->index('feedback_created_at');
        });
    }
}
