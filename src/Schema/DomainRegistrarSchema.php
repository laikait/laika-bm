<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Model\Contract\SchemaAbstract;

class DomainRegistrarSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'domain_registrars';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->id('dr_id');
            $t->string('name', 100)->comment('Registrar Name');
            $t->string('module_name', 60)->comment('e.g. enom, namesilo, resellerclub');
            $t->string('api_url');
            $t->serialize('credentials')->comment('encrypted key/value pairs');
            $t->enum('is_default', ['yes', 'no'])->default('no');
            $t->enum('is_active', ['yes', 'no'])->default('yes');
            $t->timestamps('dr_created_at', 'dr_updated_at');

            // Indexes
            $t->unique('name');
            $t->unique('module_name');
            $t->index('is_default');
            $t->index('is_active');
            $t->index('dr_created_at');
            $t->index('dr_updated_at');
        });
    }
}
