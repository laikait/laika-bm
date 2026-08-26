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

add_hook('get_clients', 'get_clients', 1000);
add_hook('get_client', 'get_client', 1000);
add_hook('has_no_client', 'has_no_client', 1000);
add_hook('count_clients', 'count_clients', 1000);
add_hook('count_clients_by_query', 'count_clients_by_query', 1000);
add_hook('client_count_by_status', 'client_count_by_status', 1000);
add_hook('client_count_in_current_month', 'client_count_in_current_month', 1000);
add_hook('client_statuses', 'client_statuses', 1000);
add_hook('client_status_name_color_list', 'client_status_name_color_list', 1000);
add_hook('get_note', 'get_note', 1000);
add_hook('get_client_notes', 'get_client_notes', 1000);
add_hook('get_staff_notes', 'get_staff_notes', 1000);