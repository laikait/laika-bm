<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Model\Contract\SchemaAbstract;

class AnnouncementSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'announcements';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->bigId('id');
            $t->uid('uid');
            $t->unsignedBigInteger('staff_relid')->comment('staffs -> id');
            $t->string('title');
            $t->longText('body');
            $t->timestamp('published_at');
            $t->enum('is_active', ['yes', 'no'])->default('yes');
            $t->timestamp('created_at');

            // Indexes
            $t->index('staff_relid');
            $t->index('is_active');
        });
    }
}
