<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Core\Exceptions\SchemaException;
use LBM\Model\BillingCycleModel;
use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Model\Contract\SchemaAbstract;
use LBM\Support\Uid;

class BillingCycleSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'billing_cycles';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->id('billing_cycle_id');
            $t->uid('uid');
            $t->string('billing_cycle_name', 50);
            $t->timestamp('billing_cycle_created_at');

            $t->index('billing_cycle_created_at');
        });
    }

    public function seed(): void
    {
        // Seeds re-run on every app:migrate, not just on table creation,
        // so a bare insert() would collide on the second run.
        if ((new BillingCycleModel())->count() > 0) {
            return;
        }

        $cycles = [
            ['billing_cycle_name' => 'one_time'],
            ['billing_cycle_name' => 'monthly'],
            ['billing_cycle_name' => 'semi_annual'],
            ['billing_cycle_name' => 'annual'],
            ['billing_cycle_name' => 'biennial'],
            ['billing_cycle_name' => 'triennial'],
        ];
        $model = new BillingCycleModel();
        $model->transaction(function (BillingCycleModel $m) use ($cycles) {
            try {
                $m->insert(Uid::stamp($cycles));
            } catch (\Throwable $e) {
                throw new SchemaException("Insert Failed Into [{$this->table}].", (int) $e->getCode(), $e);
            }
        });
    }
}
