<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Model\Contract\SchemaAbstract;

class DomainSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'domains';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->bigId('domain_id');
            $t->uid('uid');
            $t->string('domain');
            $t->string('tld', 30)->comment('e.g. .com, .net');
            $t->unsignedBigInteger('client_relid')->comment('clients -> cid');
            $t->unsignedInteger('registrar_relid')->comment('domain_registrars -> dr_id');
            $t->unsignedInteger('status_relid')->default(1)->comment('domain_statuses -> status_id');
            $t->enum('type', ['register', 'transfer', 'existing'])->default('register');
            $t->timestamp('registration_date')->nullable()->default(NULL);
            $t->timestamp('expiry_date')->nullable()->default(NULL);
            $t->timestamp('next_due_date')->nullable()->default(NULL);
            $t->enum('billing_cycle', ['annual', 'biennial', 'triennial'])->default('annual');
            $t->unsignedInteger('currency_relid')->comment('currencies -> currency_id');
            $t->decimal('amount', 18, 4);
            $t->enum('id_protection', ['yes', 'no'])->default('no');
            $t->enum('auto_renew', ['yes', 'no'])->default('yes');
            $t->enum('is_locked', ['yes', 'no'])->default('yes');
            $t->string('epp_code')->nullable()->default(NULL)->comment('encrypted');
            $t->serialize('registrar_data')->nullable()->default(NULL)->comment('Registrar Specific Metadata');
            $t->timestamps('domain_created_at', 'domain_updated_at');

            // Indexes
            $t->unique('domain');
            $t->index('client_relid');
            $t->index('registrar_relid');
            $t->index('status_relid');
            $t->index('registration_date');
            $t->index('next_due_date');
            $t->index('currency_relid');
            $t->index('domain_created_at');
            $t->index('domain_updated_at');
        });
    }
}
