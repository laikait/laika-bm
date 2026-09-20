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
 * A server module that can list the accounts on a server - Phase 53.
 *
 * Optional, beside ServerInterface. The server screen shows the list beside
 * LBM's own services, so an operator can see accounts the panel has that LBM
 * does not know about, and services LBM thinks exist that the panel has lost.
 * Read-only: nothing is created, changed or imported from here.
 *
 * Anything the panel's list does not carry is null, not guessed - `suspended`
 * included (Phase 54: DirectAdmin lists names only). A guess of "active" would
 * put a false badge on the screen.
 */
interface ListsAccounts
{
    /**
     * Every Account On The Server
     * @param array $server The `servers` row, with hostname and credentials
     * @return array{
     *     success: bool,
     *     accounts: array<int, array{username: string, domain: ?string, package: ?string, suspended: ?bool}>,
     *     message: ?string
     * }
     */
    public function accounts(array $server): array;
}
