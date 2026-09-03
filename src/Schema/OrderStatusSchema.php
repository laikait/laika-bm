<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Core\Exceptions\SchemaException;
use LBM\Model\OrderStatusModel;
use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Model\Contract\SchemaAbstract;
use Laika\Service\Uid;

class OrderStatusSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'order_statuses';

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

    public function seed(): void
    {
        // Seeds re-run on every app:migrate, not just on table creation,
        // so a bare insert() would collide on the second run.
        if ((new OrderStatusModel())->count() > 0) {
            return;
        }

        $model = new OrderStatusModel();
        $model->transaction(function (OrderStatusModel $m) {
            try {
                $default = [
                    ['status_name' => 'pending', 'status_color' => '#ffc107', 'system_default' => 'yes'],
                    ['status_name' => 'active', 'status_color' => '#198754', 'system_default' => 'yes'],
                    ['status_name' => 'inactive', 'status_color' => '#6c757d', 'system_default' => 'yes'],
                    ['status_name' => 'cancelled', 'status_color' => '#495057', 'system_default' => 'yes'],
                    ['status_name' => 'fraud', 'status_color' => '#dc3545', 'system_default' => 'yes']
                ];
                $m->insert(Uid::stamp($default));
            } catch (\Throwable $e) {
                throw new SchemaException($e->getMessage(), (int) $e->getCode(), $e);
            }
        });
    }
}
