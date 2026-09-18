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

namespace LBM\Action;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Laika\Model\Model;
use LBM\Model\AutoChargeAttemptModel;
use LBM\Model\InvoiceModel;
use LBM\Service\Money;
use LBM\Service\Status;
use LBM\Support\Clock;
use RuntimeException;
use Throwable;

/**
 * Charging a saved card without the customer there - Phase 48.
 *
 * Two ways in, one path: the daily `charge saved cards` cron task, and the
 * "Charge saved card" button on the admin invoice. Both claim an attempt row,
 * charge the balance off-session through the card's gateway, and record the
 * payment through the one ledger (GatewayCallback::recordCharge()).
 *
 * ---------------------------------------------------------------------------
 * OFF UNTIL THE OPERATOR SWITCHES IT ON
 * ---------------------------------------------------------------------------
 * `auto_charge` is read through option_bool(), so a missing row is off. An
 * install upgraded to this phase has no saved cards yet, but charging
 * customers' cards is a new act on them, and Phase 23's argument holds: nothing
 * starts taking money behind the operator's back after an update.
 *
 * ---------------------------------------------------------------------------
 * AT MOST ONCE A DAY, AND THREE DAYS AT MOST
 * ---------------------------------------------------------------------------
 * The claim is a row written BEFORE the provider is asked, and the database's
 * UNIQUE (invoice_relid, claim_day) is what refuses a second one - see
 * AutoChargeAttemptSchema. After TRIES scheduled days the invoice is left for
 * the customer and staff: a card declined three mornings running is not going
 * to work on the fourth, and each try is a line on the customer's statement.
 *
 * ---------------------------------------------------------------------------
 * THE CUSTOMER IS TOLD
 * ---------------------------------------------------------------------------
 * A scheduled charge that fails sends `card-charge-failed`, saying why - a
 * decline, or the bank wanting them to confirm the payment themselves, which an
 * off-session charge can never do.
 *
 * English on purpose: cron reaches this, and no catalogue is loaded there.
 */
class AutoCharge extends Action
{
    /** @var int Scheduled Days An Invoice Is Tried On */
    public const TRIES = 3;

    /** @var string Status Lookup Table */
    public const STATUSES = 'invoice_statuses';

    /** @var string The Email a Failed Scheduled Charge Sends */
    public const TEMPLATE = 'card-charge-failed';

    /** @var string Started By The Daily Task */
    public const CRON = 'cron';

    /** @var string Started By a Member Of Staff */
    public const STAFF = 'staff';

    public function model(): Model
    {
        return new AutoChargeAttemptModel();
    }

    protected function createdColumn(): ?string
    {
        return 'attempted_at';
    }

    protected function updatedColumn(): ?string
    {
        return null;
    }

    ####################################################################################
    /*================================= EXTERNAL API =================================*/
    ####################################################################################

    /**
     * Whether The Operator Has Switched It On
     * @return bool
     */
    public function enabled(): bool
    {
        return option_bool('auto_charge');
    }

    /**
     * The Daily Sweep
     *
     * Unpaid invoices due within `auto_charge_days` of today, whose client has a
     * default card that can be charged. A client with no card is not touched and
     * nothing is recorded for them.
     * @return string What the cron line says
     */
    public function run(): string
    {
        // A cron process runs no pipeline, so nothing has put it on the
        // operator's timezone - and "today" is the claim.
        Clock::apply();

        if (!$this->enabled()) {
            return 'off';
        }

        $days = max(0, (int) option_int('auto_charge_days', 0));
        $horizon = date('Y-m-d 23:59:59', strtotime("+{$days} days"));
        $counts = ['charged' => 0, 'failed' => 0, 'asked' => 0, 'skipped' => 0];
        $cards = new PayMethod();

        foreach ($this->due($horizon) as $invoice) {
            try {
                $card = $cards->defaultFor((int) ($invoice['client_relid'] ?? 0));

                if ($card === null) {
                    continue;
                }

                if ($this->scheduledTries((int) $invoice['invoice_id']) >= self::TRIES) {
                    $counts['skipped']++;

                    continue;
                }

                $answer = $this->attempt($invoice, $card, self::CRON);

                match ($answer['outcome']) {
                    'charged', 'pending' => $counts['charged']++,
                    'authentication'     => $counts['asked']++,
                    'claimed'            => $counts['skipped']++,
                    default              => $counts['failed']++,
                };
            } catch (Throwable $e) {
                $counts['failed']++;

                (new Activity())->record(
                    'invoice.autocharge.failed',
                    'Could not charge a saved card for invoice ' . ($invoice['invoice_number'] ?? '?') . ': ' . $e->getMessage(),
                    Activity::SYSTEM
                );
            }
        }

        return "{$counts['charged']} charged, {$counts['failed']} failed, {$counts['asked']} need the customer, "
            . "{$counts['skipped']} skipped";
    }

