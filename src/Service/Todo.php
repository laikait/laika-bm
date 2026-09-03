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
 * A staff member's own to-do list.
 *
 * @see \LBM\Action\Todo
 * @method static int add(int $staffId, array $input)
 * @method static array all(array $where = [], string $direction = 'ASC', ?string $order = null)
 * @method static int count(array $where = [])
 * @method static int countBetween(?string $from = null, ?string $to = null, array $where = [])
 * @method static int delete(int|string $key)
 * @method static bool exists(array $where)
 * @method static ?array find(int|string|null $key)
 * @method static array forStaff(int $staffId, bool $includeDone = true)
 * @method static ?array forStaffKey(int|string $key, int $staffId)
 * @method static Model model()
 * @method static int outstanding(int $staffId)
 * @method static bool toggle(array $todo, ?bool $done = null)
 * @method static int update(int|string $key, array $data)
 */
class Todo extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'action.todo';
    }
}
