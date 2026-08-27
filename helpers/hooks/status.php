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
/*--------------------------------- STATUS HOOKS ---------------------------------*/
####################################################################################

/** Everything needed to render a status pill - name, label, colour, text colour */
add_hook('status_badge', 'status_badge', 1000);

/** A status name */
add_hook('status_name', 'status_name', 1000);

/** A status colour */
add_hook('status_color', 'status_color', 1000);

/** Every row in a lookup table - what a status filter dropdown is built from */
add_hook('status_list', 'status_list', 1000);

/** Resolve a status id by name */
add_hook('status_id', 'status_id', 1000);

/** Humanise a status name for display */
add_hook('status_label', 'status_label', 1000);

/** Pick readable text for a background colour */
add_hook('contrast_color', 'contrast_color', 1000);
