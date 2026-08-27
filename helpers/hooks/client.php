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
/*--------------------------------- CLIENT HOOKS ---------------------------------*/
####################################################################################

/*================================= IDENTITY ==================================*/
/** The signed-in client */
add_hook('current_client', 'current_client', 1000);

/** The signed-in client contact, when the session belongs to a sub-login */
add_hook('current_contact', 'current_contact', 1000);

/** Whether somebody is signed into the client area */
add_hook('is_client', 'is_client', 1000);

/*=================================== NAMES ===================================*/
/** A client's display name - company first, then the person */
add_hook('client_name', 'client_name', 1000);

/** A client's personal name, ignoring the company */
add_hook('client_person_name', 'client_person_name', 1000);

/*================================== BILLING ==================================*/
/** A client's credit balance, formatted in their own currency */
add_hook('client_balance', 'client_balance', 1000);

/** A client's preferred currency id */
add_hook('client_currency', 'client_currency', 1000);
