<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Core\Exceptions\SchemaException;
use LBM\Model\EmailQueueStatusModel;
use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Core\Abstracts\SchemaAbstract;

class EmailQueueStatusSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'email_queue_statuses';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->id('status_id')->comment('Status ID');
            $t->enum('status_name', ['queued', 'completed', 'failed', 'manual'])->default('queued')->comment('Status Name');
            $t->string('status_color', 25)->comment('Status Color');

            $t->index('status_name');
        });
    }

    public function seed(): void
    {
        $model = new EmailQueueStatusModel();
        $model->transaction(function (EmailQueueStatusModel $m) {
            try {
                $default = [
                    ['status_name' => 'queued', 'status_color' => '#000000'], // Modify Later
                    ['status_name' => 'completed', 'status_color' => '#000000'],
                    ['status_name' => 'failed', 'status_color' => '#000000'],
                    ['status_name' => 'manual', 'status_color' => '#000000'],
                ];
                $m->insert($default);
            } catch (\Throwable $e) {
                throw new SchemaException($e->getMessage(), (int) $e->getCode(), $e);
            }
        });
    }
}
