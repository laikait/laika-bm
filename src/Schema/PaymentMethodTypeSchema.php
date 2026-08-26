<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Core\Exceptions\SchemaException;
use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use LBM\Model\PaymentMethodTypeModel;
use Laika\Model\Contract\SchemaAbstract;

class PaymentMethodTypeSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'payment_method_types';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->id('pm_type_id');
            $t->string('type_name', 100);
            $t->enum('is_default', ['yes', 'no'])->default('no');

            // Indexes
            $t->unique('type_name');
            $t->index('is_default');
        });
    }

    public function seed(): void
    {
        // Seeds re-run on every app:migrate, not just on table creation,
        // so a bare insert() would collide on the second run.
        if ((new PaymentMethodTypeModel())->count() > 0) {
            return;
        }

        $model = new PaymentMethodTypeModel();
        $model->transaction(function ($m) {
            try {
                $default = [
                    ['type_name' => 'card', 'is_default' => 'yes'],
                    ['type_name' => 'bank_account', 'is_default' => 'yes'],
                    ['type_name' => 'paypal', 'is_default' => 'yes'],
                    ['type_name' => 'other', 'is_default' => 'yes']
                ];
                $m->insert($default);
            } catch (\Throwable $e) {
                throw new SchemaException($e->getMessage(), (int) $e->getCode(), $e);
            }
        });
    }
}
