<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Core\Exceptions\SchemaException;
use LBM\Model\OrderNoteModel;
use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Core\Abstracts\SchemaAbstract;

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

    public function seed(): void
    {
        $model = new OrderNoteModel();
        $model->transaction(function (OrderNoteModel $m) {
            try {
                $m->insert([
                    'order_relid' => 1,
                    'creator_type' => 'client',
                    'creator_relid' => 1,
                    'note' => 'New Order Created By Client ID 1'
                ]);
                $m->insert([
                    'order_relid' => 1,
                    'creator_type' => 'client',
                    'creator_relid' => 1,
                    'note' => 'New Order Status Pending By Client ID 1'
                ]);
            } catch (\Throwable $e) {
                throw new SchemaException($e->getMessage(), (int) $e->getCode(), $e);
            }
        });
    }
}
