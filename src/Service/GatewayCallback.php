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
 * Callbacks payment gateways have sent us, and what was done about each one.
 *
 * A relay forwards method calls, not constants: `GatewayCallback::APPLIED`
 * fatals here. Reach the outcomes through the action, or through outcomes().
 *
 * @see \LBM\Action\GatewayCallback
 * @method static array all(array $where = [], string $direction = 'ASC', ?string $order = null)
 * @method static array browse(array $where = [], ?string $search = null, ?int $limit = null, string $direction = 'DESC')
 * @method static array browseRecent(array $where = [], ?int $limit = null)
 * @method static int count(array $where = [])
 * @method static int delete(int|string $key)
 * @method static bool exists(array $where)
 * @method static ?array find(int|string|null $key)
 * @method static ?array first(array $where)
 * @method static array forInvoice(int $invoiceId)
 * @method static Model model()
 * @method static string[] outcomes()
 * @method static array receive(array $gateway, array $result, array $payload = [])
 * @method static ?array seen(int $gatewayId, string $reference)
 * @method static int update(int|string $key, array $data)
 */
class GatewayCallback extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'action.gateway.callback';
    }
}
