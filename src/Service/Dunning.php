<?php
/**
 * Laika Bill Manager
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: MIT
 * This file is part of Laika Bill Manager.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace LBM\Service;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Laika\Relay\Relay;

/**
 * Suspending a service for non-payment, and bringing it back.
 *
 * A relay forwards method calls, not constants: `Dunning::BY_STAFF` fatals here.
 * Reach them through the action, or through the accessor methods beside them.
 *
 * @see \LBM\Action\Dunning
 * @method static array delinquent(int $serviceId)
 * @method static bool enabled()
 * @method static int forInvoice(int $invoiceId)
 * @method static int graceDays()
 * @method static bool isSuspendedByUs(array $service)
 * @method static ?array lastFailure(array $service)
 * @method static Model model()
 * @method static array overdue()
 * @method static array restorable()
 * @method static array restoreAll()
 * @method static bool onHold(array $service)
 * @method static array restoreService(array $service, bool $hold = false)
 * @method static string run()
 * @method static array suspendAll()
 * @method static array suspendService(array $service, string $reason = '', string $by = 'dunning')
 */
class Dunning extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'action.dunning';
    }
}
