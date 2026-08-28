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
 * Domain registrations and their nameservers.
 *
 * @see \LBM\Action\Domain
 * @method static array all(array $where = [], string $direction = 'ASC', ?string $order = null)
 * @method static array browse(array $where = [], ?string $search = null, ?int $limit = null, string $direction = 'DESC')
 * @method static array browseForClient(int $clientId, ?int $limit = null)
 * @method static array browseWithClients(array $where = [], ?string $search = null, ?int $limit = null)
 * @method static int count(array $where = [])
 * @method static int create(array $data)
 * @method static array cycles()
 * @method static ?int daysToExpiry(array $domain)
 * @method static int delete(int|string $key)
 * @method static int deleteWhere(array $where)
 * @method static bool exists(array $where)
 * @method static array expiringWithin(int $days = 30)
 * @method static ?array find(int|string|null $key)
 * @method static ?array first(array $where)
 * @method static ?array forClientKey(int|string $key, int $clientId)
 * @method static bool isExpired(array $domain)
 * @method static Model model()
 * @method static int modify(int|string $key, array $input)
 * @method static array nameservers(int $domainId)
 * @method static int setNameservers(int $domainId, array $hosts)
 * @method static ?int statusId(string $name)
 * @method static string statusTable()
 * @method static array statuses()
 * @method static array types()
 * @method static int update(int|string $key, array $data)
 * @method static int updateWhere(array $where, array $data)
 */
class Domain extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'action.domain';
    }
}
