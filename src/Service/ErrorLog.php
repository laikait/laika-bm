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

use Laika\Model\Model;
use Laika\Relay\Relay;

/**
 * Reading the error log.
 *
 * The WRITER is `LBM\Support\ErrorLog` and is deliberately not behind a relay:
 * it is called from inside an exception handler and from a shutdown function,
 * where resolving a container entry is one more thing that can fail at the
 * worst possible moment.
 *
 * @see \LBM\Action\ErrorLog
 * @method static array browse(array $where = [], ?string $search = null, ?int $limit = null, string $direction = 'DESC')
 * @method static array browseLog(?string $source = null, ?string $level = null, ?string $search = null, ?int $limit = null)
 * @method static int count(array $where = [])
 * @method static array countsBySource()
 * @method static int delete(int|string $key)
 * @method static int deleteWhere(array $where)
 * @method static bool exists(array $where)
 * @method static ?array find(int|string|null $key)
 * @method static ?array first(array $where)
 * @method static ?string lastAt()
 * @method static Model model()
 * @method static string prune()
 * @method static int retentionDays()
 */
class ErrorLog extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'action.errorlog';
    }
}
