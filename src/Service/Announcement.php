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
 * Operator news - written in the admin panel, read on the public site.
 *
 * `browse()` is the admin listing and shows everything, drafts and scheduled
 * items included. `published()` is the public one and shows neither.
 *
 * @see \LBM\Action\Announcement
 * @method static array all(array $where = [], string $direction = 'ASC', ?string $order = null)
 * @method static array browse(array $where = [], ?string $search = null, ?int $limit = null, string $direction = 'DESC')
 * @method static int count(array $where = [])
 * @method static int create(array $data)
 * @method static int delete(int|string $key)
 * @method static int deleteWhere(array $where)
 * @method static bool exists(array $where)
 * @method static ?array find(int|string|null $key)
 * @method static ?array first(array $where)
 * @method static array latest(int $limit = 3)
 * @method static Model model()
 * @method static array published(?int $limit = null)
 * @method static ?array publishedByUid(string $uid)
 * @method static int update(int|string $key, array $data)
 * @method static int updateWhere(array $where, array $data)
 */
class Announcement extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'action.announcement';
    }
}
