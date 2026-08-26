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

add_hook('get_currencies', 'get_currencies');
add_hook('get_currency', 'get_currency');
add_hook('get_default_currency', 'get_default_currency');
add_hook('get_exchange_rate', 'get_exchange_rate');
add_hook('convert_currency', 'convert_currency');