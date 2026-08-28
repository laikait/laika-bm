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
 * Staff accounts and the roles that grant them access.
 *
 * @see \LBM\Action\Staff
 * @method static int activeCount()
 * @method static array all(array $where = [], string $direction = 'ASC', ?string $order = null)
 * @method static array browse(array $where = [], ?string $search = null, ?int $limit = null, string $direction = 'DESC')
 * @method static array browseWithRoles(array $where = [], ?string $search = null, ?int $limit = null)
 * @method static bool canSignIn(array $staff)
 * @method static int count(array $where = [])
 * @method static int create(array $data)
 * @method static int delete(int|string $key)
 * @method static int deleteWhere(array $where)
 * @method static bool emailTaken(string $email, ?int $ignore = null)
 * @method static bool exists(array $where)
 * @method static ?array find(int|string|null $key)
 * @method static ?array findByLogin(string $identifier)
 * @method static ?array first(array $where)
 * @method static Model model()
 * @method static int modify(int|string $key, array $input)
 * @method static int modifyRole(int|string $key, string $name, array $input)
 * @method static array permissionActions()
 * @method static array permissionGroups()
 * @method static int remove(int|string $key)
 * @method static int removeRole(int|string $key)
 * @method static ?array role(int|string $key)
 * @method static array roles()
 * @method static void setPassword(int $staffId, string $password)
 * @method static ?int statusId(string $name)
 * @method static string statusTable()
 * @method static array statuses()
 * @method static int store(array $input, string $password)
 * @method static int storeRole(string $name, array $input)
 * @method static void touchLogin(int $staffId, string $ip)
 * @method static int update(int|string $key, array $data)
 * @method static int updateWhere(array $where, array $data)
 * @method static bool usernameTaken(string $username, ?int $ignore = null)
 */
class Staff extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'action.staff';
    }
}
