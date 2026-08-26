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

add_hook('get_activity', 'get_activity', 1000);
add_hook('get_latest_activities', 'get_latest_activities', 1000);
add_hook('get_activities_by_author_type', 'get_activities_by_author_type', 1000);
add_hook('get_activities_by_author', 'get_activities_by_author', 1000);