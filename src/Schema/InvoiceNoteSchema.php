<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Model\Contract\SchemaAbstract;

class InvoiceNoteSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'invoice_notes';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->bigId('note_id');
            $t->uid('uid');
            $t->unsignedBigInteger('invoice_relid')->comment('invoices -> invoice_id');
            $t->unsignedBigInteger('staff_relid')->comment('staffs -> sid');
            $t->text('note');
            $t->timestamp('note_created_at');

            // Indexes
            $t->index('invoice_relid');
            $t->index('staff_relid');
            $t->index('note_created_at');
        });
    }
}
