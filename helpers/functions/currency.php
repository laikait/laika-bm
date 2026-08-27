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

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use LBM\Service\Money;

####################################################################################
/*----------------------------------- CURRENCIES ---------------------------------*/
####################################################################################
//
// Thin wrappers over LBM\Support\Money so templates can reach multi-currency
// support through the |hook() filter. Every arithmetic step inside Money is
// bcmath over decimal strings - see LBM\Support\Money for why money never
// becomes a float.

/**
 * Every Active Currency, Keyed By Id
 * @return array<int,array>
 */
function get_currencies(): array
{
    return Money::all();
}

/**
 * Get One Currency
 * @param int|string|null $currency Currency Id or ISO code. Null returns the default
 * @return ?array
 */
function get_currency(int|string|null $currency = null): ?array
{
    return Money::get($currency);
}

/**
 * The Default Currency
 * @return ?array
 */
function get_default_currency(): ?array
{
    return Money::default();
}

/**
 * The Default Currency's ISO Code
 * @return string
 */
function default_currency_code(): string
{
    return (string) (Money::default()['currency_code'] ?? '');
}

/**
 * The Exchange Rate Between Two Currencies
 * @param int|string|null $from Source Currency
 * @param int|string|null $to Target Currency
 * @return string Decimal string
 */
function get_exchange_rate(int|string|null $from, int|string|null $to): string
{
    return Money::rate($from, $to);
}

/**
 * Convert an Amount Between Currencies
 * @param int|float|string $amount Amount In The Source Currency
 * @param int|string|null $from Source Currency
 * @param int|string|null $to Target Currency
 * @return string Decimal string
 */
function convert_currency(int|float|string $amount, int|string|null $from, int|string|null $to): string
{
    return Money::convert($amount, $from, $to);
}

/**
 * Format an Amount With Its Currency Symbols
 * @param int|float|string $amount Amount
 * @param int|string|null $currency Currency Id or ISO code. Null uses the default
 * @return string
 */
function money(int|float|string $amount, int|string|null $currency = null): string
{
    return Money::format($amount, $currency);
}
