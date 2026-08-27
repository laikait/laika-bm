<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Model\Contract\SchemaAbstract;

class KnowledgeBaseSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'articles';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->bigId('kb_id');
            $t->uid('uid');
            $t->unsignedInteger('category_relid')->nullable()->default(NULL)->comment('kb_categories -> kb_cat_id');
            $t->unsignedBigInteger('staff_relid')->comment('staffs -> sid');
            $t->string('title');
            $t->string('slug');
            $t->longText('body');
            $t->unsignedInteger('views')->default(0);
            $t->unsignedInteger('votes_helpful')->default(0);
            $t->unsignedInteger('votes_unhelpful')->default(0);
            $t->enum('is_featured', ['yes', 'no'])->default('no');
            $t->enum('is_active', ['yes', 'no'])->default('yes');
            $t->timestamp('published_at');
            $t->timestamps('kb_created_at', 'kb_updated_at');

            // Indexes
            $t->index('category_relid');
            $t->index('staff_relid');
            $t->unique('slug');
            $t->index('is_active');
            $t->index('kb_created_at');
        });
    }
}
