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

####################################################################################
/*----------------------------------- APP HOOKS ----------------------------------*/
####################################################################################
//
// Hook::apply() ksorts by priority and threads the running value through every
// callback, so the HIGHEST priority number runs last and decides the answer.
//
// laika-core registers `app_name` and `app_host` at 1000, pointing at its own
// config-backed helpers. LBM registers at 1001 to override them with the
// option-backed versions (instruction 14). Registering at the same priority
// would not do it - the winner would then depend on package load order, which
// composer does not guarantee.
//
// Hook names laika-core does not claim are registered at 1000 as usual.

/*================================ APP OPTIONS ================================*/
/** Get an option */
add_hook('option', 'option', 1000);

/** Get an option as int */
add_hook('option_int', 'option_int', 1000);

/** Get an option as bool */
add_hook('option_bool', 'option_bool', 1000);

/*================================= IDENTITY ==================================*/
/** App name - overrides laika-core's config-backed app_name() */
add_hook('app_name', 'lbm_app_name', 1001);

/** App host - overrides laika-core's config-backed app_host() */
add_hook('app_host', 'lbm_app_host', 1001);

/** App logo URL */
add_hook('app_logo', 'app_logo', 1000);

/** App icon URL */
add_hook('app_icon', 'app_icon', 1000);

/** Absolute URL for a bundled asset */
add_hook('lbm_asset', 'lbm_asset', 1000);

/*================================= TEMPLATES =================================*/
/** Admin template name */
add_hook('admin_template', 'admin_template', 1000);

/** Client area template name */
add_hook('panel_template', 'panel_template', 1000);

/** Template directory for an area, below template/ */
add_hook('template_dir', 'template_dir', 1000);

/** Template directory for the current area */
add_hook('current_template', 'current_template', 1000);

/*================================ FORMATTING =================================*/
/** Format a number for display */
add_hook('decimal', 'decimal', 1000);

/** Decimal symbol */
add_hook('decimal_symbol', 'decimal_symbol', 1000);

/** Thousand separator */
add_hook('thousand_separator', 'thousand_separator', 1000);

/** Format a timestamp in the app's format and timezone */
add_hook('format_date', 'format_date', 1000);

/** Format a timestamp as a date, with no time */
add_hook('format_day', 'format_day', 1000);

/** Format a timestamp as a time, with no date */
add_hook('format_time', 'format_time', 1000);

/*================================= LISTINGS ==================================*/
/** Rows per page */
add_hook('data_limit', 'data_limit', 1000);

/** Total pages for a row count */
add_hook('total_pages', 'total_pages', 1000);

/** Generate a UID */
add_hook('lbm_uid', 'lbm_uid', 1000);

/*================================= LANGUAGE ==================================*/
/** Language codes that are fully translated */
add_hook('language_choices', 'language_choices', 1000);
