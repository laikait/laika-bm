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
 * Keeping a domain, and losing it.
 *
 * A relay forwards method calls, not constants: `DomainRenewal::GRACE_DAYS`
 * fatals here. Reach the action directly when a constant is genuinely needed.
 *
 * @see \LBM\Action\DomainRenewal
 * @method static array awaiting()
 * @method static array deliver()
 * @method static array expire()
 * @method static Model model()
 * @method static array renew(array $domain, string $periodEnd, int $years = 1)
 * @method static string run()
 */
class DomainRenewal extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'action.domain.renewal';
    }
}
