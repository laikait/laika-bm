<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Core\Exceptions\SchemaException;
use LBM\Model\ClientServiceStatusModel;
use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Model\Contract\SchemaAbstract;
use LBM\Support\Uid;

class ClientServiceStatusSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'client_service_statuses';

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
        if ((new ClientServiceStatusModel())->count() > 0) {
            return;
        }

        // Six-digit hex, like every other status table. These shipped as
        // '#00000' - five digits, which is not a colour any browser accepts -
        // so every service pill rendered with no background at all.
        $statuses = [
            ['status_name' => 'pending', 'status_color' => '#ffc107', 'system_default' => 'yes'],
            ['status_name' => 'active', 'status_color' => '#198754', 'system_default' => 'yes'],
            ['status_name' => 'suspended', 'status_color' => '#fd7e14', 'system_default' => 'yes'],
            ['status_name' => 'terminated', 'status_color' => '#dc3545', 'system_default' => 'yes'],
            ['status_name' => 'cancelled', 'status_color' => '#495057', 'system_default' => 'yes'],
            ['status_name' => 'fraud', 'status_color' => '#b02a37', 'system_default' => 'yes']
        ];
        $model = new ClientServiceStatusModel();
        $model->transaction(function (ClientServiceStatusModel $m) use ($statuses) {
            try {
                $m->insert(Uid::stamp($statuses));
            } catch (\Throwable $e) {
                throw new SchemaException($e->getMessage(), (int) $e->getCode(), $e);
            }
        });
    }
}
