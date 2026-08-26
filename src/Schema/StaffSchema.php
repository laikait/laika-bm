<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Core\Exceptions\SchemaException;
use LBM\Model\StaffModel;
use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Core\Abstracts\SchemaAbstract;

class StaffSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'staffs';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->bigId('sid');
            $t->uid('uid');
            $t->unsignedInteger('role_relid')->comment('staff_roles -> role_id');
            $t->string('first_name', 80);
            $t->string('middle_name', 80)->nullable()->default(null);
            $t->string('last_name', 80);
            $t->string('username', 80)->nullable()->default(NULL);
            $t->string('email');
            $t->unsignedInteger('status_relid')->comment('staff_statuses -> status_id');
            $t->string('two_factor_secret')->nullable()->default(null);
            $t->timestamp('last_login_at')->nullable()->default(null);
            $t->string('last_login_ip', 100)->nullable()->default(null);
            $t->enum('is_restricted', ['yes', 'no'])->default('no');
            $t->timestamps('staff_created_at', 'staff_updated_at');

            // Indexes
            $t->index('username');
            $t->unique('email');
            $t->index('role_relid');
            $t->index('status_relid');
            $t->index('is_restricted');
            $t->index('staff_created_at');
        });
    }

    public function seed(): void
    {
        $model = new StaffModel();
        $model->transaction(function (StaffModel $m) {
            try {
                $statuses = [
                    'role_relid' => 1,
                    'uid' => $m->uid(),
                    'first_name' => 'Showket',
                    'last_name' => 'Ahmed',
                    'username' => 'riyadhtayf',
                    'email' => 'riyadhtayf@gmail.com',
                    'status_relid' => 1
                ];
                $m->insert($statuses);
            } catch (\Throwable $e) {
                throw new SchemaException("Unable to Insert Default Into 'staffs. {$e->getMessage()}", (int) $e->getCode(), $e);
            }
        });
        return;
    }
}
