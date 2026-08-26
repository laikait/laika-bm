<?php

declare(strict_types=1);

use Laika\Service\Url;
use Laika\Service\Math;
use Laika\Service\Date;


/**
 * Decimal Symbol
 * @return string
 */
function decimal_symbol(): string
{
    static $symbol = null;
    if ($symbol === null) $symbol = option('decimal_symbol', '.');
    return $symbol;
}

/**
 * Thousand Separator
 * @return string
 */
function thousand_separator(): string
{
    static $separator = null;
    if ($separator === null) $separator = option('thousand_separator', '.');
    return $separator;
}

/**
 * Decimal Format
 * @param string|float|int $amount
 * @return string
 */
function decimal(string|float|int $amount): string
{
    $amount = preg_replace('/[^0-9.\-]+/i', '', (string) $amount);
    return number_format((float) $amount, 2, decimal_symbol(), thousand_separator());
};

/**
 * To App Date Format
 * @param null|string $time
 * @return string
 */
function format_date(null|string $time): string
{
    return $time ? Date::parse($time)->format() : '';
};

/**
 * Admin template Name
 * @return string
 * */
function admin_template(): string
{
    return 'admin/' . option('admin_template', 'bootstrap');
};

/**
 * App Icon
 * @return void
 */
function app_icon(): void
{
    $name = option('app_icon', 'icon.png');
    asset("assets/img/{$name}");
};

/**
 * App Logo
 * @return void
 */
function app_logo(): void
{
    $name = option('app_logo', 'logo.png');
    asset("assets/img/{$name}");
};

/**
 * Get App Host
 * @return string
 */
function app_uri()
{
    return option('app_host', Url::base());
};

/**
 * Get Data Limit
 * @param ?int $default = null
 * @return int
 */
function data_limit(?int $default = null): int
{
    $default = ($default && ($default > 0)) ? $default : 20;
    return option_int('data_limit', $default);
}

/**
 * Get Total Pages
 * @param int|string $totalRows
 * @return int
 */
function total_pages(int|string $totalRows): int
{
    $totalRows = (int) $totalRows;
    return $totalRows > data_limit() ? (int) ceil($totalRows / data_limit()) : 1;
}
