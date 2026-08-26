<?php
/**
 * Laika Framework
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: MIT
 * This file is part of the Laika PHP Framework.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace LBM\Schema;

use Laika\Service\Vault;
use LBM\Model\PasswordModel;
use Laika\Model\Schema\Schema;
use Laika\Model\Schema\Blueprint;
use Laika\Core\Abstracts\SchemaAbstract;
use Laika\Core\Exceptions\SchemaException;

class PasswordModelSchema extends SchemaAbstract
{
    /** @var string Database Table Name */
    protected string $table = 'passwords';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->bigId('id');
            $t->unsignedBigInteger('rel_id')->comment('Client/Staff/... ID');
            $t->string('rel_type', 50)->comment('User Type');
            $t->string('hash')->comment('Password Hash');
            $t->timestamp('revoked_at')->nullable()->default(NULL);
            $t->timestamp('created_at');

            // Indexes
            $t->index(['rel_id','rel_type'], 'rel_user');
            $t->index('revoked_at');
        });
    }

    public function seed(): void
    {
        try {
            $model = new PasswordModel();
            $arr = [
                'rel_id'    =>  1,
                'rel_type'  =>  'staff',
                'hash'      =>  Vault::hashPassword('123456'),
            ];

            $model->transaction(function (PasswordModel $m) use ($arr) {
                $m->insert($arr);
            });
        } catch (\Throwable $e) {
            throw new SchemaException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }
}
