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
 * The outgoing email queue.
 *
 * @see \LBM\Action\Mail
 * @method static array all(array $where = [], string $direction = 'ASC', ?string $order = null)
 * @method static array browse(array $where = [], ?string $search = null, ?int $limit = null, string $direction = 'DESC')
 * @method static int count(array $where = [])
 * @method static int create(array $data)
 * @method static int delete(int|string $key)
 * @method static int deleteWhere(array $where)
 * @method static bool exists(array $where)
 * @method static ?array find(int|string|null $key)
 * @method static ?array first(array $where)
 * @method static int markFailed(int $queueId, string $error)
 * @method static int markSent(int $queueId)
 * @method static Model model()
 * @method static array pending(int $limit = 25)
 * @method static int prune(int $days)
 * @method static int queue(array $message)
 * @method static int queueTemplate(string $slug, string $to, array $variables = [], ?int $clientId = null)
 * @method static int queueTest(string $to)
 * @method static int retry(int|string $key)
 * @method static bool send(array $row)
 * @method static ?int statusId(string $name)
 * @method static string statusTable()
 * @method static array statuses()
 * @method static int update(int|string $key, array $data)
 * @method static int updateWhere(array $where, array $data)
 */
class Mail extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'action.mail';
    }
}
