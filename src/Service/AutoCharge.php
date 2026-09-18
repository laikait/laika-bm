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

namespace LBM\Service;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Laika\Relay\Relay;

/**
 * Charging a saved card without the customer there - Phase 48.
 *
 * @see \LBM\Action\AutoCharge
 * @method static array chargeNow(int $invoiceId, string $payMethodUid, string $source = 'staff')
 * @method static bool enabled()
 * @method static ?array lastAttempt(int $invoiceId)
 * @method static string run()
 */
class AutoCharge extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'action.auto.charge';
    }
}
