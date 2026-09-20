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

namespace LBM\Support;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Laika\Service\Config;
use Laika\Service\Visitor;
use Laika\Shield\Support\RateLimiter;
use Throwable;

/**
 * Login and reset throttling - Phase 52.
 *
 * Before this, both sign-in forms would check passwords for as long as anybody
 * cared to send them. laika-shield's RateLimiter already does the counting,
 * with a lock around the read-modify-write and a fail-open when its directory is
 * unwritable; what it lacks is a way to ASK whether a key is over its limit
 * without adding a hit. A lockout needs exactly that: only failures count, and a
 * form that is locked must say so before the password is even looked at.
 * wait() is that question. The upstream version belongs on RateLimiter (U8).
 *
 * Two keys per sign-in, because each stops a different attack:
 *
 *   account  what was typed, lowercased. Stops guessing one account's
 *            password from many addresses.
 *   ip       the visitor. Stops one address trying one password against many
 *            accounts, which the account key never sees.
 *
 * A successful sign-in clears the ACCOUNT key only. Clearing the IP key too
 * would let an attacker who holds one real account reset their counter at will
 * and keep guessing everybody else's.
 *
 * The price of an account key is that somebody can lock a known username out
 * for one window by failing on purpose. The window is short, and the owner can
 * still reset their password, which a lockout does not block.
 *
 * Limits come from the `login` section of lf-config/shield.php. laika-shield
 * ignores sections it does not know, so they sit beside the firewall's own.
 */
class LoginThrottle extends RateLimiter
{
    /** @var string Where The Counters Live, Below APP_PATH. Shared With LBM\Pipeline\Firewall */
    public const STORAGE = 'lf-storage/shield';

    /** @var array<string,int> Defaults, Overridden By lf-config/shield.php `login` */
    public const DEFAULTS = [
        'max.attempts'    =>  5,
        'ip.max.attempts' =>  20,
        'window'          =>  900,
        'reset.max'       =>  3,
        'reset.ip.max'    =>  10,
        'reset.window'    =>  3600,
    ];

    /** @var array<string,int> */
    private array $limits;

    public function __construct(?string $storageDir = null)
    {
        parent::__construct($storageDir ?? APP_PATH . DIRECTORY_SEPARATOR . self::STORAGE);

        $this->limits = self::DEFAULTS;

        try {
            $configured = Config::get('shield', 'login');
        } catch (Throwable) {
            $configured = null;
        }

        foreach (is_array($configured) ? $configured : [] as $key => $value) {
            if (array_key_exists($key, $this->limits) && (int) $value > 0) {
                $this->limits[$key] = (int) $value;
            }
        }
    }

    ####################################################################################
    /*==================================== SIGN IN ===================================*/
    ####################################################################################

    /**
     * Seconds Before This Sign-In May Be Tried Again, Or 0
     * @param string $area ADMIN or PANEL
     * @param string $identifier What Was Typed
     * @return int
     */
    public function lockedFor(string $area, string $identifier): int
    {
        return max(
            $this->wait($this->accountKey($area, $identifier), $this->limits['max.attempts']),
            $this->wait($this->ipKey($area), $this->limits['ip.max.attempts'])
        );
    }

    /**
     * Count a Failed Sign-In
     * @param string $area ADMIN or PANEL
     * @param string $identifier What Was Typed
     * @return void
     */
    public function failed(string $area, string $identifier): void
    {
        $window = $this->limits['window'];

        $this->tooMany($this->accountKey($area, $identifier), $this->limits['max.attempts'], $window);
        $this->tooMany($this->ipKey($area), $this->limits['ip.max.attempts'], $window);
    }

    /**
     * Clear The Account's Count After a Sign-In That Worked
     * @param string $area ADMIN or PANEL
     * @param string $identifier What Was Typed
     * @return void
     */
    public function succeeded(string $area, string $identifier): void
    {
        $this->reset($this->accountKey($area, $identifier));
    }

    ####################################################################################
    /*================================ PASSWORD RESET ================================*/
    ####################################################################################

    /**
     * Count a Reset Request, And Say Whether It May Send Mail
     *
     * Every request counts, not just failures: each one sends an email, and a
     * reset form with no limit lets anybody fill a stranger's inbox, or burn the
     * operator's sending reputation, from a script.
     * @param string $area ADMIN or PANEL
     * @param string $email Address Asked For
     * @return bool False when over either limit
     */
    public function allowReset(string $area, string $email): bool
    {
        $window = $this->limits['reset.window'];

        $account = $this->tooMany("reset:{$area}:" . $this->normalise($email), $this->limits['reset.max'], $window);
        $ip = $this->tooMany("reset:{$area}:ip:" . $this->ip(), $this->limits['reset.ip.max'], $window);

        return !$account && !$ip;
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Seconds Left On a Key That Is At Its Limit, Without Adding a Hit
     * @param string $key Counter Key
     * @param int $max Allowed Hits
     * @return int 0 when the key may be tried
     */
    private function wait(string $key, int $max): int
    {
        $data = $this->get($key);

        if ($data === null || $data['hits'] < $max) {
            return 0;
        }

        return max(0, $data['expires_at'] - time());
    }

    private function accountKey(string $area, string $identifier): string
    {
        return "login:{$area}:" . $this->normalise($identifier);
    }

    private function ipKey(string $area): string
    {
        return "login:{$area}:ip:" . $this->ip();
    }

    private function normalise(string $identifier): string
    {
        return strtolower(trim($identifier));
    }

    /**
     * The Visitor's IP - The Same Source login_logs Records
     * @return string
     */
    private function ip(): string
    {
        $ip = Visitor::ip();

        return is_string($ip) && $ip !== '' ? $ip : 'unknown';
    }
}
