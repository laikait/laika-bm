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

use Laika\Model\Schema\Blueprint;
use Laika\Model\Schema\Schema;
use Laika\Model\Contract\SchemaAbstract;

/**
 * Password reset requests.
 *
 * Keyed by (rel_id, rel_type) like the `passwords` table, so staff, clients and
 * client contacts all reset through one path.
 *
 * Only a SHA-256 hash of the token is stored. The plain token exists in exactly
 * two places - the link in the email, and the URL the person clicks - so a
 * leaked database backup does not hand somebody a working reset link for every
 * account in it. That is the same reasoning as laika-auth's `auth_tokens`.
 *
 * Rows are marked used rather than deleted, so a link cannot be replayed and so
 * an operator can see that a reset happened. LBM\Job\PruneTokensJob clears the
 * spent and expired ones.
 */
class PasswordResetSchema extends SchemaAbstract
{
    protected string $table = 'password_resets';
    protected string $connection = 'default';

    public function up(): void
    {
        Schema::on($this->connection)->createIfNotExists($this->table, function (Blueprint $t) {
            $t->bigId('reset_id');
            $t->uid('uid');
            $t->unsignedBigInteger('rel_id')->comment('Client/Staff/Contact ID');
            $t->string('rel_type', 50)->comment('User Type');
            $t->string('token')->comment('SHA-256 Hash Of The Token, Never The Token');
            $t->string('ip', 50)->nullable()->default(NULL)->comment('Who Asked For It');
            $t->timestamp('expires_at');
            $t->timestamp('used_at')->nullable()->default(NULL);
            $t->timestamp('created_at');
            $t->unique('token');
            $t->index(['rel_id', 'rel_type'], 'rel_user');
            $t->index('expires_at');
            $t->index('used_at');
        });
    }
}
