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

namespace LBM\Tests\Queue;

use Laika\Queue\Abstracts\Job;
use Laika\Queue\Driver\DatabaseDriver;
use Laika\Queue\Driver\DatabaseFailedJobProvider;
use LBM\Support\QueueRunner;
use LBM\Tests\TestCase;
use RuntimeException;

/** Succeeds, and says so. */
final class PassingJob extends Job
{
    public static int $ran = 0;

    public function handle(): void
    {
        self::$ran++;
    }
}

/** Always throws. One try, so the first failure is the last. */
final class FailingJob extends Job
{
    public int $maxTries = 1;
    protected bool $jitter = false;

    public function handle(): void
    {
        throw new RuntimeException('the registrar is down');
    }
}

/** Always throws, with retries left. */
final class RetryingJob extends Job
{
    public int $maxTries = 3;
    public int $retryAfter = 600;
    protected bool $jitter = false;

    public function handle(): void
    {
        throw new RuntimeException('try again later');
    }
}

/**
 * Phase 51: cron works LBM's queue itself - a job runs, a failing one is
 * retried with backoff, and one out of tries lands in the failed-job store.
 * On the database driver, against the test database - never the app's queue.
 */
final class QueueRunnerTest extends TestCase
{
    private QueueRunner $runner;
    private DatabaseFailedJobProvider $failed;

    protected function setUp(): void
    {
        parent::setUp();

        Job::registerTrustedClasses([PassingJob::class, FailingJob::class, RetryingJob::class]);
        PassingJob::$ran = 0;

        // No connection name: laika-queue 1.1.2 rejects EVERY named connection,
        // `default` included - match(true) against preg_match()'s int 1 (U14).
        $this->failed = new DatabaseFailedJobProvider();
        $this->runner = new QueueRunner(new DatabaseDriver(), $this->failed);
    }

    public function testAJobRunsAndLeavesTheQueue(): void
    {
        self::assertNull($this->runner->run(new PassingJob()));
        self::assertSame(1, PassingJob::$ran);
        self::assertSame(0, (new DatabaseDriver())->size(QueueRunner::QUEUE));
    }

    public function testAFailureIsReportedAndRetriedLater(): void
    {
        $failure = $this->runner->run(new RetryingJob());

        self::assertStringContainsString('try again later', (string) $failure);
        self::assertStringContainsString('will retry', (string) $failure);

        // Released with a backoff: still queued, and not due within this drain.
        self::assertSame(1, (new DatabaseDriver())->size(QueueRunner::QUEUE));
        self::assertSame(0, $this->runner->drain(1)['done']);
    }

    public function testAJobOutOfTriesGoesToTheFailedStore(): void
    {
        $before = count($this->failed->all());

        $failure = $this->runner->run(new FailingJob());

        self::assertStringContainsString('gave up', (string) $failure);
        self::assertSame(0, (new DatabaseDriver())->size(QueueRunner::QUEUE));
        self::assertCount($before + 1, $this->failed->all());
    }

    public function testDrainRunsEverythingThatIsDue(): void
    {
        $this->runner->push(new PassingJob());
        $this->runner->push(new PassingJob());

        $result = $this->runner->drain();

        self::assertSame(2, $result['done']);
        self::assertSame(0, $result['failed']);
        self::assertSame(2, PassingJob::$ran);
    }
}
