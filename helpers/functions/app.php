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

use Laika\Service\Url;
use Laika\Service\Date;

####################################################################################
/*----------------------------------- IDENTITY -----------------------------------*/
####################################################################################
//
// laika-core already declares app_name() and app_host(), both reading
// lf-config/app.php. Redeclaring either would be a fatal, so LBM defines its own
// under an lbm_ prefix and wins the hook at priority 1001 - see helpers/hooks/app.php.
// Everything the operator can edit lives in `options` (instruction 14); the config
// file is only ever an installer seed source.

/**
 * App Name
 *
 * The hook chain hands each callback the previous value, so laika-core's
 * config-backed answer arrives as $previous and becomes the fallback for an
 * install whose option row was never written.
 * @param mixed $previous Value From The Previous Hook Callback
 * @return string
 */
function lbm_app_name(mixed $previous = null): string
{
    $fallback = (is_string($previous) && $previous !== '') ? $previous : 'Laika Bill Manager';

    return option('app_name', $fallback) ?: $fallback;
}

/**
 * App Host
 * @param mixed $previous Value From The Previous Hook Callback
 * @return string
 */
function lbm_app_host(mixed $previous = null): string
{
    $fallback = (is_string($previous) && $previous !== '') ? $previous : Url::base();

    return option('app_host', $fallback) ?: $fallback;
}

/**
 * App Logo URL
 *
 * Returns the URL rather than echoing it. The framework's asset() prints and
 * returns void, which makes it unusable through a hook - Hook::apply() passes
 * each callback's return value along the chain, so a void callback collapses
 * the value to null and a template renders nothing.
 * @return string
 */
function app_logo(): string
{
    return lbm_asset('assets/img/' . (option('app_logo', 'logo.png') ?: 'logo.png'));
}

/**
 * App Icon URL
 * @return string
 */
function app_icon(): string
{
    return lbm_asset('assets/img/' . (option('app_icon', 'icon.png') ?: 'icon.png'));
}

/**
 * Absolute URL For a Bundled Asset
 *
 * Absolute, not the relative "./assets/..." the framework's asset() emits: a
 * relative asset URL resolves against the current path, so the same markup that
 * works on /admin breaks on /admin/client/{uid}/edit.
 * @param string $path Path Below The App Root. Example: 'assets/css/app.css'
 * @return string
 */
function lbm_asset(string $path): string
{
    if (parse_url($path, PHP_URL_HOST)) {
        return $path;
    }

    return rtrim(Url::base(), '/') . '/' . ltrim($path, '/.');
}

####################################################################################
/*----------------------------------- TEMPLATES ----------------------------------*/
####################################################################################
//
// A template is one directory holding every area: template/<theme>/admin/,
// /client/, /install/ and /partials/. So these return the theme NAME, and the
// area is part of the view path:
//
//     (new Template(admin_template()))->view('admin/dashboard')
//
// Keeping partials in the theme rather than splitting the theme across
// template/admin/<theme>/ and template/panel/<theme>/ is what lets the admin and
// client areas share one header, one alert block and one pagination control.

/**
 * Admin Template Name
 * @return string
 */
function admin_template(): string
{
    return option('admin_template', 'bootstrap') ?: 'bootstrap';
}

/**
 * Client Area Template Name
 * @return string
 */
function panel_template(): string
{
    return option('panel_template', 'bootstrap') ?: 'bootstrap';
}

/**
 * The Template Name For The Current Area
 * @return string
 */
function current_template(): string
{
    return Url::segment(1) === PANEL ? panel_template() : admin_template();
}

####################################################################################
/*----------------------------------- FORMATTING ---------------------------------*/
####################################################################################

/**
 * Decimal Symbol
 * @return string
 */
function decimal_symbol(): string
{
    static $symbol = null;

    if ($symbol === null) {
        $symbol = option('decimal_symbol', '.') ?: '.';
    }

    return $symbol;
}

/**
 * Thousand Separator
 *
 * Defaults to a comma, not a period. Sharing the decimal symbol's default would
 * render 1234.56 as "1.234.56", which reads as neither a thousands group nor a
 * decimal.
 * @return string
 */
function thousand_separator(): string
{
    static $separator = null;

    if ($separator === null) {
        $separator = option('thousand_separator', ',') ?: ',';
    }

    return $separator;
}

/**
 * Format a Number For Display
 *
 * Display only. Arithmetic on money goes through LBM\Service\Money, which works
 * in bcmath decimal strings - see LBM\Support\Money for why.
 * @param string|float|int $amount Amount
 * @return string
 */
function decimal(string|float|int $amount): string
{
    $amount = preg_replace('/[^0-9.\-]+/', '', (string) $amount);

    return number_format((float) $amount, 2, decimal_symbol(), thousand_separator());
}

/**
 * Format a Timestamp In The App's Format and Timezone
 * @param null|string $time Timestamp
 * @return string
 */
function format_date(null|string $time): string
{
    return $time ? Date::parse($time)->format() : '';
}

####################################################################################
/*----------------------------------- LISTINGS -----------------------------------*/
####################################################################################

/**
 * Rows Per Page
 * @param ?int $default Fallback When The Option Is Unset
 * @return int
 */
function data_limit(?int $default = null): int
{
    $default = ($default && $default > 0) ? $default : 20;
    $limit = option_int('data_limit', $default);

    return $limit > 0 ? $limit : $default;
}

/**
 * Total Pages For a Row Count
 * @param int|string $totalRows Total Rows
 * @return int
 */
function total_pages(int|string $totalRows): int
{
    $totalRows = (int) $totalRows;

    return $totalRows > data_limit() ? (int) ceil($totalRows / data_limit()) : 1;
}

/**
 * Generate a UID
 *
 * RFC 4122 v4. Never use Model::uid() - it returns an 8-8-8-8 string that is not
 * a valid UUID and is rejected by drivers with a native UUID column type.
 * @return string
 */
function lbm_uid(): string
{
    return \LBM\Support\Uid::make();
}
