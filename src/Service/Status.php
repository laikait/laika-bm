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
 * The status lookup tables - name and colour for every *_relid.
 *
 * @see \LBM\Support\Status
 * @method static array|null get(string $table, int|string|null $id)
 * @method static string name(string $table, int|string|null $id, string $default = '')
 * @method static string color(string $table, int|string|null $id)
 * @method static array all(string $table)
 * @method static int|null idOf(string $table, string $name)
 * @method static void flush(?string $table = null)
 */
class Status extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'support.status';
    }
}
