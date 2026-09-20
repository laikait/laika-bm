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
 * The optional server-module operations on a live service - Phase 53.
 *
 * @see \LBM\Action\ServiceOperation
 * @method static array accounts(array $server)
 * @method static array capabilities(array $service)
 * @method static array changePackage(array $service, int $productId)
 * @method static array changePassword(array $service, ?string $password = null)
 * @method static ?array checkServer(array $server)
 * @method static array latestUsage(int $serviceId)
 * @method static bool listsAccounts(array $server)
 * @method static array singleSignOn(array $service, string $as)
 * @method static array syncAllUsage()
 * @method static array syncUsage(array $service)
 */
class ServiceOperation extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'action.service.operation';
    }
}
