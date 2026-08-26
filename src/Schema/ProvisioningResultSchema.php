<?php
/**
 * Laika Bill Manager
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: MIT
 * This file is part of Laika Bill Manager.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace LBM\Schema;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use LBM\Model\ProvisioningResultModel;
use Laika\Model\Schema\Schema;
use Laika\Model\Schema\Blueprint;
use Laika\Model\Contract\SchemaAbstract;
use Laika\Core\Exceptions\SchemaException;

class ProvisioningResultSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'provisioning_results';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->id('result_id');
            $t->string('result_name', 50)->comment('Result Name');
            $t->string('result_color', 25)->comment('Result Colour');
            $t->enum('system_default', ['yes', 'no'])->default('no');

            // Indexes
            $t->unique('result_name');
            $t->index('system_default');
        });
    }

    public function seed(): void
    {
        // Seeds re-run on every app:migrate, not just on table creation,
        // so a bare insert() would collide on the second run.
        if ((new ProvisioningResultModel())->count() > 0) {
            return;
        }

        $model = new ProvisioningResultModel();
        $model->transaction(function (ProvisioningResultModel $m) {
            try {
                $m->insert([
                    ['result_name' => 'pending', 'result_color' => '#f0ad4e', 'system_default' => 'yes'],
                    ['result_name' => 'success', 'result_color' => '#5cb85c', 'system_default' => 'yes'],
                    ['result_name' => 'failure', 'result_color' => '#d9534f', 'system_default' => 'yes']
                ]);
            } catch (\Throwable $e) {
                throw new SchemaException($e->getMessage(), (int) $e->getCode(), $e);
            }
        });
    }
}
