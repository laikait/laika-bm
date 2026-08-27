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
/*---------------------------------- ADMIN HOOKS ---------------------------------*/
####################################################################################

/*================================= IDENTITY ==================================*/
/** The signed-in staff member */
add_hook('current_staff', 'current_staff', 1000);

/** The signed-in staff member's role id */
add_hook('current_staff_role', 'current_staff_role', 1000);

/** Whether somebody is signed into the admin area */
add_hook('is_staff', 'is_staff', 1000);

/*=================================== ACCESS ==================================*/
/**
 * Whether the signed-in staff member holds an access.
 *
 * Templates hide a button with this; LBM\Pipeline\Permission refuses the route
 * with the same underlying check, so the two cannot disagree.
 */
add_hook('staff_has_access', 'staff_has_access', 1000);

/*================================== LISTINGS =================================*/
/** Map request query keys onto database columns */
add_hook('query_to_columns', 'query_to_columns', 1000);

/*=================================== CHARTS ==================================*/
/** Build the arcs for a donut chart */
add_hook('pi_chart', 'pi_chart', 1000);
