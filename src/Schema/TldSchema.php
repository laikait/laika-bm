<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Model\Contract\SchemaAbstract;

class TldSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'tlds';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->id('tld_id');
            $t->unsignedInteger('registrar_relid')->comment('domain_registrars -> dr_id');
            $t->unsignedInteger('currency_relid')->comment('currencies -> currency_id');
            $t->string('tld', 30)->comment('e.g. .com, .net');
            $t->tinyInteger('min_years')->default(1);
            $t->tinyInteger('max_years')->default(10);
            $t->decimal('register_price', 18, 4)->default(0.0000);
            $t->decimal('renew_price', 18, 4)->default(0.0000);
            $t->decimal('transfer_price', 18, 4)->default(0.0000);
            $t->decimal('restore_price', 18, 4)->default(0.0000);
            $t->enum('epp_required', ['yes', 'no'])->default('yes');
            $t->enum('id_protection', ['yes', 'no'])->default('yes');
            $t->enum('is_active', ['yes', 'no'])->default('yes');
            $t->timestamps('tld_created_at', 'tld_updated_at');

            // Indexes
            $t->unique(['registrar_relid', 'tld'], 'tld_registrar');
            $t->unique('tld');
            $t->index('registrar_relid');
            $t->index('currency_relid');
            $t->index('is_active');
            $t->index('tld_created_at');
            $t->index('tld_updated_at');
        });
    }
}
