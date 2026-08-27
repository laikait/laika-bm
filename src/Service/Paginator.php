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
 * Keyset pagination.
 *
 * @see \LBM\Support\Paginator
 * @method static array page(\Laika\Model\Model $model, ?int $limit = null, string $direction = 'ASC')
 * @method static ?string nextUrl(?int $next)
 * @method static ?int cursor()
 */
class Paginator extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'support.paginator';
    }
}
