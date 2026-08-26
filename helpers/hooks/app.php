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

/*============================= OPTION HOOKS =============================*/
// /** Get App DB Option */
add_hook('option', 'option', 1000);

/** Get App DB Option as Int */
add_hook('option_int', 'option_int', 1000);

/** Get App DB Option as Bool */
add_hook('option_bool', 'option_bool', 1000);

/*============================= APP HOOKS =============================*/
/** Get App Host */
add_hook('app_host', 'app_uri', 1000);

/** Get App Name */
add_hook('app_name', 'app_name', 1000);

/** App Logo */
add_hook('app_logo', 'app_logo', 1000);

/** App Icon */
add_hook('app_icon', 'app_icon', 1000);

/*========================== TEMPLATE FILTERS ==========================*/
/** Admin template Name */
add_hook('admin_template', 'admin_template', 1000);

/** Pi Chart in Template */
add_hook('pi_chart', 'pi_chart', 1000);

/** Get Data Limit */
add_hook('data_limit', 'data_limit', 1000);

/** Total Pages */
add_hook('total_pages', 'total_pages', 1000);

/*========================== ADMIN FILTERS ==========================*/
/**
 * Check Staff Has Access
 */
add_hook('staff_has_access', 'staff_has_access', 1000);

/*=========================== DATE FILTERS ===========================*/
/** To App Date Format */
add_hook('date_format', 'format_date', 1000);

/*=========================== AMOUNT FILTERS ===========================*/
/** Default Currency */
add_hook('currency', 'default_currency', 1000);
/** Decimal Format */
add_hook('decimal', 'decimal', 1000);
/** Format Currency */
add_hook('format_currency', 'format_currency', 1000);
/** Decimal Symbol */
add_hook('decimal_symbol', 'decimal_symbol', 1000);
/** Thousand Separator */
add_hook('thousand_separator', 'thousand_separator', 1000);