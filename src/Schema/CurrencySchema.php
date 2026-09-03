<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Core\Exceptions\SchemaException;
use LBM\Model\CurrencyModel;
use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Model\Contract\SchemaAbstract;
use Laika\Service\Uid;

class CurrencySchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'currencies';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->id('currency_id');
            $t->uid('uid');
            $t->string('currency_code', 3)->comment('ISO 4217 e.g. USD');
            $t->string('prefix_symbol');
            $t->string('suffix_symbol');
            $t->decimal('exchange_rate', 16, 6)->default('1.000000');
            $t->enum('is_active', ['yes', 'no'])->default('yes');
            $t->enum('is_default', ['yes', 'no'])->default('no');
            $t->timestamps('currency_created_at', 'currency_updated_at');

            // Indexes
            $t->unique('currency_code');
            $t->index('is_active');
            $t->index('is_default');
            $t->index('currency_created_at');
            $t->index('currency_updated_at');
        });
    }
}
