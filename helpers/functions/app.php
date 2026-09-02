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
use LBM\Support\Version;

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
 * Product Version
 *
 * Not an option, and deliberately not settable by the operator: this describes
 * the code that is running, so the code is the only thing entitled to state it.
 * `app_name` is the operator's to change; this is not.
 * @return string
 */
function app_version(): string
{
    return Version::CURRENT;
}

/**
 * Product Name
 *
 * The software's own name, as distinct from `app_name`, which an operator
 * routinely changes to their own company. A support conversation needs both.
 * @return string
 */
function app_product(): string
{
    return Version::PRODUCT;
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
// A template is one directory per AREA: template/front/<name>/,
// template/admin/<name>/ and template/panel/<name>/, each holding its own
// layouts, partials, views and assets. The three are themed independently -
// that is the whole point, since front_template, admin_template and
// panel_template are separate options - so a template is self contained and
// shares nothing with its neighbours.
//
// The installer is template/install/ with no name level: it runs before there
// is a database to read an option out of.
//
// laika-core carries the directory in the view NAME rather than a constructor
// argument, so the composed path is what a controller renders:
//
//     (new Template())->view(template_dir(ADMIN) . '/dashboard')
//
// admin_template() and panel_template() stay NAME-only: the settings form binds
// its two selects to them, and they are registered hooks.

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
 * Public Site Template Name
 * @return string
 */
function front_template(): string
{
    return option('front_template', 'bootstrap') ?: 'bootstrap';
}

/**
 * Which Area This Request Is In
 *
 * The single place the area rule lives, and it is a THREE-way match, not a
 * binary. It reads as "not panel means admin" everywhere else in this codebase's
 * history, and that was true until the front area existed.
 *
 * ADMIN and PANEL own a URL prefix. FRONT owns no prefix at all - it is the
 * public site and it answers on `/`, so it is defined by exclusion: anything
 * that is not /admin and not /panel, the empty root segment included.
 *
 * The installer is not an area in this sense. It is a whole-app state rather
 * than a place in the URL, so GlobalPipeline::language() checks
 * Install::isInstalled() before it ever asks this.
 * @return string ADMIN, PANEL or FRONT
 */
function area(): string
{
    return match (Url::segment(1)) {
        PANEL   => PANEL,
        ADMIN   => ADMIN,
        default => FRONT,
    };
}

/**
 * The Template Directory For An Area, Below template/
 *
 * The single place the layout is encoded. Falls back to `bootstrap` when the
 * selected directory is not there: an operator can pick a template and later
 * delete it, and Twig would then fail to load every view - a blank page with no
 * hint of the cause, rather than the stock theme.
 * @param ?string $area ADMIN, PANEL or FRONT. Null uses the current request
 * @return string Example: 'admin/bootstrap'
 */
function template_dir(?string $area = null): string
{
    $area = $area ?? area();

    $name = match ($area) {
        PANEL   => panel_template(),
        FRONT   => front_template(),
        default => admin_template(),
    };

    // Not $area directly: an unrecognised value would compose a path that cannot
    // exist and fall through to bootstrap under a directory nothing renders from.
    $area = match ($area) {
        PANEL   => PANEL,
        FRONT   => FRONT,
        default => ADMIN,
    };

    if (!is_dir(APP_PATH . DS . 'template' . DS . $area . DS . $name)) {
        $name = 'bootstrap';
    }

    return $area . '/' . $name;
}

/**
 * The Template Directory For The Current Area
 * @return string
 */
function current_template(): string
{
    return template_dir();
}

####################################################################################
/*----------------------------------- LANGUAGE -----------------------------------*/
####################################################################################

/**
 * Language Codes That Are Fully Translated
 *
 * A catalogue is per area, so a language is only offered once ALL FOUR have one -
 * admin, panel, front and install.
 * Half a translation is not a cosmetic gap here - local() throws on a key it
 * cannot find, so an area with no catalogue is a white screen, and the Settings
 * dropdown is the only thing standing between an operator and that.
 *
 * Keyed code => label, ready for a select. The label is the bare code upper-cased:
 * naming the language in its own tongue would mean shipping a name table that goes
 * stale the moment somebody adds a locale we have never heard of.
 * @return array<string,string>
 */
function language_choices(): array
{
    $areas = [ADMIN, PANEL, FRONT, 'install'];
    $choices = [];

    foreach (glob(LANG_PATH . DS . ADMIN . DS . '*.local.php') ?: [] as $path) {
        $code = basename($path, '.local.php');

        foreach ($areas as $area) {
            if (!is_file(LANG_PATH . DS . $area . DS . $code . '.local.php')) {
                continue 2;
            }
        }

        $choices[$code] = strtoupper($code);
    }

    return $choices !== [] ? $choices : ['en' => 'EN'];
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

/**
 * Format a Timestamp As a Date, With No Time
 *
 * An invoice due date, a renewal date and a registration date are all days, not
 * moments - printing "2026-08-27 00:00" next to one is noise. Uses the
 * `date_format` option, which is what that setting is for.
 * @param null|string $time Timestamp
 * @return string
 */
function format_day(null|string $time): string
{
    if (!$time) {
        return '';
    }

    return Date::parse($time)->format(option('date_format', 'Y-m-d') ?: 'Y-m-d');
}

/**
 * Format a Timestamp As a Time, With No Date
 *
 * For a list of things that all happened today - ticket replies, activity on a
 * dashboard - where repeating the date on every row tells the reader nothing.
 * @param null|string $time Timestamp
 * @return string
 */
function format_time(null|string $time): string
{
    if (!$time) {
        return '';
    }

    return Date::parse($time)->format(option('time_format', 'H:i') ?: 'H:i');
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
