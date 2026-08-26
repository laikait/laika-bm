<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Model\Contract\SchemaAbstract;

class ClientContactSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'client_contacts';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->bigId('cc_id')->comment('Client Contact ID');
            $t->uid('cc_uid');
            $t->unsignedBigInteger('client_relid')->comment('clients -> cid');
            $t->string('first_name', 80);
            $t->string('middle_name', 80)->nullable()->default(NULL);
            $t->string('last_name', 80);
            $t->string('email');
            $t->string('username', 80)->nullable()->default(NULL)->comment('Null = No Panel Access');
            // Credentials live in `passwords` with rel_type = 'contact', the same
            // table staff and clients use, so there is one place to hash, verify
            // and rotate a password rather than three.
            $t->string('phone_cc', 5)->nullable()->default(NULL)->comment('Phone Calling Code');
            $t->string('phone_number', 30)->nullable()->default(NULL);
            $t->string('street')->nullable()->default(NULL);
            $t->string('city', 100)->nullable()->default(NULL);
            $t->string('state', 100)->nullable()->default(NULL);
            $t->string('postcode', 20)->nullable()->default(NULL);
            $t->unsignedInteger('country_relid')->nullable()->default(NULL)->comment('countries -> country_id');
            $t->unsignedInteger('status_relid')->default(1)->comment('client_statuses -> status_id');
            $t->json('permissions')->comment('JSON Data');
            $t->enum('is_primary', ['yes', 'no'])->default('no');
            $t->timestamps('cc_created_at', 'cc_updated_at');

            // Indexes
            $t->index('client_relid');
            $t->index('first_name');
            $t->index('last_name');
            $t->index('username');
            $t->index('email');
            $t->index('status_relid');
            $t->index('country_relid');
            $t->index('is_primary');
            $t->index('cc_created_at');
        });
    }

    /*
     * No seed(): Contacts are created through the admin panel; no demo rows are seeded.
     */
}
