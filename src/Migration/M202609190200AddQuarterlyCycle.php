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

namespace LBM\Migration;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Laika\Service\Uid;
use LBM\Contract\MigrationAbstract;
use LBM\Model\BillingCycleModel;

/**
 * The tenth real migration: Phase 50 offers a quarterly billing cycle.
 *
 * Provision::nextDue() and InvoiceGenerateJob::periodEnd() have both mapped
 * `quarterly` to three months since they were written, but the cycle was never
 * seeded, so no product could be priced or sold on it. BillingCycleSchema now
 * seeds it on a fresh install; this adds the same row to an existing one.
 *
 * A row, not a structure change - it lives here because an installed site never
 * runs a schema's seed() again (seed() returns early once the table has rows).
 *
 * Cycles are matched by NAME everywhere (Provision::cycleId(), the product
 * pricing screens), never by id, so the new row's id differing between a fresh
 * install and an upgraded one changes nothing.
 */
class M202609190200AddQuarterlyCycle extends MigrationAbstract
{
    /** @var string Ledger Key. Written once, never edited */
    protected string $id = '20260919_0200_add_quarterly_cycle';

    /** @var string What This Does */
    protected string $description
        = 'Add the quarterly billing cycle, which the billing code already understood but nothing offered.';

    /**
     * Whether This Install Needs It
     * @return bool
     */
    public function applies(): bool
    {
        return $this->hasTable('billing_cycles')
            && (new BillingCycleModel($this->connection))->where(['billing_cycle_name' => 'quarterly'])->count() === 0;
    }

    /**
     * Add The Row
     * @return void
     */
    public function run(): void
    {
        (new BillingCycleModel($this->connection))->insert(Uid::stamp([
            ['billing_cycle_name' => 'quarterly'],
        ]));
    }
}
