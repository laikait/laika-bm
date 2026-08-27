<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Model\Contract\SchemaAbstract;

class SupportCannedResponseSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'support_canned_responses';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->id('response_id');
            $t->uid('uid');
            $t->unsignedInteger('department_relid')->nullable()->default(NULL)->comment('NULL = all departments');
            $t->string('title');
            $t->text('message');
            $t->unsignedBigInteger('staff_relid')->nullable()->default(NULL)->comment('staffs -> sid');
            $t->timestamps('response_created_at', 'response_updated_at');

            // Indexes
            $t->index('department_relid');
            $t->index('staff_relid');
            $t->index('response_created_at');
            $t->index('response_updated_at');
        });
    }
}
