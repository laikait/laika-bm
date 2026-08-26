<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Core\Abstracts\SchemaAbstract;

class ClientSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'clients';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->bigId('cid');
            $t->uid('cuid');
            $t->string('company_name')->nullable()->default(NULL);
            $t->string('first_name', 80);
            $t->string('middle_name', 80)->nullable()->default(NULL);
            $t->string('last_name', 80);
            $t->string('email');
            $t->string('username', 80)->nullable()->default(NULL);
            $t->string('phone_cc', 5)->nullable()->default(NULL)->comment('Phone Calling Code');
            $t->string('phone_number', 30)->nullable()->default(NULL);
            $t->string('street')->nullable()->default(NULL);
            $t->string('city', 100)->nullable()->default(NULL);
            $t->string('state', 100)->nullable()->default(NULL);
            $t->string('postcode', 20)->nullable()->default(NULL);
            $t->unsignedInteger('country_relid')->nullable()->default(NULL)->comment('countries -> country_id');
            $t->unsignedInteger('currency_relid')->nullable()->default(NULL)->comment('currencies -> currency_id');
            $t->unsignedInteger('status_relid')->default(2)->comment('client_statuses -> status_id');
            $t->timestamp('email_verified_at')->nullable()->default(null);
            $t->decimal('credit_balance', 18, 4)->default(0.0000);
            $t->enum('tax_exempt', ['yes', 'no'])->default('yes');
            $t->string('tax_id')->nullable()->default(NULL)->comment('VAT / GST / EIN etc');
            $t->timestamp('last_login_at')->nullable()->default(null);
            $t->string('last_login_ip', 100)->nullable()->default(null);
            $t->enum('is_restricted', ['yes', 'no'])->default('no');
            $t->timestamps('client_created_at', 'client_updated_at');

            $t->index('first_name');
            $t->index('last_name');
            $t->index('username');
            $t->unique('email');
            $t->index('country_relid');
            $t->index('currency_relid');
            $t->index('status_relid');
            $t->index('is_restricted');
            $t->index('client_created_at');
            $t->index('client_updated_at');
        });
    }
}
