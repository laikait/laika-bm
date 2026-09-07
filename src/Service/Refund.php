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
 * Giving money back - and actually telling the processor about it.
 *
 * A relay forwards method calls, not constants: `Refund::GATEWAY` fatals here.
 * Reach the method names through the action.
 *
 * @see \LBM\Action\Refund
 * @method static int byHand(int|string $key, int|float|string|null $amount = null, string $reason = '')
 * @method static ?array gatewayFor(array $payment)
 * @method static ?string gatewayRefusal(array $payment)
 * @method static string receivedOn(array $invoice)
 * @method static string refundableOn(array $payment)
 * @method static string refundedOn(int $invoiceId)
 * @method static bool restate(int $invoiceId)
 * @method static int throughGateway(int|string $key, int|float|string|null $amount = null, string $reason = '')
 * @method static int toCredit(int|string $key, int|float|string|null $amount = null, string $reason = '')
 */
class Refund extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'action.refund';
    }
}
