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

namespace LBM\Support;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Throwable;
use LBM\Job\InvoiceGenerateJob;
use LBM\Job\InvoiceReminderJob;
use LBM\Job\PruneTokensJob;
use LBM\Service\Mail;
use LBM\Service\Dunning;
use LBM\Service\Provision;
use LBM\Service\Server;
use LBM\Service\Setting;
use LBM\Service\Termination;

/**
 * Everything the operator's cron task actually does.
 *
 * The entry point is `cron.php` at the app root, which is a nine-line shim:
 * refuse anything that is not CLI, boot, and call run() here. The work lives in
 * the package so it is versioned with the product rather than sitting in a file
 * an operator might edit and then lose on the next release.
 *
 * ------------------------------------------------------------------------
 * Nothing in Pipeline/ runs for a cron invocation
 * ------------------------------------------------------------------------
 * There is no request, so there is no session, no CSRF, no language catalogue
 * and no area. Which is why nothing in this class calls local(): with no
 * catalogue loaded it would throw `'LANG' Class Doesn't Exists!` and mask the
 * real error underneath it. Same rule the queue worker has always followed, and
 * the same reason src/Action/* is left in English.
 *
 * The one thing a pipeline does that this genuinely needs is the clock, and
 * `Clock::apply()` exists for exactly that - "daily" has to mean the operator's
 * day, not the server's. cron.php calls it before run().
 *
 * ------------------------------------------------------------------------
 * Cadence is tracked, not assumed
 * ------------------------------------------------------------------------
 * A five-minute cron must not raise invoices 288 times a day. Two options carry
 * the state:
 *
 *   cron_last_run    - timestamp, written on every run. This is what the admin
 *                      dashboard reads to tell an operator their cron is dead,
 *                      which is the single most common way a self-hosted
 *                      billing install silently stops billing anybody.
 *   cron_last_daily  - Y-m-d in the *app* timezone. The daily block runs when
 *                      it differs from today.
 *
 * The daily jobs are all idempotent by construction - InvoiceGenerateJob decides
 * what to raise by looking at what has already been raised - so a double run
 * costs nothing but time. The cadence tracking is about cost, not correctness.
 *
 * Mail is the exception, and the reason the lock below is not optional: sending
 * is not idempotent and a message sent twice is a message the customer received
 * twice.
 */
class Cron
{
    /** @var string Lock File, Relative To APP_PATH */
    public const LOCK = '/lf-storage/lbm/cron.lock';

    /** @var int Minutes After Which a Lock Is Assumed Abandoned */
    public const LOCK_MINUTES = 30;

    /** @var int How Many Queued Messages Are Taken Per Batch */
    public const MAIL_BATCH = 25;

    /** @var int The Most Messages One Run Will Attempt */
    public const MAIL_MAX = 200;

    /** @var int How Long Delivered Messages Are Kept */
    public const MAIL_KEEP_DAYS = 30;

    /** @var string[] Lines To Print When The Run Finishes */
    private array $lines = [];

    /** @var string[] What Went Wrong, If Anything */
    private array $errors = [];

    /**
     * Run The Scheduled Work
     * @return int Process exit code. Non-zero when anything failed
     */
    public function run(): int
    {
        $lock = $this->lock();

        if ($lock === false) {
            // Not an error. A previous run is still going, which on a
            // five-minute cron with a large mail backlog is entirely normal,
            // and reporting it as a failure would mail the operator about
            // nothing every five minutes until the backlog cleared.
            $this->out('Another run is still in progress. Nothing to do.');

            return $this->finish(0);
        }

        try {
            $this->everyRun();
            $this->daily();
        } finally {
            // Written even when something threw, so a broken task cannot make
            // the dashboard claim cron has stopped running.
            $this->put('cron_last_run', date('Y-m-d H:i:s'));
            $this->unlock();
        }

        return $this->finish($this->errors === [] ? 0 : 1);
    }

    ####################################################################################
    /*=================================== SCHEDULE ===================================*/
    ####################################################################################

