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
 * Orders - what a client asked for, before it is billed.
 *
 * @see \LBM\Action\Order
 * @method static int accept(int|string $key)
 * @method static array all(array $where = [], string $direction = 'ASC', ?string $order = null)
 * @method static array browse(array $where = [], ?string $search = null, ?int $limit = null, string $direction = 'DESC')
 * @method static array browseForClient(int $clientId, ?int $limit = null)
 * @method static array browseWithClients(array $where = [], ?string $search = null, ?int $limit = null)
 * @method static int cancel(int|string $key)
 * @method static int count(array $where = [])
 * @method static int create(array $data)
 * @method static int delete(int|string $key)
 * @method static int deleteWhere(array $where)
 * @method static bool exists(array $where)
 * @method static ?array find(int|string|null $key)
 * @method static ?array first(array $where)
 * @method static ?array forClientKey(int|string $key, int $clientId)
 * @method static array itemTypes()
 * @method static array items(int $orderId)
 * @method static Model model()
 * @method static int modify(int|string $key, array $input)
 * @method static string recalculate(int $orderId)
 * @method static int remove(int|string $key)
 * @method static void replaceItems(int $orderId, array $items)
 * @method static ?int statusId(string $name)
 * @method static string statusTable()
 * @method static array statuses()
 * @method static int store(array $input, array $items = [])
 * @method static int update(int|string $key, array $data)
 * @method static int updateWhere(array $where, array $data)
 */
class Order extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'action.order';
    }
}
