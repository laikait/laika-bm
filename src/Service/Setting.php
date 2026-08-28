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
 * Every system setting, stored in the options table.
 *
 * @see \LBM\Action\Setting
 * @method static array all(array $where = [], string $direction = 'ASC', ?string $order = null)
 * @method static bool bool(string $key)
 * @method static array browse(array $where = [], ?string $search = null, ?int $limit = null, string $direction = 'DESC')
 * @method static int count(array $where = [])
 * @method static int create(array $data)
 * @method static int delete(int|string $key)
 * @method static int deleteWhere(array $where)
 * @method static bool exists(array $where)
 * @method static ?array find(int|string|null $key)
 * @method static ?array first(array $where)
 * @method static void flushCaches()
 * @method static ?string get(string $key, ?string $default = null)
 * @method static array group(string $group)
 * @method static int int(string $key, int $default = 0)
 * @method static Model model()
 * @method static void put(string $key, mixed $value)
 * @method static int putMany(array $values)
 * @method static int saveGroup(string $group, array $input)
 * @method static int update(int|string $key, array $data)
 * @method static int updateWhere(array $where, array $data)
 */
class Setting extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'action.setting';
    }
}
