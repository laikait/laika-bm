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
use LBM\Support\Uid;

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

    public function seed(): void
    {
        // Seeds re-run on every app:migrate, not just on table creation,
        // so a bare insert() would collide on the second run.
        if ((new CurrencyModel())->count() > 0) {
            return;
        }

        $model = new CurrencyModel();
        $model->transaction(function (CurrencyModel $m) {
            try {
                $currencies = [
                    ['currency_code' => 'USD', 'prefix_symbol' => '$', 'suffix_symbol' => '', 'is_active' => 'yes', 'is_default' => 'yes'],
                    ['currency_code' => 'EUR', 'prefix_symbol' => '€', 'suffix_symbol' => '', 'is_active' => 'yes', 'is_default' => 'yes'],
                    ['currency_code' => 'GBP', 'prefix_symbol' => '£', 'suffix_symbol' => '', 'is_active' => 'yes', 'is_default' => 'yes'],
                    ['currency_code' => 'BDT', 'prefix_symbol' => '৳', 'suffix_symbol' => '', 'is_active' => 'yes', 'is_default' => 'yes'],
                    ['currency_code' => 'INR', 'prefix_symbol' => '₹', 'suffix_symbol' => '', 'is_active' => 'yes', 'is_default' => 'yes'],
                    ['currency_code' => 'JPY', 'prefix_symbol' => '¥', 'suffix_symbol' => '', 'is_active' => 'yes', 'is_default' => 'yes'],
                    ['currency_code' => 'CNY', 'prefix_symbol' => '¥', 'suffix_symbol' => '', 'is_active' => 'yes', 'is_default' => 'yes'],
                    ['currency_code' => 'AUD', 'prefix_symbol' => 'A$', 'suffix_symbol' => '', 'is_active' => 'yes', 'is_default' => 'yes'],
                    ['currency_code' => 'CAD', 'prefix_symbol' => 'C$', 'suffix_symbol' => '', 'is_active' => 'yes', 'is_default' => 'yes'],
                    ['currency_code' => 'CHF', 'prefix_symbol' => 'CHF', 'suffix_symbol' => '', 'is_active' => 'yes', 'is_default' => 'yes'],
                    ['currency_code' => 'KRW', 'prefix_symbol' => '₩', 'suffix_symbol' => '', 'is_active' => 'yes', 'is_default' => 'yes'],
                    ['currency_code' => 'SGD', 'prefix_symbol' => 'S$', 'suffix_symbol' => '', 'is_active' => 'yes', 'is_default' => 'yes'],
                    ['currency_code' => 'HKD', 'prefix_symbol' => 'HK$', 'suffix_symbol' => '', 'is_active' => 'yes', 'is_default' => 'yes'],
                    ['currency_code' => 'MYR', 'prefix_symbol' => 'RM', 'suffix_symbol' => '', 'is_active' => 'yes', 'is_default' => 'yes'],
                    ['currency_code' => 'THB', 'prefix_symbol' => '฿', 'suffix_symbol' => '', 'is_active' => 'yes', 'is_default' => 'yes'],
                    ['currency_code' => 'IDR', 'prefix_symbol' => 'Rp', 'suffix_symbol' => '', 'is_active' => 'yes', 'is_default' => 'yes'],
                    ['currency_code' => 'PHP', 'prefix_symbol' => '₱', 'suffix_symbol' => '', 'is_active' => 'yes', 'is_default' => 'yes'],
                    ['currency_code' => 'VND', 'prefix_symbol' => '₫', 'suffix_symbol' => '', 'is_active' => 'yes', 'is_default' => 'yes'],
                    ['currency_code' => 'PKR', 'prefix_symbol' => '₨', 'suffix_symbol' => '', 'is_active' => 'yes', 'is_default' => 'yes'],
                    ['currency_code' => 'NPR', 'prefix_symbol' => '₨', 'suffix_symbol' => '', 'is_active' => 'yes', 'is_default' => 'yes'],
                    ['currency_code' => 'LKR', 'prefix_symbol' => 'Rs', 'suffix_symbol' => '', 'is_active' => 'yes', 'is_default' => 'yes'],
                    ['currency_code' => 'AED', 'prefix_symbol' => 'د.إ', 'suffix_symbol' => '', 'is_active' => 'yes', 'is_default' => 'yes'],
                    ['currency_code' => 'SAR', 'prefix_symbol' => '﷼', 'suffix_symbol' => '', 'is_active' => 'yes', 'is_default' => 'yes'],
                    ['currency_code' => 'QAR', 'prefix_symbol' => '﷼', 'suffix_symbol' => '', 'is_active' => 'yes', 'is_default' => 'yes'],
                    ['currency_code' => 'KWD', 'prefix_symbol' => 'د.ك', 'suffix_symbol' => '', 'is_active' => 'yes', 'is_default' => 'yes'],
                    ['currency_code' => 'TRY', 'prefix_symbol' => '₺', 'suffix_symbol' => '', 'is_active' => 'yes', 'is_default' => 'yes'],
                    ['currency_code' => 'RUB', 'prefix_symbol' => '₽', 'suffix_symbol' => '', 'is_active' => 'yes', 'is_default' => 'yes'],
                    ['currency_code' => 'ZAR', 'prefix_symbol' => 'R', 'suffix_symbol' => '', 'is_active' => 'yes', 'is_default' => 'yes'],
                    ['currency_code' => 'NGN', 'prefix_symbol' => '₦', 'suffix_symbol' => '', 'is_active' => 'yes', 'is_default' => 'yes'],
                    ['currency_code' => 'GHS', 'prefix_symbol' => '₵', 'suffix_symbol' => '', 'is_active' => 'yes', 'is_default' => 'yes'],
                    ['currency_code' => 'BRL', 'prefix_symbol' => 'R$', 'suffix_symbol' => '', 'is_active' => 'yes', 'is_default' => 'yes'],
                    ['currency_code' => 'MXN', 'prefix_symbol' => 'MX$', 'suffix_symbol' => '', 'is_active' => 'yes', 'is_default' => 'yes'],
                ];
                $m->insert(Uid::stamp($currencies));
            } catch (\Throwable $e) {
                throw new SchemaException($e->getMessage(), (int) $e->getCode(), $e);
            }
        });
    }
}
