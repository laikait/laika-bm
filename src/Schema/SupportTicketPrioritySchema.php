<?php

declare(strict_types=1);

// Namespace
namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Core\Exceptions\SchemaException;
use LBM\Model\SupportTicketPriorityModel;
use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Core\Abstracts\SchemaAbstract;

class SupportTicketPrioritySchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'support_priorities';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->id('priority_id');
            $t->string('priority_name');
            $t->string('priority_color');

            // Indexes
            $t->unique('priority_name');
        });
    }

    public function seed(): void
    {
        $model = new SupportTicketPriorityModel();
        $model->transaction(function (SupportTicketPriorityModel $m) {
            try {
                $default = [
                    ['priority_name' => 'low', 'priority_color' => '#000000'],
                    ['priority_name' => 'medium', 'priority_color' => '#000000'],
                    ['priority_name' => 'high', 'priority_color' => '#000000'],
                    ['priority_name' => 'urgent', 'priority_color' => '#000000']
                ];
                $m->insert($default);
            } catch (\Throwable $e) {
                throw new SchemaException($e->getMessage(), (int) $e->getCode(), $e);
            }
        });
    }
}
