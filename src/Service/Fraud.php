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
 * Screening an order at checkout, and which fraud module does it.
 *
 * A relay forwards method calls, not constants: `Fraud::NONE` fatals here.
 * `chosen()` answers null for none, which is all a caller needs.
 *
 * @see \LBM\Action\Fraud
 * @method static array screen(int|string $key)
 * @method static string choose(string $module)
 * @method static ?string chosen()
 * @method static ?string installedModule(string $module)
 * @method static array modules()
 * @method static ?string name()
 * @method static string state()
 */
class Fraud extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'action.fraud';
    }
}
