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
 * A server module that can sign somebody straight into the control panel -
 * Phase 53.
 *
 * Optional, beside ServerInterface. Asked for a URL at the moment somebody
 * clicks, never earlier: these links are single-use and expire in seconds on
 * every panel that offers them, so one fetched when the page was drawn would be
 * dead by the time it was clicked. The URL is followed by a redirect and is
 * never stored.
 *
 * `$context['as']` is `client` or `staff`, for a panel that tells them apart.
 */
interface SingleSignOn
{
    /**
     * A One-Time Sign-In Link For The Account
     * @param array $service The `client_services` row
     * @param array $context See ServerInterface::create(), plus `as`
     * @return array{success: bool, url: ?string, message: ?string}
     */
    public function singleSignOn(array $service, array $context = []): array;
}
