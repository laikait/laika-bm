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

namespace LBM\Service;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Laika\Relay\Relay;

/**
 * Staff role permissions.
 *
 * @see \LBM\Support\Permission
 * @method static bool allows(?int $roleId, string $access)
 * @method static array forRole(int $roleId)
 * @method static array grantAll(array $groups)
 * @method static array fromInput(array $groups, array $input)
 * @method static void flush(?int $roleId = null)
 */
class Permission extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'support.permission';
    }
}