    /**
     * Charge One Of The Invoice's Client's Cards, Now - Staff
     *
     * Not bound by the daily claim: pressing the button is a decision, not a
     * schedule. The card must be the invoice's own client's; another client's
     * is not found.
     * @param int $invoiceId
     * @param string $payMethodUid
     * @param string $source staff, or cron
     * @return array{success: bool, outcome: string, message: string}
     * @throws RuntimeException
     */
    public function chargeNow(int $invoiceId, string $payMethodUid, string $source = self::STAFF): array
    {
        $invoices = new Invoice();
        $invoice = $invoices->find($invoiceId);

        if ($invoice === null) {
            throw new RuntimeException('That invoice no longer exists.');
        }

        if ($invoices->isSettled($invoice)) {
            throw new RuntimeException('That invoice is already settled.');
        }

        $card = (new PayMethod())->forClientKey((int) $invoice['client_relid'], $payMethodUid);

        if ($card === null) {
            throw new RuntimeException('That card is not on this client\'s account.');
        }

        return $this->attempt($invoice, $card, $source === self::CRON ? self::CRON : self::STAFF);
    }

    /**
     * The Newest Attempt On An Invoice
     * @param int $invoiceId
     * @return ?array
     */
    public function lastAttempt(int $invoiceId): ?array
    {
        $model = $this->model();
        $rows = $model->where(['invoice_relid' => $invoiceId])->order($model->id, self::DESC)->limit(1)->get();

        return $rows[0] ?? null;
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Unpaid Invoices Due By The Horizon
     * @param string $horizon Y-m-d H:i:s
     * @return array
     */
    private function due(string $horizon): array
    {
        $excluded = array_values(array_filter([
            Status::idOf(self::STATUSES, 'paid'),
            Status::idOf(self::STATUSES, 'cancelled'),
            Status::idOf(self::STATUSES, 'draft'),
        ]));

        $model = new InvoiceModel();

        if ($excluded !== []) {
            $model->whereNotIn('status_relid', $excluded);
        }

        $rows = $model->notNull('invoice_due_date')
            ->where(['invoice_due_date' => $horizon], '<=')
            ->order($model->id, self::ASC)
            ->get();

        $invoices = new Invoice();

        return array_values(array_filter($rows, static fn (array $row): bool => !$invoices->isSettled($row)
            && Money::isGreater($invoices->balance($row), '0')));
    }

    /**
     * How Many Scheduled Days An Invoice Has Been Tried On
     * @param int $invoiceId
     * @return int
     */
    private function scheduledTries(int $invoiceId): int
    {
        return $this->model()->where(['invoice_relid' => $invoiceId, 'source' => self::CRON])->count();
    }

    /**
     * Claim, Charge, Record - One Attempt
     * @param array $invoice
     * @param array $card Token and all
     * @param string $source
     * @return array{success: bool, outcome: string, message: string}
     */
    private function attempt(array $invoice, array $card, string $source): array
    {
        $invoiceId = (int) $invoice['invoice_id'];

        // THE CLAIM. Written before anything is sent; a second scheduled attempt
        // the same day fails to insert and stops here. Staff rows carry no day.
        try {
            $attemptId = $this->create([
                'invoice_relid' =>  $invoiceId,
                'pm_relid'      =>  (int) ($card['pm_id'] ?? 0),
                'source'        =>  $source,
                'claim_day'     =>  $source === self::CRON ? date('Y-m-d') : null,
                'outcome'       =>  'claimed',
                'attempted_at'  =>  date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable) {
            return ['success' => false, 'outcome' => 'claimed', 'message' => 'This invoice has already been tried today.'];
        }

        $cards = new PayMethod();
        $gateway = $cards->gatewayOf($card);
        $driver = $gateway === null ? null : (new Gateway())->tokenizerFor($gateway);

        if ($driver === null) {
            return $this->close($attemptId, 'failed', 'The card\'s gateway is not available, so nothing was charged.');
        }

        // Read again: an invoice the customer settled a minute ago is not charged.
        $invoices = new Invoice();
        $fresh = $invoices->find($invoiceId);

        if ($fresh === null || $invoices->isSettled($fresh)) {
            return $this->close($attemptId, 'failed', 'The invoice was settled before the card was charged - nothing was charged.');
        }

        $balance = $invoices->balance($fresh);
        $currency = Money::get((int) ($fresh['currency_relid'] ?? 0));
        $client = (new Client())->find((int) ($fresh['client_relid'] ?? 0));
        $token = $cards->token($card);

        try {
            $result = $driver->charge([
                'amount'      =>  $balance,
                'currency'    =>  (string) ($currency['currency_code'] ?? ''),
                'invoice_id'  =>  $invoiceId,
                'client_id'   =>  (int) ($fresh['client_relid'] ?? 0),
                'description' =>  (string) ($fresh['invoice_number'] ?? ''),
                'client'      =>  $client ?? [],
                'return_url'  =>  '',
                'token'       =>  '',
                'pay_method'  =>  $token,
                'save'        =>  false,
                'off_session' =>  true,
                'attempt_key' =>  (new Gateway())->attemptKey($invoiceId, $balance, $token),
            ]);
        } catch (Throwable $e) {
            $result = ['success' => false, 'pending' => false, 'message' => $e->getMessage()];
        }

        $pending = ($result['pending'] ?? false) === true;

        if (!$pending && ($result['success'] ?? false) === true) {
            $answer = (new GatewayCallback())->recordCharge($gateway, $fresh, $result, $source);

            if (in_array((string) ($answer['outcome'] ?? ''), ['applied', 'duplicate', 'ignored'], true)) {
                return $this->close($attemptId, 'charged', 'Charged ' . $cards->describe($card) . '.', (int) ($answer['transaction_id'] ?? 0));
            }

            // The money moved and could not be recorded. Loud: somebody has to
            // look at the callbacks screen, where the reason is.
            return $this->close($attemptId, 'failed', 'Charged, but the payment could not be recorded: ' . (string) ($answer['message'] ?? ''));
        }

        // Off-session there is nobody to send to a bank, so a charge that needs
        // one is the customer's to finish.
        if ($pending && trim((string) ($result['redirect'] ?? '')) === '') {
            return $this->close($attemptId, 'pending', trim((string) ($result['message'] ?? '')) ?: 'The provider took the payment on and will confirm it.');
        }

        $message = trim((string) ($result['message'] ?? '')) ?: 'The card was not charged.';
        $outcome = $pending || ($result['authenticate'] ?? false) === true ? 'authentication' : 'failed';
        $closed = $this->close($attemptId, $outcome, $message);

        if ($source === self::CRON) {
            $this->tell($fresh, $client, $card, $outcome, $message);
        }

        return $closed;
    }

    /**
     * Write What Happened Onto The Claim
     * @param int $attemptId
     * @param string $outcome
     * @param string $message
     * @param int $transactionId
     * @return array{success: bool, outcome: string, message: string}
     */
    private function close(int $attemptId, string $outcome, string $message, int $transactionId = 0): array
    {
        $data = ['outcome' => $outcome, 'message' => mb_substr($message, 0, 250)];

        if ($transactionId > 0) {
            $data['transaction_relid'] = $transactionId;
        }

        $this->update($attemptId, $data);

        return ['success' => in_array($outcome, ['charged', 'pending'], true), 'outcome' => $outcome, 'message' => $message];
    }

    /**
     * Tell The Customer The Scheduled Charge Did Not Work
     * @param array $invoice
     * @param ?array $client
     * @param array $card
     * @param string $outcome failed Or authentication
     * @param string $reason
     * @return void
     */
    private function tell(array $invoice, ?array $client, array $card, string $outcome, string $reason): void
    {
        if ($client === null || empty($client['email'])) {
            return;
        }

        try {
            (new Mail())->queueTemplate(self::TEMPLATE, (string) $client['email'], [
                'first_name'     =>  $client['first_name'] ?? '',
                'last_name'      =>  $client['last_name'] ?? '',
                'invoice_number' =>  $invoice['invoice_number'] ?? '',
                'balance'        =>  money((new Invoice())->balance($invoice), $invoice['currency_relid'] ?? null),
                'card'           =>  (new PayMethod())->describe($card),
                'reason'         =>  $reason,
                'what_next'      =>  $outcome === 'authentication'
                    ? 'Your bank wants you to confirm this payment yourself, which it cannot ask for while you are away. Please pay the invoice in your account.'
                    : 'Please pay the invoice in your account, or add another card there.',
            ], (int) $client['cid']);
        } catch (Throwable $e) {
            (new Activity())->record(
                'invoice.autocharge.mail.failed',
                'Could not tell the client about a failed card charge on invoice ' . ($invoice['invoice_number'] ?? '?')
                    . ': ' . $e->getMessage(),
                Activity::SYSTEM
            );
        }
    }
}
