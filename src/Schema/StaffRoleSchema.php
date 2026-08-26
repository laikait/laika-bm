<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Model\Contract\SchemaAbstract;

class StaffRoleSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'staff_roles';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->id('role_id');
            $t->string('role_name', 50)->comment('Role Name');
            $t->json('permissions')->comment('JSON Data');
            $t->timestamps('role_created_at', 'role_updated_at');

            // Indexes
            $t->index('role_name');
            $t->index('role_created_at');
        });
    }

    /*
     * No seed(): The superadmin role is created by the installer, which builds its
     * permission set from LBM\Support\Permission::GROUPS.
     */
}
