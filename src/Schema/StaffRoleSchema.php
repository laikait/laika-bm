<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Core\Exceptions\SchemaException;
use LBM\Model\StaffRoleModel;
use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Core\Abstracts\SchemaAbstract;

class StaffRoleSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'staff_roles';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on()->createIfNotExists('staff_roles', function (Blueprint $t) {
            $t->id('role_id');
            $t->string('role_name', 50)->comment('Role Name');
            $t->json('permissions')->comment('JSON Data');
            $t->timestamps('role_created_at', 'role_updated_at');

            // Indexes
            $t->index('role_name');
            $t->index('role_created_at');
        });
    }

    public function seed(): void
    {
        $model = new StaffRoleModel();
        $model->transaction(function (StaffRoleModel $m) {
            $permissions = [
                'staff'     =>  ['create' => true, 'read' => true, 'update' => true, 'delete' => true],
                'client'    =>  ['create' => true, 'read' => true, 'update' => true, 'delete' => true],
                'product'   =>  ['create' => true, 'read' => true, 'update' => true, 'delete' => true],
                'invoice'   =>  ['create' => true, 'read' => true, 'update' => true, 'delete' => true],
                'order'     =>  ['create' => true, 'read' => true, 'update' => true, 'delete' => true],
                'note'      =>  ['create' => true, 'read' => true, 'update' => true, 'delete' => true],
                'ticket'    =>  ['create' => true, 'read' => true, 'update' => true, 'delete' => true],
                'report'    =>  ['create' => true, 'read' => true, 'update' => true, 'delete' => true],
                'instance'  =>  ['create' => true, 'read' => true, 'update' => true, 'delete' => true],
                'notice'    =>  ['create' => true, 'read' => true, 'update' => true, 'delete' => true],
                'module'    =>  ['create' => true, 'read' => true, 'update' => true, 'delete' => true],
                'settings'  =>  ['create' => true, 'read' => true, 'update' => true, 'delete' => true],
                'issue'     =>  ['create' => true, 'read' => true, 'update' => true, 'delete' => true],
                'log'       =>  ['create' => true, 'read' => true, 'update' => true, 'delete' => true],
                'security'  =>  ['create' => true, 'read' => true, 'update' => true, 'delete' => true],
            ];
            $role = [
                'role_name' => 'superadmin',
                'permissions' => json_encode($permissions, JSON_PRETTY_PRINT)
            ];
            try {
                $m->insert($role);
            } catch (\Throwable $e) {
                throw new SchemaException($e->getMessage(), (int) $e->getCode(), $e);
            }
        });
        return;
    }
}
