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

namespace LBM\Tests\Security;

use LBM\Support\LoginThrottle;
use LBM\Tests\TestCase;

/**
 * Phase 52: only failures count, a lock is reported before any password is
 * checked, and a success clears the account's count but not the address's.
 * On a throwaway directory - never the app's live counters.
 */
final class LoginThrottleTest extends TestCase
{
    private string $dir;
    private LoginThrottle $throttle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/lbm-throttle-' . bin2hex(random_bytes(4));
        $this->throttle = new LoginThrottle($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/laika_shield_rl/*') ?: [] as $file) {
            unlink($file);
        }

        @rmdir($this->dir . '/laika_shield_rl');
        @rmdir($this->dir);

        parent::tearDown();
    }

    public function testTheSixthTryIsLockedAndNotBefore(): void
    {
        for ($i = 0; $i < LoginThrottle::DEFAULTS['max.attempts']; $i++) {
            self::assertSame(0, $this->throttle->lockedFor(ADMIN, 'Alice'));
            $this->throttle->failed(ADMIN, 'Alice');
        }

        $wait = $this->throttle->lockedFor(ADMIN, 'alice ');

        self::assertGreaterThan(0, $wait, 'The account key ignores case and spaces.');
        self::assertLessThanOrEqual(LoginThrottle::DEFAULTS['window'], $wait);
    }

    public function testAreasAreCountedSeparately(): void
    {
        for ($i = 0; $i < LoginThrottle::DEFAULTS['max.attempts']; $i++) {
            $this->throttle->failed(ADMIN, 'alice');
        }

        self::assertSame(0, $this->throttle->lockedFor(PANEL, 'alice'));
    }

    public function testSuccessClearsTheAccountButNotTheAddress(): void
    {
        $ipMax = LoginThrottle::DEFAULTS['ip.max.attempts'];

        // One address working through many accounts, one guess each...
        for ($i = 0; $i < $ipMax - 1; $i++) {
            $this->throttle->failed(PANEL, "victim{$i}");
        }

        // ...then signing in to its own account and trying once more.
        $this->throttle->failed(PANEL, 'mine');
        $this->throttle->succeeded(PANEL, 'mine');

        self::assertGreaterThan(0, $this->throttle->lockedFor(PANEL, 'somebody-new'));
    }

    public function testResetMailIsCappedPerAddress(): void
    {
        for ($i = 0; $i < LoginThrottle::DEFAULTS['reset.max']; $i++) {
            self::assertTrue($this->throttle->allowReset(PANEL, 'a@example.test'));
        }

        self::assertFalse($this->throttle->allowReset(PANEL, 'A@example.test'));
        self::assertTrue($this->throttle->allowReset(PANEL, 'b@example.test'));
    }
}
