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
 * A server module that can move an account to another package - Phase 53.
 *
 * Optional, beside ServerInterface. LBM asks `instanceof ChangesPackage` and
 * shows the staff button only when the answer is yes.
 *
 * This is the module half only: tell the control panel. What the change costs
 * - proration, an invoice, a credit - is Phase 58's, and happens before this is
 * called. Like every server call it may be retried, so moving an account to
 * the package it is already on must report success.
 */
interface ChangesPackage
{
    /**
     * Move The Account To The New Product's Package
     * @param array $service The `client_services` row
     * @param array $context See ServerInterface::create(); `product` is the NEW
     *        product and `previous` the one the service was on
     * @return array{success: bool, message: ?string, raw: array}
     */
    public function changePackage(array $service, array $context = []): array;
}
