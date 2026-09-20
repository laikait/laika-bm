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

namespace LBM\Tests\Reports;

use Laika\Service\Date;
use LBM\Controller\Admin\ReportController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Phase 51: the rolling report months are whole, distinct calendar months -
 * including when today is the 29th, 30th or 31st, where "-N months" used to
 * overflow and repeat one month while skipping another.
 */
final class MonthWindowsTest extends TestCase
{
    public static function monthEnds(): array
    {
        return [['2026-03-31 10:00:00'], ['2026-05-31 10:00:00'], ['2026-10-31 10:00:00'], ['2028-02-29 10:00:00']];
    }

    #[DataProvider('monthEnds')]
    public function testMonthsCountedBackFromAMonthEndAreDistinct(string $today): void
    {
        $labels = [];

        for ($back = 11; $back >= 0; $back--) {
            $labels[] = Date::parse($today)->modify("first day of -{$back} months")->format('Y-m');
        }

        self::assertCount(12, array_unique($labels), 'A month is repeated: ' . implode(',', $labels));
    }

    public function testTheWindowsAreContiguousWholeMonths(): void
    {
        $method = new ReflectionMethod(ReportController::class, 'lastMonths');
        $windows = $method->invoke((new ReflectionClass(ReportController::class))->newInstanceWithoutConstructor());

        self::assertCount(12, $windows);

        foreach ($windows as $i => $window) {
            self::assertStringEndsWith('-01 00:00:00', $window['start']);
            self::assertStringEndsWith(' 23:59:59', $window['end']);

            if ($i > 0) {
                $gap = strtotime($window['start']) - strtotime($windows[$i - 1]['end']);
                self::assertSame(1, $gap, 'Each month starts the second after the last one ends.');
            }
        }
    }
}
