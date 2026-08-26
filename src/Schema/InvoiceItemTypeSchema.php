<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Core\Exceptions\SchemaException;
use LBM\Model\InvoiceItemTypeModel;
use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Model\Contract\SchemaAbstract;

class InvoiceItemTypeSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'invoice_item_types';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->id('type_id');
            $t->string('type_name', 50)->comment('Item Type Name');
            $t->timestamp('type_created_at');

            $t->unique('type_name');
            $t->index('type_created_at');
        });
    }

    public function seed(): void
    {
        // Seeds re-run on every app:migrate, not just on table creation,
        // so a bare insert() would collide on the second run.
        if ((new InvoiceItemTypeModel())->count() > 0) {
            return;
        }

        $model = new InvoiceItemTypeModel();
        $model->transaction(function ($m) {
            try {
                $default = [
                    ['type_name' => 'product'],
                    ['type_name' => 'addon'],
                    ['type_name' => 'domain'],
                    ['type_name' => 'setup'],
                    ['type_name' => 'usage'],
                    ['type_name' => 'credit'],
                    ['type_name' => 'discount'],
                    ['type_name' => 'other']
                ];
                $m->insert($default);
            } catch (\Throwable $e) {
                throw new SchemaException($e->getMessage(), (int) $e->getCode(), $e);
            }
        });
    }
}
