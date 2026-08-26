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

namespace LBM\Model;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Model;

class StaffModel extends Model
{
    /** @var string Table Name */
    protected string $table = 'staffs';

    /** @var string Primary Column Name */
    protected string $id = 'sid';

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
        'sid'          =>  'int',
        'role_relid'   =>  'int',
        'status_relid' =>  'int',
    ];
}
