<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Model\Contract\SchemaAbstract;

class CreditNoteSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'credit_notes';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->bigId('credit_note_id');
            $t->uid('uid');
            $t->unsignedBigInteger('client_relid');

            // Which invoice this credits, when it credits one at all.
            //
            // NULLABLE ON PURPOSE. A goodwill credit is a real thing - an
            // apology, a service-level breach, a gesture at renewal time - and
            // it belongs to the client rather than to any one document. Forcing
            // an invoice onto it would make an operator pick an unrelated one.
            //
            // Added to an existing install by
            // M202609070100AddCreditNoteInvoice.
            $t->unsignedBigInteger('invoice_relid')->nullable()->default(NULL)
                ->comment('invoices -> invoice_id. Null for a credit against no particular invoice');
            $t->unsignedInteger('currency_relid');
            $t->decimal('amount', 18, 4);
            $t->decimal('used_amount', 18, 4)->default(0.0000);
            $t->text('reason')->nullable()->default(NULL);
            $t->unsignedInteger('status_relid')->default(1)->comment('credit_note_statuses -> status_id');
            $t->timestamp('credit_created_at');

            // Indexes
            $t->index('client_relid');
            $t->index('invoice_relid');
            $t->index('currency_relid');
            $t->index('status_relid');
            $t->index('credit_created_at');
        });
    }
}
