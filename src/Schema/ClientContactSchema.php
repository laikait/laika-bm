<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Core\Exceptions\SchemaException;
use LBM\Model\ClientContactModel;
use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Core\Abstracts\SchemaAbstract;

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
            $t->string('username', 80)->nullable()->default(NULL)->comment('No Panel Access');
            $t->string('password')->nullable()->default(NULL)->comment('No Panel Access');
            $t->string('phone_cc', 5)->nullable()->default(NULL)->comment('Phone Calling Code');
            $t->string('phone_number', 30)->nullable()->default(NULL);
            $t->string('street')->nullable()->default(NULL);
            $t->string('city', 100)->nullable()->default(NULL);
            $t->string('state', 100)->nullable()->default(NULL);
            $t->string('postcode', 20)->nullable()->default(NULL);
            $t->unsignedInteger('country_relid')->nullable()->default(NULL)->comment('countries -> country_id');
            $t->unsignedInteger('status_relid')->default(1)->comment('client_statuses -> status_id');
            $t->serialize('permissions');
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

    public function seed(): void
    {
        $model = new ClientContactModel();
        $model->transaction(function (ClientContactModel $m) {
            try {
                $m->insert([
                    'client_relid' => 1,
                    'cc_uid' => $m->uid(),
                    'first_name' => 'Contact',
                    'last_name' => '1',
                    'email' => 'testcontact1@test.com',
                    'username' => 'testcontact1',
                    'password' => password_hash('123456', PASSWORD_ARGON2I),
                    'phone_cc' => '+880',
                    'phone_number' => '01713271724',
                    'street' => '173/3, Senbari Road',
                    'city' => 'Mymensingh',
                    'status_relid' => 1,
                    'country_relid' => 17,
                    'permissions' => serialize(['contact' => ['create' => true, 'read' => true,'update' => true, 'delete' => true]]),
                    'is_primary' => 'yes'
                ]);
                $m->insert([
                    'client_relid' => 1,
                    'cc_uid' => $m->uid(),
                    'first_name' => 'Contact',
                    'cc_uid' => $m->uid(),
                    'middle_name' => 'Name',
                    'last_name' => '2',
                    'email' => 'testcontact2@test.com',
                    'username' => 'testcontact2',
                    'password' => password_hash('123456', PASSWORD_ARGON2I),
                    'phone_cc' => '+880',
                    'phone_number' => '01534004963',
                    'status_relid' => 1,
                    'country_relid' => 17,
                    'permissions' => serialize(['contact' => ['create' => true, 'read' => true,'update' => true, 'delete' => true]]),
                    'is_primary' => 'no'
                ]);
            } catch (\Throwable $e) {
                throw new SchemaException($e->getMessage(), (int) $e->getCode(), $e);
            }
        });
    }
}
