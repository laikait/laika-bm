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
 * Ending a service: cancelling the billing, and destroying the account.
 *
 * A relay forwards method calls, not constants: `Termination::WHEN` fatals here.
 * Reach them through the action.
 *
 * @see \LBM\Action\Termination
 * @method static array cancelNow(array $service, string $reason = '')
 * @method static array dueForCancellation()
 * @method static array dueForTermination()
 * @method static bool isScheduled(array $service)
 * @method static Model model()
 * @method static int retainDays()
 * @method static string run()
 * @method static int runCancellations()
 * @method static array runTerminations()
 * @method static array schedule(array $service, string $when, string $reason = '')
 * @method static ?string scheduledFor(array $service)
 * @method static array terminate(array $service, string $reason = '')
 * @method static array unschedule(array $service)
 */
class Termination extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'action.termination';
    }
}