    /**
     * Work That Happens On Every Invocation
     * @return void
     */
    private function everyRun(): void
    {
        $this->task('mail queue', function (): string {
            $sent = 0;
            $failed = 0;
            $seen = [];
            $batch = $this->number('cron_mail_batch', self::MAIL_BATCH);
            $max = $this->number('cron_mail_max', self::MAIL_MAX);

            while (count($seen) < $max) {
                $rows = Mail::pending($batch);
                $fresh = 0;

                foreach ($rows as $row) {
                    $id = (int) $row['queue_id'];

                    // A message that just failed is still "pending" - it is
                    // retried until MAX_ATTEMPTS - so without this the same
                    // dead message would be retried three times inside one run,
                    // seconds apart, and then be out of attempts. The next cron
                    // invocation is the right retry interval, not the next loop.
                    if (isset($seen[$id])) {
                        continue;
                    }

                    $seen[$id] = true;
                    $fresh++;

                    Mail::send($row) ? $sent++ : $failed++;

                    if (count($seen) >= $max) {
                        break;
                    }
                }

                if ($fresh === 0) {
                    break;
                }
            }

            // A failed send is not a failed task. The queue records the reason
            // on the row and retries it; stopping the run here would mean one
            // bad address stopped invoices being raised.
            return $sent . ' sent, ' . $failed . ' failed';
        });

        // Every run, not daily, and that is the whole point of it being here.
        //
        // A customer who pays at 09:02 for a hosting account should have it
        // within minutes, not tomorrow morning - so this rides the five-minute
        // tick. It is also the SAFETY NET for provisioning: Invoice::
        // applyPayment() and markPaid() call Provision::forInvoice() directly
        // for promptness, but the sweep here is what makes the guarantee, since
        // it looks at settled invoices rather than at whether anybody
        // remembered to call anything.
        //
        // Both halves are bounded per tick, so a backlog spreads over several
        // runs instead of making one run last an hour and trip the lock.
        $this->task('provision paid orders', static function (): string {
            return Provision::run();
        });

        // Every run too, and for the same reason read in the other direction: a
        // customer who has just paid must come back within minutes, not
        // tomorrow. Suspension riding the same tick is a side effect of that and
        // costs nothing - the verdict is a date comparison, so running it 288
        // times a day reaches the same answer as running it once, just sooner.
        //
        // It reports `off` on every install that has not deliberately switched
        // it on. That line in the cron log is the feature working, not a fault:
        // an operator who has never heard of this can read what their cron does
        // and see that nothing is being switched off behind their back.
        $this->task('suspend overdue services', static function (): string {
            return Dunning::run();
        });

        // Every run as well, because a cancellation scheduled for "the end of
        // the term" has a time on it, not just a date - a customer whose month
        // runs out at 14:00 should not still be billed and provisioned until
        // the daily block happens to fire tomorrow morning.
        $this->task('end cancelled services', static function (): string {
            return Termination::run();
        });
    }

    /**
     * Work That Happens Once a Day
     * @return void
     */
    private function daily(): void
    {
        $today = date('Y-m-d');

        if ((string) option('cron_last_daily', '') === $today) {
            $this->out('daily work: already done today');

            return;
        }

        $this->task('raise due invoices', static function (): string {
            (new InvoiceGenerateJob())->handle();

            return 'done';
        });

        $this->task('invoice reminders', static function (): string {
            (new InvoiceReminderJob())->handle();

            return 'done';
        });

        $this->task('prune expired tokens', static function (): string {
            (new PruneTokensJob())->handle();

            return 'done';
        });

        // Daily, not per tick: it touches every server row, and the answer
        // cannot change unless something provisioned or terminated - both of
        // which recount the affected server themselves. This is the repair pass
        // for installs carrying the zeroes that shipped from Phase 0 until 24.
        $this->task('recount server accounts', static function (): string {
            return Server::recountAll() . ' servers';
        });

        $this->task('prune sent mail', function (): string {
            $days = $this->number('mail_prune_days', self::MAIL_KEEP_DAYS);

            return Mail::prune($days) . ' removed';
        });

        // Only stamped when the block actually ran. Stamping it up front would
        // mean a crash halfway through skipped the rest of the day's work
        // entirely, which is the one failure mode worth a repeated run.
        $this->put('cron_last_daily', $today);
    }

