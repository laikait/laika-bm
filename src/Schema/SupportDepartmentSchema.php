<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Core\Abstracts\SchemaAbstract;

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
}
