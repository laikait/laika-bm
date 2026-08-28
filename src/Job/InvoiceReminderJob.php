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
use LBM\Model\InvoiceModel;
use LBM\Action\Activity;
use LBM\Action\Client;
use LBM\Action\Invoice;
use LBM\Action\Mail;
use LBM\Service\Money;
use LBM\Service\Status;

/**
 * Chases unpaid invoices, and marks the ones that have gone past due.
 *
 * Run daily, after InvoiceGenerateJob. Two things happen per invoice:
 *
 *  - anything past its due date and still owing is moved to `overdue`, so the
 *    admin screens and the client area agree on what is late without either of
 *    them working it out from a date;
 *  - a reminder is queued on the days named by `invoice_reminder_days`, a comma
 *    separated list of offsets - "-7,0,3" means a week before, on the day, and
 *    three days after.
 *
 * Exactly one reminder goes out per invoice per offset, keyed off the day the
 * job runs. That is what makes the job safe to run twice: a second run on the
 * same day matches the same offsets and finds the same messages already queued.
 */
class InvoiceReminderJob extends Job
{
    /** @var string Queue Name */
    public string $queue = 'default';

    /** @var int Retries */
    public int $maxTries = 2;

    /** @var int Seconds Before a Retry */
    public int $retryAfter = 300;

    /** @var string Status Lookup Table */
    public const STATUSES = 'invoice_statuses';

    /** @var string Days Relative To The Due Date, When Nothing Is Configured */
    public const DEFAULT_OFFSETS = '-7,0,3';

    /** @var string Template Slug */
    public const TEMPLATE = 'invoice-reminder';

