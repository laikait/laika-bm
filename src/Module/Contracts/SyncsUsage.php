<?php
/**
 * Laika Bill Manager
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: Proprietary - see LICENSE
 * This file is part of Laika Bill Manager.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace LBM\Module\Contracts;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

/**
 * A server module that can report what an account is using - Phase 53.
 *
 * Optional, beside ServerInterface. Cron asks once a day, and a member of staff
 * can ask from the service screen. Each metric becomes a
 * `service_usage_records` row, which is what usage billing (Phase 66) will
 * charge from - so report what was USED, as whole numbers, in a unit named in
 * the metric: `disk_mb`, `bandwidth_mb`, `email_accounts`. Limits are not
 * usage and do not belong here.
 */
interface SyncsUsage
{
    /**
     * What The Account Is Using Now
     * @param array $service The `client_services` row
     * @param array $context See ServerInterface::create()
     * @return array{success: bool, metrics: array<string,int>, message: ?string}
     */
    public function usage(array $service, array $context = []): array;
}
