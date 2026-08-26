<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Core\Abstracts\SchemaAbstract;

class KnowledgeBaseCategorySchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'kb_categories';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->id('kb_cat_id');
            $t->unsignedInteger('parent_id')->nullable()->default(NULL)->comment('self -> kb_cat_id');
            $t->string('name');
            $t->string('slug');
            $t->enum('is_active', ['yes', 'no'])->default('yes');

            // Indexes
            $t->index('parent_id');
            $t->unique('slug');
            $t->index('is_active');
        });
    }
}
