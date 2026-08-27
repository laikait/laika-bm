<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Model\Contract\SchemaAbstract;

class LoginLogSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'login_logs';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->bigId('log_id');
            $t->uid('uid');
            $t->string('rel_type', 50);
            $t->unsignedBigInteger('rel_id')->comment('staffs/clients -> id');
            $t->string('ip', 50);
            $t->string('browser', 50);
            $t->string('os', 50);
            $t->string('user_agent');
            $t->timestamp('created_at');

            // Indexes
            $t->index(['rel_type', 'rel_id'], 'rel_user');
            $t->index('created_at');
        });
    }
}
