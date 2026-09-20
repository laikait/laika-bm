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

use Throwable;
use Laika\Core\Worker\Queue;
use Laika\Queue\Abstracts\Job;
use Laika\Queue\Worker;
use Laika\Queue\Interfaces\QueueDriverInterface;
use Laika\Queue\Interfaces\FailedJobProviderInterface;
use Laika\Service\Infra;

/**
 * Cron works the queue itself - Phase 51.
 *
 * LBM's jobs have always extended laika-queue's Job, and nothing ever queued
 * one: cron called handle() directly, so maxTries, backoff and the failed-job
 * store - the reasons to use a queue at all - never applied. A job that threw
 * was simply tried again tomorrow, if the daily block ran again, and nobody
 * could list what had failed.
 *
 * Now cron PUSHES each job and then drains the queue in this same process, for
 * a bounded time. That is the owner's decision (2026-09-19): an operator on
 * shared hosting has cron and nothing else, so LBM must not need a resident
 * worker. One that is running anyway (`php worker lbm`) simply shares the load.
 *
 * Everything per job is laika-queue's own Worker - its pop/ack/release/delete,
 * its backoff, its failed-job store. Only two things are added: drain() stops
 * when the queue is empty or the time is up instead of looping for ever, and
 * process() also REPORTS a failure, because cron's log and exit code must still
 * say that the day's invoices were not raised. The upstream version of this
 * belongs in laika-queue as `Worker::drain()` (plan U13).
 *
 * No fork, deliberately: cron.php already holds a lock and runs to a budget, and
 * this must work where pcntl does not.
 */
class QueueRunner extends Worker
{
    /** @var string The Queue LBM's Own Jobs Go On */
    public const QUEUE = 'lbm';

    /** @var int Seconds One Cron Run May Spend Working The Queue, By Default */
    public const SECONDS = 60;

    /** @var array<int,string> What Failed During The Last drain(), In Words */
    private array $failures = [];

    /**
     * @param ?QueueDriverInterface $driver Defaults to lf-config/queue.php's
     * @param ?FailedJobProviderInterface $failer Defaults to lf-config/queue.php's
     */
    public function __construct(?QueueDriverInterface $driver = null, ?FailedJobProviderInterface $failer = null)
    {
        // unserialize() refuses every class not named here - laika-queue's guard
        // against object injection through a tampered payload.
        Job::registerTrustedClasses(Infra::getQueueJobsClasses());

        parent::__construct($driver ?? Queue::driver(), $failer ?? Queue::failedProvider());
    }

    /**
     * Put a Job On LBM's Queue
     * @param Job $job
     * @param int $delay Seconds
     * @return string Job ID
     */
    public function push(Job $job, int $delay = 0): string
    {
        return $this->driver->push($job, self::QUEUE, $delay);
    }

    /**
     * Work The Queue Until It Is Empty Or The Time Is Up
     *
     * A job whose retry is not due yet is not popped, so it waits for a later
     * run - which is the backoff working as intended, not a job lost.
     * @param int $seconds Time Budget
     * @return array{done:int,failed:int,failures:array<int,string>}
     */
    public function drain(int $seconds = self::SECONDS): array
    {
        $this->failures = [];
        $done = 0;
        $until = time() + max(1, $seconds);

        while (time() < $until) {
            $job = $this->driver->pop(self::QUEUE);

            if ($job === null) {
                break;
            }

            $this->process($job, self::QUEUE);
            $done++;
        }

        return ['done' => $done, 'failed' => count($this->failures), 'failures' => $this->failures];
    }

    /**
     * Push One Job And Run It Now
     *
     * For cron's ordered daily work: the job runs in its place in the sequence,
     * with the queue's retry and failure handling around it.
     * @param Job $job
     * @return ?string Null when it ran, or what went wrong
     */
    public function run(Job $job): ?string
    {
        $this->push($job);

        $result = $this->drain();

        return $result['failures'] === [] ? null : implode('; ', $result['failures']);
    }

    /**
     * Worker::process(), Plus Saying What Failed
     *
     * The same four outcomes as the parent - ack, or failed() and then release
     * with backoff or give up into the failed-job store - with the failure
     * recorded for drain()'s caller.
     * @param Job $job
     * @param string $queue
     * @return void
     */
    protected function process(Job $job, string $queue): void
    {
        try {
            $job->handle();
            $this->driver->ack($job->id, $queue);
        } catch (Throwable $e) {
            $name = basename(str_replace('\\', '/', get_class($job)));
            $gaveUp = $job->tries >= $job->maxTries;

            $this->failures[] = $name . ': ' . $e->getMessage()
                . ($gaveUp ? ' (gave up - see `php laika queue:failed`)' : ' (will retry)');

            $job->failed($e);

            if ($gaveUp) {
                $this->failer?->log($queue, $job->serializePayload(), $e);
                $this->driver->delete($job->id, $queue);
            } else {
                $this->driver->release($job->id, $queue, $job->backoff());
            }
        }
    }
}
