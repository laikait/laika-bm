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
use LBM\Support\Uid;

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
            $t->uid('uid');
            $t->string('type_name');
            $t->enum('is_default', ['yes', 'no'])->default('no');

            // Indexes
            $t->unique('type_name');
            $t->index('is_default');
        });
    }

    /**
     * The kinds of thing a product can be.
     *
     * This was called default() rather than seed(), and nothing calls default()
     * - app:migrate runs up() then seed(). So the rows never appeared, and
     * `products.type_relid` is a NOT NULL column: on a fresh install the type
     * dropdown on the new-product form was empty and a product could not be
     * created at all.
     * @return void
     */
    public function seed(): void
    {
        // Seeds re-run on every app:migrate, not just on table creation,
        // so a bare insert() would collide on the second run.
        if ((new ProductTypeModel())->count() > 0) {
            return;
        }

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
                $m->insert(Uid::stamp($default));
            } catch (\Throwable $e) {
                throw new SchemaException($e->getMessage(), (int) $e->getCode(), $e);
            }
        });
        return;
    }
}
