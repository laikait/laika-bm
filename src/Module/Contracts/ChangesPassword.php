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
 * A server module that can set an account's password - Phase 53.
 *
 * Optional, beside ServerInterface. The password arrives in the clear and LBM
 * stores it encrypted with ClientService::setCredential() once this reports
 * success - never write it anywhere yourself, and never log it.
 */
interface ChangesPassword
{
    /**
     * Set The Account's Password On The Server
     * @param array $service The `client_services` row
     * @param string $password The new password, in the clear
     * @param array $context See ServerInterface::create()
     * @return array{success: bool, message: ?string, raw: array}
     */
    public function changePassword(array $service, string $password, array $context = []): array;
}
