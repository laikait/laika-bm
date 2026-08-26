<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Core\Exceptions\SchemaException;
use LBM\Model\ProductTypeModel;
use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Model\Contract\SchemaAbstract;

class ProductTypeSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'product_types';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->id('product_type_id');
            $t->string('type_name');
            $t->enum('is_default', ['yes', 'no'])->default('no');

            // Indexes
            $t->unique('type_name');
            $t->index('is_default');
        });
    }

    /**
     * Default Values to Insert
     * @return void
     */
    public function default(): void
    {
        $model = new ProductTypeModel();
        $model->transaction(function (ProductTypeModel $m) {
            try {
                $default = [
                    ['type_name' => 'shared_hosting', 'is_default' => 'yes'],
                    ['type_name' => 'vps', 'is_default' => 'yes'],
                    ['type_name' => 'dedicated', 'is_default' => 'yes'],
                    ['type_name' => 'domain', 'is_default' => 'yes'],
                    ['type_name' => 'ssl', 'is_default' => 'yes'],
                    ['type_name' => 'software', 'is_default' => 'yes'],
                    ['type_name' => 'other', 'is_default' => 'yes']
                ];
                $m->insert($default);
            } catch (\Throwable $e) {
                throw new SchemaException($e->getMessage(), (int) $e->getCode(), $e);
            }
        });
        return;
    }
}
