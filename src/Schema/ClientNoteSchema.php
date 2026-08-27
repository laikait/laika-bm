<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Core\Exceptions\SchemaException;
use LBM\Model\ClientNoteModel;
use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Model\Contract\SchemaAbstract;
use LBM\Support\Uid;

class ClientNoteSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'client_notes';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->bigId('note_id');
            $t->uid('uid');
            $t->unsignedBigInteger('client_relid')->comment('clients -> cid');
            $t->unsignedBigInteger('staff_relid')->comment('staffs -> sid');
            $t->text('note');
            $t->timestamp('note_created_at');

            // Indexes
            $t->index('client_relid');
            $t->index('staff_relid');
            $t->index('note_created_at');
        });
    }

    public function seed(): void
    {
        // Seeds re-run on every app:migrate, not just on table creation,
        // so a bare insert() would collide on the second run.
        if ((new ClientNoteModel())->count() > 0) {
            return;
        }

        $model = new ClientNoteModel();
        $model->transaction(function (ClientNoteModel $m) {
            $logs = [
                [
                    'client_relid' => 1,
                    'staff_relid' => 1,
                    'note' => 'This is a sample note 1 inserted by default'
                ],
                [
                    'client_relid' => 1,
                    'staff_relid' => 1,
                    'note' => 'This is a sample note 2 inserted by default'
                ],
                [
                    'client_relid' => 1,
                    'staff_relid' => 2,
                    'note' => 'This is a sample note 3 inserted by default'
                ],
                [
                    'client_relid' => 2,
                    'staff_relid' => 2,
                    'note' => 'This is a sample note 4 inserted by default'
                ],
                [
                    'client_relid' => 2,
                    'staff_relid' => 1,
                    'note' => 'This is a sample note 5 inserted by default'
                ]
            ];

            try {
                $m->insert(Uid::stamp($logs));
            } catch (\Throwable $e) {
                throw new SchemaException($e->getMessage(), (int) $e->getCode(), $e);
            }
        });
    }
}
