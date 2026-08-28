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

namespace LBM\Job;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Laika\Model\Model;
use Laika\Queue\Abstracts\Job;
use LBM\Model\PasswordResetModel;

/**
 * Clears out expired credentials.
 *
 * Nothing in laika-auth reaps `auth_tokens`: issueToken() inserts and revoke()
 * only stamps revoked_at, so on a busy install the table grows by a row per
 * sign-in forever and every validateToken() lookup pays for it. Same story for
 * spent password-reset rows.
 *
 * Rows are kept for a grace period rather than deleted the moment they expire,
 * because "when did this session end" is a question worth being able to answer
 * for a little while after it does.
 */
class PruneTokensJob extends Job
{
    /** @var string Queue Name */
    public string $queue = 'default';

    /** @var int Retries */
    public int $maxTries = 2;

    /** @var int Days An Expired Or Revoked Row Is Kept */
    public const GRACE_DAYS = 7;

    /** @var int Days To Keep */
    private int $days;

    /**
     * @param ?int $days Days To Keep. Null uses GRACE_DAYS
     */
    public function __construct(?int $days = null)
    {
        $this->days = $days !== null && $days > 0 ? $days : self::GRACE_DAYS;
    }

    /**
     * Run The Job
     * @return void
     */
    public function handle(): void
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$this->days} days"));

        $this->pruneAuthTokens($cutoff);
        $this->pruneResets($cutoff);
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Delete Long-Dead Session Tokens
     *
     * Two passes rather than one OR'd condition: a NULL expires_at means "never
     * expires", so a single WHERE mixing the two would need care to avoid
     * deleting live tokens that simply have no expiry. Two narrow statements are
     * easier to be sure about than one clever one.
     * @param string $cutoff Cutoff Timestamp
     * @return void
     */
    private function pruneAuthTokens(string $cutoff): void
    {
        // Expired: expires_at is set and is in the past.
        (new Model())->table('auth_tokens')
            ->notNull('expires_at')
            ->where(['expires_at' => $cutoff], '<')
            ->delete();

        // Revoked: signed out deliberately, and long enough ago.
        (new Model())->table('auth_tokens')
            ->notNull('revoked_at')
            ->where(['revoked_at' => $cutoff], '<')
            ->delete();
    }

    /**
     * Delete Spent And Expired Password Resets
     * @param string $cutoff Cutoff Timestamp
     * @return void
     */
    private function pruneResets(string $cutoff): void
    {
        (new PasswordResetModel())
            ->notNull('used_at')
            ->where(['used_at' => $cutoff], '<')
            ->delete();

        (new PasswordResetModel())
            ->where(['expires_at' => $cutoff], '<')
            ->delete();
    }
}
