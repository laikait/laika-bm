<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Core\Exceptions\SchemaException;
use LBM\Model\SupportDepartmentModel;
use LBM\Support\Uid;
use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Model\Contract\SchemaAbstract;

class SupportDepartmentSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'support_departments';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->id('dep_id');
            $t->uid('uid');
            $t->string('dep_name', 100);
            $t->string('dep_email', 100)->nullable()->default(null)->comment('Inbound Email');
            $t->text('dep_description');
            $t->enum('dep_requires_login', ['yes', 'no'])->default('yes');
            $t->enum('dep_hidden', ['yes', 'no'])->default('no');
            $t->tinyInteger('dep_auto_close_days')->default(7);
            $t->enum('dep_is_active', ['yes', 'no'])->default('yes');
            $t->timestamps('dep_created_at', 'dep_updated_at');

            // Indexes
            $t->unique('dep_name');
            $t->index('dep_is_active');
            $t->index('dep_created_at');
            $t->index('dep_updated_at');
        });
    }

    /**
     * Seed One Department
     *
     * Not decoration: support_tickets.department_relid is NOT NULL, so with an
     * empty table nobody can open a ticket at all and the client area's "new
     * ticket" form has an empty dropdown with nothing to pick.
     *
     * Seeds re-run on every app:migrate, not only on table creation, so this
     * guards on the row count - a bare insert would collide with itself on the
     * second run.
     * @return void
     */
    public function seed(): void
    {
        $model = new SupportDepartmentModel();

        if ($model->count() > 0) {
            return;
        }

        $departments = [
            [
                'dep_name'            =>  'General Support',
                'dep_email'           =>  null,
                'dep_description'     =>  'Questions about your account, services or invoices.',
                'dep_requires_login'  =>  'yes',
                'dep_hidden'          =>  'no',
                'dep_auto_close_days' =>  7,
                'dep_is_active'       =>  'yes',
            ],
        ];

        $model->transaction(function (SupportDepartmentModel $m) use ($departments) {
            try {
                $m->insert(Uid::stamp($departments));
            } catch (\Throwable $e) {
                throw new SchemaException("Insert Failed Into [{$this->table}].", (int) $e->getCode(), $e);
            }
        });
    }
}
