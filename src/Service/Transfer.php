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
 * Bringing a domain in from another registrar.
 *
 * A relay forwards method calls, not constants: `Transfer::MAX_ATTEMPTS` fatals
 * here. Reach it through the action.
 *
 * @see \LBM\Action\Transfer
 * @method static int attemptsOn(array $domain)
 * @method static ?string authCode(array $domain)
 * @method static array awaiting()
 * @method static bool complete(int $domainId, ?string $expiry = null)
 * @method static array deliver()
 * @method static ?array find(int|string|null $key)
 * @method static array inFlight()
 * @method static Model model()
 * @method static bool needsCode(array $domain)
 * @method static string run()
 * @method static array submit(array $domain)
 * @method static array waitingForCode()
 * @method static bool wasSubmitted(array $domain)
 */
class Transfer extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'action.transfer';
    }
}
