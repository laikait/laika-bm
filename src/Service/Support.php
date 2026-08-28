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
 * Support tickets, replies and departments.
 *
 * @see \LBM\Action\Support
 * @method static array all(array $where = [], string $direction = 'ASC', ?string $order = null)
 * @method static int assign(int|string $key, ?int $staffId)
 * @method static array browse(array $where = [], ?string $search = null, ?int $limit = null, string $direction = 'DESC')
 * @method static array browseForClient(int $clientId, ?int $limit = null)
 * @method static array browseWithClients(array $where = [], ?string $search = null, ?int $limit = null)
 * @method static int close(int|string $key)
 * @method static int count(array $where = [])
 * @method static int create(array $data)
 * @method static int delete(int|string $key)
 * @method static int deleteWhere(array $where)
 * @method static ?array department(int|string $key)
 * @method static array departments(bool $visibleOnly = false)
 * @method static bool exists(array $where)
 * @method static ?array find(int|string|null $key)
 * @method static ?array first(array $where)
 * @method static ?array forClientKey(int|string $key, int $clientId)
 * @method static Model model()
 * @method static int open(array $input, string $message, string $authorType = \LBM\Action\Support::CLIENT, ?int $authorId = null)
 * @method static int openCount(?int $clientId = null)
 * @method static array priorities()
 * @method static int remove(int|string $key)
 * @method static int removeDepartment(int|string $key)
 * @method static array replies(int $ticketId, bool $includeInternal = false)
 * @method static int reply(int $ticketId, string $message, string $authorType = \LBM\Action\Support::CLIENT, ?int $authorId = null, bool $internal = false)
 * @method static int saveDepartment(array $input, int|string|null $key = null)
 * @method static int setStatus(int|string $key, int $statusId)
 * @method static ?int statusId(string $name)
 * @method static string statusTable()
 * @method static array statuses()
 * @method static int update(int|string $key, array $data)
 * @method static int updateWhere(array $where, array $data)
 */
class Support extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'action.support';
    }
}
