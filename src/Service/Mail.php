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
 * Mail, configured from the mail_* options.
 *
 * Resolved lazily - nothing reads the options table until something sends.
 *
 * @see \LBM\Mail\MailerFactory
 * @method static \Laika\Mailman\Mailer mailer()
 * @method static void flush()
 */
class Mail extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'mailer';
    }
}
