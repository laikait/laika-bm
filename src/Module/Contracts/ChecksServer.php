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
 * A server module that can check its credentials against a server - Phase 54.
 *
 * Optional, beside ServerInterface. Check on the servers screen always opens
 * the port; that says the machine is there, not that the API token typed in is
 * right. A module implementing this is asked next, with a call that changes
 * nothing, so a wrong token is found on the servers screen rather than by the
 * first customer whose account is never created.
 */
interface ChecksServer
{
    /**
     * Make One Harmless Authenticated Call
     * @param array $server The `servers` row, with hostname and credentials
     * @return array{success: bool, message: string}
     */
    public function checkServer(array $server): array;
}
