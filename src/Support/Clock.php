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

namespace LBM\Support;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Throwable;
use Laika\Service\Date;
use Laika\Model\Connection;

/**
 * Puts the PHP clock and the database clock on the operator's timezone.
 *
 * This exists because there is more than one way into the application and only
 * one of them was setting the clock.
 *
 * A web request runs GlobalPipeline, which reads `time_zone` from the options
 * table, sets PHP's default timezone to it, and sends the matching offset to
 * the database session. A queue worker runs no pipeline at all: `php worker`
 * boots, calls Init::db(), and starts popping jobs. So the worker was left on
 * whatever php.ini says and on the database's own session default.
 *
 * That is not cosmetic. Every date column in this application is a TIMESTAMP,
 * and MySQL converts a TIMESTAMP on the way in and on the way out using the
 * session timezone - so a row written by a web request and read by a worker two
 * hours adrift comes back two hours out. InvoiceReminderJob decides which
 * invoices are due today; PruneTokensJob decides which tokens have expired.
 * Both would be wrong by the host's offset from the operator's timezone, and
 * wrong quietly: a reminder a day early looks like a reminder, not like a bug.
 *
 * So every entry point that is not a web request calls apply() before doing
 * anything with a date.
 */
class Clock
{
    /** @var string The Timezone Used When The Operator Has Not Chosen One */
    public const FALLBACK = 'UTC';

    /** @var bool Whether This Process Has Already Been Set */
    private static bool $applied = false;

    /**
     * Put This Process On The Operator's Timezone
     *
     * Safe to call more than once and safe to call early: a failure to read the
     * option - during install, or before the database exists - falls back to UTC
     * rather than throwing, because a job that cannot read a setting should
     * still run on a defensible clock rather than not run at all.
     * @param bool $force Re-apply even if it has already been done
     * @return string The timezone that was applied
     */
    public static function apply(bool $force = false): string
    {
        if (self::$applied && !$force) {
            return Date::getAppTimezone();
        }

        $timezone = self::FALLBACK;

        try {
            $configured = option('time_zone', self::FALLBACK);

            if (is_string($configured) && trim($configured) !== '') {
                $timezone = trim($configured);
            }
        } catch (Throwable) {
            // No database yet, or no options table. UTC it is.
        }

        try {
            Date::setAppTimezone($timezone);
        } catch (Throwable) {
            // An option holding a timezone PHP does not recognise. Rather than
            // let the process run on an unknown clock, fall back explicitly.
            $timezone = self::FALLBACK;
            Date::setAppTimezone($timezone);
        }

        try {
            Date::setFormat(option('datetime_format', 'Y-m-d H:i:s') ?: 'Y-m-d H:i:s');
        } catch (Throwable) {
            Date::setFormat('Y-m-d H:i:s');
        }

        // The database half. Without this the PHP clock is right and the
        // database clock is not, which is the worse of the two failures -
        // everything looks correct until a stored timestamp is read back.
        try {
            Connection::applyTimezone(Date::getOffset());
        } catch (Throwable) {
            // No connection registered yet. The caller is expected to have
            // opened one; if it has not, nothing here has written a date yet
            // either.
        }

        self::$applied = true;

        return $timezone;
    }

    /**
     * Forget That This Process Has Been Set
     *
     * For a long-running worker that should pick up an operator's change of
     * timezone rather than holding the one it booted with.
     * @return void
     */
    public static function flush(): void
    {
        self::$applied = false;
    }
}
