<?php
/**
 * Laika Framework
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: MIT
 * This file is part of the Laika Framework.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace LBM\Model;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Model;
use Laika\Service\Visitor;
use Laika\Service\Uid;

class LoginLogModel extends Model
{
    // Table Name
    protected string $table = 'login_logs';

    // Primary Column Name
    protected string $id = 'log_id';

    /** @var string UID Column Name */
    protected string $uid = 'uid';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    /** @var bool Soft Delete */
    protected bool $softDelete = false;

    /** @var string Deleted At Column */
    protected string $deletedAtColumn = 'deleted_at';

    /** @var array<string,string> Casts, derived from the column types in the schema */
    protected array $casts = [
        'log_id' =>  'int',
        'rel_id' =>  'int',
    ];

    /**
     * Record a Successful Sign-In
     *
     * Three things here are not optional, and all three used to be missing.
     *
     * The uid is UNIQUE with no default, so omitting it stores '' - which works
     * exactly once and then collides with itself on every later sign-in.
     *
     * `os` and `created_at` are NOT NULL, so a row without them is rejected
     * outright on a strict MySQL and stored as nonsense on a lax one.
     *
     * And the string columns are narrow - browser and os hold 50 characters,
     * user_agent 255 - while a real user agent runs well past that. Trimming
     * here means a long one is recorded short rather than stopping somebody
     * signing in.
     * @param int $relId Staff/Client/Contact ID
     * @param string $relType User Type. staff, client or contact
     * @return void
     */
    public function createLog(int $relId, string $relType): void
    {
        $ip = Visitor::ip();

        $this->insert([
            $this->uid    =>  Uid::make(),
            'rel_id'      =>  $relId,
            'rel_type'    =>  $relType,
            'ip'          =>  is_string($ip) && $ip !== '' ? mb_substr($ip, 0, 50) : 'unknown',
            'browser'     =>  mb_substr(Visitor::browser(), 0, 50),
            'os'          =>  mb_substr(Visitor::os(), 0, 50),
            'user_agent'  =>  mb_substr(Visitor::userAgent(), 0, 255),
            'created_at'  =>  date('Y-m-d H:i:s'),
        ]);
    }
}
