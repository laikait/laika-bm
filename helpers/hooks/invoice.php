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

add_hook('get_invoices', 'get_invoices', 1000);
add_hook('get_invoice', 'get_invoice', 1000);
add_hook('group_by_status', 'group_by_status', 1000);
add_hook('total_spent_by_client', 'total_spent_by_client', 1000);
add_hook('total_outstanding_by_client', 'total_outstanding_by_client', 1000);
add_hook('client_invoices', 'client_invoices', 1000);