    ################################################################################
    /*=============================== INTERNAL API ===============================*/
    ################################################################################

    /**
     * Run One Task, Catching Whatever It Throws
     *
     * Isolation is the point. A broken SMTP server must not stop invoices being
     * raised, and an exception in the reminder job must not stop the token
     * prune. Every failure is recorded and the run continues; the exit code at
     * the end is what tells cron, and therefore the operator's mail, that
     * something needs looking at.
     * @param string $name Task Name, For The Log
     * @param callable $work The Task
     * @return void
     */
    private function task(string $name, callable $work): void
    {
        $started = microtime(true);

        try {
            $result = (string) $work();
            $this->out($name . ': ' . $result . $this->took($started));
        } catch (Throwable $e) {
            $this->errors[] = $name . ': ' . $e->getMessage();
            $this->out($name . ': FAILED - ' . $e->getMessage() . $this->took($started));
        }
    }

    /**
     * Take The Lock
     *
     * A lock older than the configured window is taken over rather than
     * respected. A run killed by the host - an OOM, a deploy, a reboot - leaves
     * its lock behind, and a lock nobody will ever release would stop this
     * install billing anybody, forever, silently.
     * @return bool False when a live run already holds it
     */
    private function lock(): bool
    {
        $path = APP_PATH . self::LOCK;
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        if (is_file($path)) {
            $age = time() - (int) (@filemtime($path) ?: 0);
            $stale = $this->number('cron_lock_minutes', self::LOCK_MINUTES) * 60;

            if ($age < $stale) {
                return false;
            }

            $this->out('Taking over a lock ' . (int) round($age / 60) . ' minutes old.');
        }

        return file_put_contents($path, date('Y-m-d H:i:s') . PHP_EOL) !== false;
    }

    /**
     * Release The Lock
     * @return void
     */
    private function unlock(): void
    {
        $path = APP_PATH . self::LOCK;

        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Write An Option Without Letting It Break The Run
     *
     * A cron run that did its work and then failed to record that it had is
     * still a run that did its work.
     * @param string $key Option Key
     * @param string $value Value
     * @return void
     */
    private function put(string $key, string $value): void
    {
        try {
            Setting::put($key, $value);
        } catch (Throwable $e) {
            $this->errors[] = 'recording ' . $key . ': ' . $e->getMessage();
        }
    }

    /**
     * An Option Read As a Positive Integer
     * @param string $key Option Key
     * @param int $default Value When Unset Or Nonsense
     * @return int
     */
    private function number(string $key, int $default): int
    {
        $value = (int) option_int($key, $default);

        return $value > 0 ? $value : $default;
    }

    /**
     * How Long Something Took, For The Log
     * @param float $started microtime(true) When It Began
     * @return string
     */
    private function took(float $started): string
    {
        return ' (' . number_format(microtime(true) - $started, 2) . 's)';
    }

    /**
     * Record a Line Of Output
     * @param string $line Text
     * @return void
     */
    private function out(string $line): void
    {
        $this->lines[] = $line;
    }

    /**
     * Print The Run And Hand Back An Exit Code
     *
     * Everything is printed at the end rather than as it happens, so a cron
     * daemon that mails its output sends one legible message rather than
     * interleaving with anything else on the box.
     * @param int $code Exit Code
     * @return int
     */
    private function finish(int $code): int
    {
        $stamp = date('Y-m-d H:i:s T');

        fwrite(STDOUT, "[{$stamp}] LBM cron\n");

        foreach ($this->lines as $line) {
            fwrite(STDOUT, '  ' . $line . "\n");
        }

        if ($this->errors !== []) {
            fwrite(STDERR, "\n" . count($this->errors) . " task(s) failed:\n");

            foreach ($this->errors as $error) {
                fwrite(STDERR, '  - ' . $error . "\n");
            }
        }

        return $code;
    }
}