    /**
     * Run The Job
     * @return void
     */
    public function handle(): void
    {
        $invoices = new Invoice();
        $offsets = $this->offsets();
        $today = date('Y-m-d');

        foreach ($this->unpaid() as $row) {
            try {
                $this->markOverdue($invoices, $row);

                $due = $row['invoice_due_date'] ?? null;

                if ($due === null || $due === '') {
                    continue;
                }

                // Which reminder is today, if any: the whole-day difference
                // between now and the due date has to be one of the configured
                // offsets, so at most one message goes out per invoice per day.
                $offset = $this->daysBetween((string) $due, $today);

                if (!in_array($offset, $offsets, true)) {
                    continue;
                }

                $this->remind($invoices, $row, $offset);
            } catch (Throwable $e) {
                (new Activity())->record(
                    'invoice.reminder.failed',
                    'Could not chase invoice #' . ($row['invoice_number'] ?? '?') . ': ' . $e->getMessage()
                );
            }
        }
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Every Invoice That Is Still Owed Something
     * @return array
     */
    private function unpaid(): array
    {
        $paid = Status::idOf(self::STATUSES, 'paid');
        $cancelled = Status::idOf(self::STATUSES, 'cancelled');
        $draft = Status::idOf(self::STATUSES, 'draft');

        $model = new InvoiceModel();

        $excluded = array_values(array_filter([$paid, $cancelled, $draft]));

        if ($excluded !== []) {
            $model->whereNotIn('status_relid', $excluded);
        }

        return $model->notNull('invoice_due_date')->order($model->id, 'ASC')->get();
    }

    /**
     * Move a Late Invoice To Overdue, And Charge The Late Fee
     *
     * The status change is what makes the fee safe to apply: it happens on the
     * one run where the invoice first becomes late, and every later run sees it
     * already marked overdue and returns before charging anything. No separate
     * "fee applied" flag is needed, and there is no way to charge it twice.
     * @param Invoice $invoices Invoice Action
     * @param array $row Invoice Row
     * @return void
     */
    private function markOverdue(Invoice $invoices, array $row): void
    {
        $overdue = Status::idOf(self::STATUSES, 'overdue');

        if ($overdue === null || (int) ($row['status_relid'] ?? 0) === $overdue) {
            return;
        }

        if (!$invoices->isOverdue($row)) {
            return;
        }

        $invoiceId = (int) $row['invoice_id'];

        $invoices->update($invoiceId, ['status_relid' => $overdue]);

        $this->chargeLateFee($invoices, $row);
    }

    /**
     * Add The Late Fee Line, Once
     *
     * A percentage of what is still owed, not of the invoice total: a customer
     * who has paid most of it should not be charged as though they had paid
     * none. Zero - the default - charges nothing at all.
     * @param Invoice $invoices Invoice Action
     * @param array $row Invoice Row
     * @return void
     */
    private function chargeLateFee(Invoice $invoices, array $row): void
    {
        $percent = (string) (option('late_fee_percent', '0') ?: '0');

        if (Money::isZero($percent)) {
            return;
        }

        $owed = $invoices->balance($row);

        if (Money::isZero($owed)) {
            return;
        }

        $fee = Money::round(Money::percent($owed, $percent));

        if (Money::isZero($fee)) {
            return;
        }

        $invoiceId = (int) $row['invoice_id'];

        $invoices->addItem($invoiceId, [
            'description' =>  "Late payment fee ({$percent}%)",
            'quantity'    =>  '1',
            'unit_price'  =>  $fee,
        ]);

        (new Activity())->record(
            'invoice.late_fee',
            'Charged a late fee of ' . money($fee, $row['currency_relid'] ?? null)
                . ' on invoice ' . ($row['invoice_number'] ?? ''),
            Activity::SYSTEM
        );
    }

    /**
     * Queue One Reminder
     * @param Invoice $invoices Invoice Action
     * @param array $row Invoice Row
     * @param int $offset Days Relative To The Due Date
     * @return void
     */
    private function remind(Invoice $invoices, array $row, int $offset): void
    {
        $client = (new Client())->find((int) ($row['client_relid'] ?? 0));

        if ($client === null || empty($client['email'])) {
            return;
        }

        $balance = $invoices->balance($row);

        if (Money::isZero($balance)) {
            return;
        }

        $mail = new Mail();
        $subject = $this->subject($row, $offset);

        // The one guard that makes a second run of the day harmless: the same
        // invoice, the same recipient and the same subject means this reminder
        // has already been queued.
        if ($mail->exists([
            'to_email' =>  (string) $client['email'],
            'subject'  =>  $subject,
        ])) {
            return;
        }

        try {
            $mail->queueTemplate(self::TEMPLATE, (string) $client['email'], [
                'first_name'     =>  $client['first_name'] ?? '',
                'last_name'      =>  $client['last_name'] ?? '',
                'invoice_number' =>  $row['invoice_number'] ?? '',
                'balance'        =>  money($balance, $row['currency_relid'] ?? null),
                'total'          =>  money($row['total'] ?? 0, $row['currency_relid'] ?? null),
                'due_date'       =>  format_date($row['invoice_due_date'] ?? null),
                'days'           =>  (string) abs($offset),
                'subject'        =>  $subject,
            ], (int) $client['cid']);
        } catch (Throwable) {
            // No such template, or it is switched off. Nothing to chase with.
            return;
        }

        (new Activity())->record(
            'invoice.reminder.queued',
            'Queued a reminder for invoice ' . ($row['invoice_number'] ?? ''),
            Activity::SYSTEM
        );
    }

    /**
     * What The Reminder Is Called
     *
     * Doubles as the "already sent" key, so it has to be stable for a given
     * invoice and offset and different between offsets.
     * @param array $row Invoice Row
     * @param int $offset Days Relative To The Due Date
     * @return string
     */
    private function subject(array $row, int $offset): string
    {
        $number = (string) ($row['invoice_number'] ?? '');

        return match (true) {
            $offset < 0  =>  "Invoice {$number} is due in " . abs($offset) . ' day(s)',
            $offset === 0 =>  "Invoice {$number} is due today",
            default      =>  "Invoice {$number} is " . $offset . ' day(s) overdue',
        };
    }

    /**
     * Whole Days From a Due Date To a Given Day
     *
     * Both sides are cut to midnight first. Comparing raw timestamps would make
     * "due in one day" depend on the hour the job happened to run.
     * @param string $due Due Date
     * @param string $today Today, As Y-m-d
     * @return int Negative before the due date, positive after
     */
    private function daysBetween(string $due, string $today): int
    {
        $dueDay = strtotime(date('Y-m-d', (int) strtotime($due)));
        $now = strtotime($today);

        if ($dueDay === false || $now === false) {
            return 0;
        }

        return (int) round(($now - $dueDay) / 86400);
    }

    /**
     * The Configured Reminder Offsets
     * @return int[]
     */
    private function offsets(): array
    {
        $configured = (string) (option('invoice_reminder_days', self::DEFAULT_OFFSETS) ?: self::DEFAULT_OFFSETS);

        $offsets = [];

        foreach (explode(',', $configured) as $part) {
            $part = trim($part);

            if ($part === '' || !is_numeric($part)) {
                continue;
            }

            $offsets[] = (int) $part;
        }

        return $offsets !== [] ? array_values(array_unique($offsets)) : [-7, 0, 3];
    }
}
