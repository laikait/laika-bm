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
 * The products a client owns, provisioned and renewing.
 *
 * @see \LBM\Action\ClientService
 * @method static int activeCount(?int $clientId = null)
 * @method static array addons(int $serviceId)
 * @method static array all(array $where = [], string $direction = 'ASC', ?string $order = null)
 * @method static array browse(array $where = [], ?string $search = null, ?int $limit = null, string $direction = 'DESC')
 * @method static array browseForClient(int $clientId, array $where = [], ?int $limit = null)
 * @method static int count(array $where = [])
 * @method static int create(array $data)
 * @method static ?string credential(array $service)
 * @method static array cycleNames()
 * @method static array cycles()
 * @method static int delete(int|string $key)
 * @method static int deleteWhere(array $where)
 * @method static array dueWithin(int $days = 30, ?int $clientId = null)
 * @method static bool exists(array $where)
 * @method static ?array find(int|string|null $key)
 * @method static int[] finishedStatusIds()
 * @method static ?array first(array $where)
 * @method static array forClient(int $clientId, array $where = [])
 * @method static ?array forClientKey(int|string $key, int $clientId)
 * @method static bool isActive(array $service)
 * @method static bool isFinished(array $service)
 * @method static string label(array $service)
 * @method static Model model()
 * @method static int modify(int|string $key, array $input)
 * @method static ?array product(array $service)
 * @method static int requestCancellation(array $service, int $clientId, string $reason, string $when = 'end_of_term')
 * @method static int setCredential(int|string $key, ?string $credential)
 * @method static int setStatus(int|string $key, string $name, ?string $reason = null)
 * @method static ?int statusId(string $name)
 * @method static string statusTable()
 * @method static array statuses()
 * @method static int store(array $input, ?string $credential = null)
 * @method static int update(int|string $key, array $data)
 * @method static int updateWhere(array $where, array $data)
 */
class ClientService extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'action.client.service';
    }
}
