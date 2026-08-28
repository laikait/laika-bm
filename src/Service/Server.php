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
 * Provisioning servers and the groups they are allocated from.
 *
 * @see \LBM\Action\Server
 * @method static array all(array $where = [], string $direction = 'ASC', ?string $order = null)
 * @method static array browse(array $where = [], ?string $search = null, ?int $limit = null, string $direction = 'DESC')
 * @method static int count(array $where = [])
 * @method static int create(array $data)
 * @method static ?string credential(array $server, string $column)
 * @method static int delete(int|string $key)
 * @method static int deleteWhere(array $where)
 * @method static bool exists(array $where)
 * @method static array fillTypes()
 * @method static ?array find(int|string|null $key)
 * @method static ?array first(array $where)
 * @method static ?array group(int|string $key)
 * @method static array groups()
 * @method static Model model()
 * @method static int modify(int|string $key, array $input)
 * @method static int remove(int|string $key)
 * @method static int saveGroup(array $input, int|string|null $key = null)
 * @method static ?int statusId(string $name)
 * @method static string statusTable()
 * @method static array statuses()
 * @method static int store(array $input)
 * @method static array test(int|string $key)
 * @method static int update(int|string $key, array $data)
 * @method static int updateWhere(array $where, array $data)
 * @method static ?int usage(array $server)
 */
class Server extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'action.server';
    }
}
