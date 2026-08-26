<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Core\Abstracts\SchemaAbstract;

class DomainContactSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'domain_contacts';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->bigId('dc_id');
            $t->unsignedBigInteger('domain_relid')->comment('domains -> id');
            $t->enum('type', ['registrant','admin','tech','billing', 'abuse']);
            $t->string('company_name')->nullable()->default(NULL);
            $t->string('first_name', 80)->nullable()->default(NULL);
            $t->string('last_name', 80)->nullable()->default(NULL);
            $t->string('email', 80)->nullable()->default(NULL);
            $t->string('phone_cc', 30)->nullable()->default(NULL)->comment('Phone Calling Code');
            $t->string('phone_number', 30)->nullable()->default(NULL);
            $t->string('address1')->nullable()->default(NULL);
            $t->string('address2')->nullable()->default(NULL);
            $t->string('city', 100)->nullable()->default(NULL);
            $t->string('state', 100)->nullable()->default(NULL);
            $t->string('postcode', 20)->nullable()->default(NULL);
            $t->unsignedInteger('country_relid')->comment('countries -> country_id');

            // Indexes
            $t->index('domain_relid');
            $t->index('country_relid');
        });
    }
}
