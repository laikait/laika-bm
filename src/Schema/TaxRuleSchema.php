<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Model\Contract\SchemaAbstract;

class TaxRuleSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'tax_rules';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->id('tr_id');
            $t->uid('uid');
            $t->string('rule_name', 100);
            $t->decimal('rate', 7, 4)->default(0.0000);
            $t->unsignedInteger('country_relid')->nullable()->default(NULL)->comment('NULL = all countries');
            $t->string('state', 100)->nullable()->default(NULL)->comment('NULL = all states');
            $t->enum('is_compound', ['yes', 'no'])->default('no');
            $t->enum('is_active', ['yes', 'no'])->default('yes');
            $t->timestamp('rule_created_at');

            // Indexes
            $t->index('country_relid');
            $t->index('is_compound');
            $t->index('is_active');
            $t->index('rule_created_at');
        });
    }
}
