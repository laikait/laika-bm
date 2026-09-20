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

namespace LBM\Job;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Laika\Queue\Abstracts\Job;
use LBM\Action\ServiceOperation;
use LBM\Support\Clock;

/**
 * Reads every active service's usage from its control panel - Phase 53.
 *
 * Daily, from cron. Only services whose module implements SyncsUsage are asked;
 * the rest are skipped without a call.
 *
 * ONE TRY, AND IT DOES NOT THROW WHEN A PANEL FAILS. A retry would read every
 * service again, and each reading is a new `service_usage_records` row - so a
 * retry after one unreachable server would double everybody else's usage.
 * Each failure is already in the provisioning log and the error log, against
 * the service and module it belongs to, which is where staff look for it.
 */
class UsageSyncJob extends Job
{
    /** @var string Queue Name */
    public string $queue = 'default';

    /** @var int Retries - see the class docblock */
    public int $maxTries = 1;

    /** @var array{done:int,failed:int} What The Last Run Did */
    public array $result = ['done' => 0, 'failed' => 0];

    /**
     * Run The Job
     * @return void
     */
    public function handle(): void
    {
        // A worker runs no pipeline - see PruneTokensJob.
        Clock::apply();

        $this->result = (new ServiceOperation())->syncAllUsage();
    }
}
