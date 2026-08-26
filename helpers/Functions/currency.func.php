<?php

/**
 * Laika PHP MVC Framework
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: MIT
 * This file is part of the Laika PHP MVC Framework.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use LBM\Service\Currency;

/**
 * Get Currencies
 * @return array
 */
function get_currencies(): array
{
    static $currencies = null;
    if ($currencies === null) $currencies = Currency::list();
    return $currencies;
}

/**
 * Get Single Currency
 * @param int|string $entity
 * @return array
 */
function get_currency(int|string $entity): array
{
    
    static $currency = [];
    $entity = is_numeric($entity) ? (int) $entity : strtoupper($entity);
    if (!isset($currency[$entity])) $currency[$entity] = Currency::get_currency($entity);
    return $currency[$entity];
}

/**
 * Ger Default Currency
 * @return array
 */
function get_default_currency(): array
{
    static $default_currency = null;
    if ($default_currency === null) $default_currency = Currency::default();
    return $default_currency;
}

/**
 * Get Exchange Rate
 * @param int|string $from
 * @param int|string $to
 * @return string
 */
function get_exchange_rate(int|string $from, int|string $to): string
{
    $from = is_string($from) ? strtoupper($from) : $from;
    $to = is_string($to) ? strtoupper($to) : $to;

    static $exchange_rates = [];
    $key = "{$from}-{$to}";
    if (!isset($exchange_rates[$key])) $exchange_rates[$key] = Currency::get_exchange_rate($from, $to);
    return $exchange_rates[$key];
}

/**
 * Convert Currency
 * @param int|float|string $amount
 * @param int|string $from
 * @param int|string $to
 * @return string
 */
function convert_currency(int|float|string $amount, int|string $from, int|string $to): string
{
    return Currency::convert($amount, $from, $to);
}
