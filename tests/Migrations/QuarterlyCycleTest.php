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

namespace LBM\Tests\Migrations;

use LBM\Migration\M202609190200AddQuarterlyCycle;
use LBM\Model\BillingCycleModel;
use LBM\Tests\TestCase;

/**
 * Phase 50: quarterly is seeded on a fresh install and added to an old one.
 */
final class QuarterlyCycleTest extends TestCase
{
    public function testAFreshInstallHasItAndTheMigrationIsNotNeeded(): void
    {
        self::assertSame(1, $this->quarterly());
        self::assertFalse((new M202609190200AddQuarterlyCycle())->applies());
    }

    public function testAnOlderInstallGetsItOnce(): void
    {
        (new BillingCycleModel())->where(['billing_cycle_name' => 'quarterly'])->delete();
        $migration = new M202609190200AddQuarterlyCycle();

        self::assertTrue($migration->applies());
        $migration->run();

        self::assertSame(1, $this->quarterly());
        self::assertFalse($migration->applies());
    }

    private function quarterly(): int
    {
        return (new BillingCycleModel())->where(['billing_cycle_name' => 'quarterly'])->count();
    }
}
