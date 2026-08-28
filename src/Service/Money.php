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
 * @method static string add(int|float|string $a, int|float|string $b)
 * @method static string sub(int|float|string $a, int|float|string $b)
 * @method static string mul(int|float|string $a, int|float|string $b)
 * @method static string div(int|float|string $a, int|float|string $b)
 * @method static string sum(array $amounts)
 * @method static string percent(int|float|string $amount, int|float|string $percent)
 * @method static int compare(int|float|string $a, int|float|string $b)
 * @method static bool isZero(int|float|string $amount)
 * @method static bool isGreater(int|float|string $a, int|float|string $b)
 * @method static string round(int|float|string $amount)
 * @method static string max(int|float|string $a, int|float|string $b)
 * @method static void flush()
 */
class Money extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'support.money';
    }
}
