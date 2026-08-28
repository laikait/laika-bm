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

use Throwable;
use Laika\Queue\Abstracts\Job;
use LBM\Action\Mail;

/**
 * Sends what LBM\Action\Mail queued.
 *
 * Dispatched with no argument it drains a batch of the queue, which is what a
 * scheduled worker wants. Dispatched with a queue id it sends that one message,
 * which is what "resend this" on the admin screen wants. One job rather than
 * two, because both do exactly the same thing to a row.
 *
 * The job never throws for a message that would not send. A bad recipient
 * address is the message's problem, not the worker's: failing the job would
 * retry the whole batch and eventually park it in failed_jobs, taking the good
 * messages with it. Per-message failures are recorded on the row and retried
 * from there, up to Mail::MAX_ATTEMPTS.
 */
class SendEmailJob extends Job
{
    /** @var string Queue Name */
    public string $queue = 'default';

    /** @var int Retries Before This Job Itself Is Given Up On */
    public int $maxTries = 3;

    /** @var int Seconds Before a Retry */
    public int $retryAfter = 120;

    /** @var ?int One Message, Or Null For a Batch */
    private ?int $queueId;

    /** @var int How Many To Take In Batch Mode */
    private int $batch;

    /**
     * @param ?int $queueId Queue ID. Null drains a batch
     * @param int $batch How Many To Take In Batch Mode
     */
    public function __construct(?int $queueId = null, int $batch = 25)
    {
        $this->queueId = $queueId;
        $this->batch = $batch > 0 ? $batch : 25;
    }

    /**
     * Run The Job
     * @return void
     */
    public function handle(): void
    {
        $mail = new Mail();

        $rows = $this->queueId !== null
            ? array_filter([$mail->find($this->queueId)])
            : $mail->pending($this->batch);

        foreach ($rows as $row) {
            try {
                $mail->send($row);
            } catch (Throwable $e) {
                // send() already records its own failures; this catches anything
                // that got past it so one poisonous row cannot stop the batch.
                $mail->markFailed((int) $row['queue_id'], $e->getMessage());
            }
        }
    }
}
