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

class ServerModel extends Model
{
    // Table Name
    protected string $table = 'servers';

    // Primary Column Name
    protected string $id = 'server_id';

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
        'server_id'       =>  'int',
        'group_relid'     =>  'int',
        'ip_addresses'    =>  'serialize',
        'port'            =>  'int',
        'max_accounts'    =>  'int',
        'active_accounts' =>  'int',
        'disk_used'       =>  'int',
        'bandwidth_used'  =>  'int',
        'status_relid'    =>  'int',
    ];

    // Start Code From Here
}
