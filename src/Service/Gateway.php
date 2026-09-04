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
 * The payment gateways this installation can take money through.
 *
 * A relay forwards method calls, not constants: `Gateway::TYPE` fatals here.
 * Reach the module type through the action, or add an accessor - the convention
 * Support::ratings() and Action\Module::types() already follow.
 *
 * @see \LBM\Action\Gateway
 * @method static int add(array $input)
 * @method static int activate(int|string $key, bool $active)
 * @method static array all(array $where = [], string $direction = 'ASC', ?string $order = null)
 * @method static int count(array $where = [])
 * @method static int delete(int|string $key)
 * @method static ?\LBM\Module\Contracts\GatewayInterface driverFor(array $row)
 * @method static string[] drivers()
 * @method static bool exists(array $where)
 * @method static ?array find(int|string|null $key)
 * @method static ?array first(array $where)
 * @method static Model model()
 * @method static array payable()
 * @method static ?array payableBySlug(string $slug)
 * @method static ?string problemWith(array $row)
 * @method static int putSettings(int|string $key, array $settings)
 * @method static array settings(array $row)
 * @method static string[] unconfigured()
 * @method static int update(int|string $key, array $data)
 */
class Gateway extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'action.gateway';
    }
}
