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
 * Turning a paid domain line into a name the customer actually owns.
 *
 * A relay forwards method calls, not constants: `Registration::BATCH` fatals
 * here. Reach the action directly when a constant is genuinely needed.
 *
 * @see \LBM\Action\Registration
 * @method static array awaiting()
 * @method static array deliver()
 * @method static array forInvoice(int $invoiceId)
 * @method static array forOrder(array $order)
 * @method static Model model()
 * @method static int reconcile()
 * @method static array register(array $domain)
 * @method static string run()
 */
class Registration extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'action.registration';
    }
}
