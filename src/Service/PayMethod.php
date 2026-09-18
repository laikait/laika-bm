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
 * A client's saved cards - Phase 48.
 *
 * @see \LBM\Action\PayMethod
 * @method static ?array defaultFor(int $clientId)
 * @method static string describe(array $row)
 * @method static ?array forClientKey(int $clientId, string $uid)
 * @method static array forClient(int $clientId, bool $usableOnly = true)
 * @method static ?array gatewayOf(array $row)
 * @method static Model model()
 * @method static array remove(int $clientId, string $uid)
 * @method static void setDefault(int $clientId, string $uid)
 * @method static int store(int $clientId, array $gateway, array $saved)
 * @method static string token(array $row)
 */
class PayMethod extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'action.pay.method';
    }
}
