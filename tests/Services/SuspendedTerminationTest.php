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

namespace LBM\Tests\Services;

use Laika\Service\Uid;
use LBM\Action\Setting;
use LBM\Action\Termination;
use LBM\Model\ClientServiceModel;
use LBM\Service\Status;
use LBM\Tests\TestCase;

/**
 * Phase 50: only services DUNNING suspended, long enough ago, are terminated -
 * and only once the operator sets a retention period.
 */
final class SuspendedTerminationTest extends TestCase
{
    private int $old;
    private int $fresh;
    private int $byStaff;

    protected function setUp(): void
    {
        parent::setUp();

        $clientId = $this->client();
        $this->old = $this->suspended($clientId, 'dunning', 40);
        $this->fresh = $this->suspended($clientId, 'dunning', 10);
        $this->byStaff = $this->suspended($clientId, 'staff', 40);
    }

    public function testOffByDefault(): void
    {
        (new Setting())->put('terminate_suspended_days', 0);

        self::assertSame([], $this->picked());
    }

    public function testOnlyLongDunningSuspensionsArePicked(): void
    {
        (new Setting())->put('terminate_suspended_days', 30);

        $picked = $this->picked();

        self::assertContains($this->old, $picked);
        self::assertNotContains($this->fresh, $picked, 'Suspended 10 days ago - not yet.');
        self::assertNotContains($this->byStaff, $picked, 'A staff suspension is never ended by a timer.');
    }

    /** @return int[] */
    private function picked(): array
    {
        return array_map(static fn (array $row): int => (int) $row['service_id'], (new Termination())->suspendedTooLong());
    }

    private function suspended(int $clientId, string $by, int $daysAgo): int
    {
        $uid = Uid::make();
        $model = new ClientServiceModel();

        $model->insert([
            'uid' => $uid, 'client_relid' => $clientId, 'product_relid' => 0, 'billing_cycle_relid' => 0,
            'currency_relid' => $this->currencyId(), 'amount' => '10',
            'status_relid' => Status::idOf('client_service_statuses', 'suspended'),
            'module_data' => serialize([
                'suspended_by' => $by,
                'suspended_at' => date('Y-m-d H:i:s', strtotime("-{$daysAgo} days")),
            ]),
        ]);

        return (int) $model->where(['uid' => $uid])->first()[$model->id];
    }
}
