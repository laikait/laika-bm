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

class GatewayCallbackModel extends Model
{
    // Table Name
    protected string $table = 'gateway_callbacks';

    // Primary Column Name
    protected string $id = 'callback_id';

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
        'callback_id'  =>  'int',
        'attempts'     =>  'int',
        // Casts run on READ ONLY. Handing insert() or update() an array for
        // this column stores the word "Array" - serialize() it on the way in.
        'payload'      =>  'serialize',
    ];

    // Start Code From Here
}
