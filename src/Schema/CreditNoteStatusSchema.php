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

use LBM\Model\CreditNoteStatusModel;
use Laika\Model\Schema\Schema;
use Laika\Model\Schema\Blueprint;
use Laika\Model\Contract\SchemaAbstract;
use LBM\Support\Uid;
use Laika\Core\Exceptions\SchemaException;

class CreditNoteStatusSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'credit_note_statuses';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->id('status_id');
            $t->uid('uid');
            $t->string('status_name', 50)->comment('Status Name');
            $t->string('status_color', 25)->comment('Status Colour');
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
        if ((new CreditNoteStatusModel())->count() > 0) {
            return;
        }

        $model = new CreditNoteStatusModel();
        $model->transaction(function (CreditNoteStatusModel $m) {
            try {
                $m->insert(Uid::stamp([
                    ['status_name' => 'open', 'status_color' => '#5bc0de', 'system_default' => 'yes'],
                    ['status_name' => 'partial', 'status_color' => '#f0ad4e', 'system_default' => 'yes'],
                    ['status_name' => 'used', 'status_color' => '#5cb85c', 'system_default' => 'yes'],
                    ['status_name' => 'voided', 'status_color' => '#777777', 'system_default' => 'yes']
                ]));
            } catch (\Throwable $e) {
                throw new SchemaException($e->getMessage(), (int) $e->getCode(), $e);
            }
        });
    }
}
