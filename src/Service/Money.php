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
 * Multi-currency amounts, all arithmetic in bcmath.
 *
 * @see \LBM\Support\Money
 * @method static array all()
 * @method static array|null get(int|string|null $currency = null)
 * @method static array|null default()
 * @method static string rate(int|string|null $from, int|string|null $to)
 * @method static string convert(int|float|string $amount, int|string|null $from, int|string|null $to)
 * @method static string format(int|float|string $amount, int|string|null $currency = null)
 * @method static void flush()
 */
class Money extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'support.money';
    }
}
