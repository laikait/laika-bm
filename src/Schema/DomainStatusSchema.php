<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Core\Exceptions\SchemaException;
use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use LBM\Model\DomainStatus;
use Laika\Core\Abstracts\SchemaAbstract;

class DomainStatusSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'domain_statuses';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->id('status_id')->comment('Domain Status');
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
        $statuses = [
            ['status_name' => 'pending', 'status_color' => '#000000', 'system_default' => 'yes'],
            ['status_name' => 'active', 'status_color' => '#000000', 'system_default' => 'yes'],
            ['status_name' => 'expired', 'status_color' => '#000000', 'system_default' => 'yes'],
            ['status_name' => 'transferred', 'status_color' => '#000000', 'system_default' => 'yes'],
            ['status_name' => 'cancelled', 'status_color' => '#000000', 'system_default' => 'yes'],
            ['status_name' => 'fraud', 'status_color' => '#000000', 'system_default' => 'yes'],
            ['status_name' => 'grace', 'status_color' => '#000000', 'system_default' => 'yes'],
            ['status_name' => 'redemption', 'status_color' => '#000000', 'system_default' => 'yes'],
            ['status_name' => 'suspended', 'status_color' => '#000000', 'system_default' => 'yes']
        ];
        $model = new DomainStatus();
        $model->transaction(function (DomainStatus $m) use ($statuses) {
            try {
                $m->insert($statuses);
            } catch (\Throwable $e) {
                throw new SchemaException($e->getMessage(), (int) $e->getCode(), $e);
            }
        });
    }
}
