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
 * Turning a paid invoice into a service somebody can use.
 *
 * A relay forwards method calls, not constants: `Provision::BATCH` fatals here.
 * Reach them through the action.
 *
 * @see \LBM\Action\Provision
 * @method static array awaiting()
 * @method static array deliver()
 * @method static array forInvoice(int $invoiceId)
 * @method static array forOrder(array $order)
 * @method static Model model()
 * @method static array provision(array $service)
 * @method static int reconcile()
 * @method static string run()
 */
class Provision extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'action.provision';
    }
}
