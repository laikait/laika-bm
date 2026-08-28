<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Core\Exceptions\SchemaException;
use LBM\Model\ProductStatusModel;
use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Model\Contract\SchemaAbstract;
use LBM\Support\Uid;

class ProductStatusSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'product_statuses';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->id('status_id');
            $t->uid('uid');
            $t->string('status_name', 50)->comment('Status Name');
            $t->string('status_color', 25)->comment('Status Color');
            $t->enum('system_default', ['yes', 'no'])->default('no');

            // Indexes
            $t->unique('status_name');
            $t->index('system_default');
        });
    }

    /**
     * The statuses a product can be in.
     *
     * This table shipped with no seed at all, which left `products.status_relid`
     * - a NOT NULL column - pointing at nothing on a fresh install: the status
     * dropdown on the new-product form was empty, and every product pill in the
     * panel rendered blank.
     */
    public function seed(): void
    {
        // Seeds re-run on every app:migrate, not just on table creation,
        // so a bare insert() would collide on the second run.
        if ((new ProductStatusModel())->count() > 0) {
            return;
        }

        $statuses = [
            ['status_name' => 'active', 'status_color' => '#198754', 'system_default' => 'yes'],
            ['status_name' => 'hidden', 'status_color' => '#6c757d', 'system_default' => 'yes'],
            ['status_name' => 'retired', 'status_color' => '#495057', 'system_default' => 'yes'],
        ];

        $model = new ProductStatusModel();

        $model->transaction(function (ProductStatusModel $m) use ($statuses): void {
            try {
                $m->insert(Uid::stamp($statuses));
            } catch (\Throwable $e) {
                throw new SchemaException($e->getMessage(), (int) $e->getCode(), $e);
            }
        });
    }
}
