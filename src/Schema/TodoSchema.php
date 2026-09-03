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
 * A staff member's own list of things to do.
 *
 * Per staff member, not shared: `staff_relid` is on every row and every query
 * scopes to it. A shared list is a different feature - it needs assignment,
 * visibility rules and a notion of who may close somebody else's item - and
 * building the shared version by accident is how a note-to-self ends up
 * readable by the whole company.
 *
 * `due_on` is a date and not a timestamp. An item due "Friday" is not due at a
 * time, and storing one invites a timezone bug on a value nobody wanted that
 * precision from.
 *
 * A new table, so migrate creates it on installations that already exist -
 * see TicketFeedbackSchema for why that distinction decides the design.
 */
class TodoSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'todos';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->bigId('todo_id');
            $t->uid('uid');
            $t->unsignedBigInteger('staff_relid')->comment('staffs -> sid');
            $t->string('title');
            $t->text('notes')->nullable()->default(NULL);
            $t->date('due_on')->nullable()->default(NULL);
            $t->enum('is_done', ['yes', 'no'])->default('no');
            $t->timestamp('done_at')->nullable()->default(NULL);
            $t->timestamps('todo_created_at', 'todo_updated_at');

            // Indexes
            $t->index('staff_relid');
            $t->index('is_done');
            $t->index('due_on');
            $t->index('todo_created_at');
        });
    }
}
