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
/*-------------------------------- CURRENCY HOOKS --------------------------------*/
####################################################################################

/** Every active currency, keyed by id */
add_hook('get_currencies', 'get_currencies', 1000);

/** Get one currency by id or ISO code */
add_hook('get_currency', 'get_currency', 1000);

/** The default currency - every exchange rate is quoted against it */
add_hook('get_default_currency', 'get_default_currency', 1000);

/** The default currency's ISO code */
add_hook('default_currency_code', 'default_currency_code', 1000);

/** The exchange rate between two currencies */
add_hook('get_exchange_rate', 'get_exchange_rate', 1000);

/** Convert an amount between currencies */
add_hook('convert_currency', 'convert_currency', 1000);

/** Format an amount with its currency symbols */
add_hook('money', 'money', 1000);
