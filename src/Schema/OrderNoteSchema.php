<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Model\Contract\SchemaAbstract;

class OrderNoteSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'order_notes';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->bigId('note_id');
            $t->uid('uid');
            $t->unsignedBigInteger('order_relid')->comment('orders -> oid');
            $t->enum('creator_type', ['client', 'staff', 'system']);
            $t->unsignedBigInteger('creator_relid')->nullable()->default(NULL);
            $t->text('note');
            $t->timestamp('note_created_at');

            // Indexes
            $t->index('order_relid');
            $t->index(['creator_type', 'creator_relid'], 'note_created_by');
            $t->index('note_created_at');
        });
    }
}
