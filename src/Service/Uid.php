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
 * RFC 4122 v4 identifiers for the `uid` column every LBM table carries.
 *
 * @see \LBM\Support\Uid
 * @method static string make()
 * @method static bool isValid(mixed $uid)
 * @method static array stamp(array $rows)
 */
class Uid extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'support.uid';
    }
}
