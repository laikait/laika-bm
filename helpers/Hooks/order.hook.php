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

add_hook('get_orders', 'get_orders', 1000);
add_hook('get_order', 'get_order', 1000);
add_hook('get_client_orders', 'get_client_orders', 1000);
add_hook('count_orders', 'count_orders', 1000);
add_hook('count_orders_by_query', 'count_orders_by_query', 1000);
add_hook('order_statuses', 'order_statuses', 1000);