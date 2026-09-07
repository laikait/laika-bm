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

namespace LBM\Action;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Laika\Model\Model;
use LBM\Model\TransactionModel;
use LBM\Service\Money;
use RuntimeException;
use Throwable;

/**
 * Giving money back - and actually telling the processor about it.
 *
 * ---------------------------------------------------------------------------
 * THE HOLE THIS FILLS
 * ---------------------------------------------------------------------------
 * `GatewayInterface::refund()` was declared in Phase 9 and, until this phase,
 * had NO CALLER ANYWHERE. The admin panel has offered a Refund button since
 * Phase 5: it wrote a `refund` row into the ledger and stopped. Nothing was
 * ever sent to the processor, so the operator's books said the customer had
 * been refunded and the customer's card had not been touched.
 *
 * That is the worst direction for this to fail in. An under-charge is found by
 * the operator; this one is found by the customer, weeks later, and every
 * report in the product has already counted the money as returned.
 *
 * ---------------------------------------------------------------------------
 * THREE OUTCOMES, AND THEY ARE THREE DIFFERENT ACTS
 * ---------------------------------------------------------------------------
 *   - `throughGateway()` asks the processor to send the money back. It can
 *     fail, and when it does NOTHING IS WRITTEN.
 *   - `byHand()` records a refund the operator has already made themselves -
 *     a bank transfer, a cheque, cash over a counter. It is the only option
 *     for a payment that never came through a gateway, which is exactly what
 *     the shipped Offline driver says when asked to refund one.
 *   - `toCredit()` moves no money at all: it converts the payment into account
 *     credit and issues the credit note that explains it.
 *
 * `byHand()` IS NOT A FALLBACK FROM `throughGateway()`, and that is the rule
 * this class exists to hold. A failed API call that quietly became a
 * bookkeeping entry would say the money went back when it did not - which is
 * the bug above, rebuilt with more steps. The operator chooses, on the screen,
 * before anything happens.
 *
 * Phase 23 settled the same argument for suspensions: the module call comes
 * first and the status second, because recording something the module refused
 * is worse than not doing it at all.
 *
 * ---------------------------------------------------------------------------
 * WHAT IS LEFT TO REFUND IS AN INVOICE QUESTION, NOT A PAYMENT ONE
 * ---------------------------------------------------------------------------
 * `Transaction::refundedAgainst()` reads every refund on the INVOICE and its
 * caller subtracted that from ONE payment's amount. On the single-payment
 * invoice the product raises by itself that happens to be right, and on any
 * invoice settled by two payments it is wrong in a way that locks the operator
 * out: refunding the first payment in full makes the second unrefundable for
 * ever, because the invoice's refund total already equals it.
 *
 * The bound that actually matters is the invoice's: money cannot come back
 * that never went in. So the room on a payment is the SMALLER of what that
 * payment moved and what the invoice has left - correct in both directions,
 * and needing no column that does not exist. A `transactions.parent_relid`
 * would express it exactly, and is not worth a migration for an arithmetic
 * bound two existing numbers already give.
 *
 * ---------------------------------------------------------------------------
 * A REFUND NEVER RE-OPENS AN INVOICE
 * ---------------------------------------------------------------------------
 * `invoices.amount_paid` is what was received against the document. It is
 * history, and a refund does not un-receive it - so it is left alone, and the
 * refunded figure is DERIVED from the ledger beside it, the same way Phase 25
 * derives `taxAmount()` rather than storing it.
 *
 * Reducing it instead would put a balance back on the invoice, and Phase 23
 * says what happens next: the invoice goes overdue, dunning finds a service
 * covered by it, and refunding a customer switches their hosting off.
 *
 * The status moves ONLY forwards, to `refunded`, and only once everything the
 * invoice received has gone back. That status has been seeded since Phase 0
 * and was unreachable until now.
 */
class Refund extends Action
{
    /** @var string A Refund The Processor Actually Made */
    public const GATEWAY = 'gateway';

    /** @var string A Refund The Operator Made Themselves */
    public const MANUAL = 'manual';

    public function model(): Model
    {
        return new TransactionModel();
    }

    protected function createdColumn(): ?string
    {
        return 'tx_created_at';
    }

    protected function updatedColumn(): ?string
    {
        return 'tx_updated_at';
    }

    ####################################################################################
    /*=================================== READING ====================================*/
    ####################################################################################

    /**
     * How Much Has Been Refunded Against An Invoice
     *
     * Completed refunds only, in the sense that every refund this product
     * writes is completed - a refund that failed at the processor is never
     * written at all.
     * @param int $invoiceId Invoice ID
     * @return string Decimal string
     */
    public function refundedOn(int $invoiceId): string
    {
        if ($invoiceId <= 0) {
            return '0';
        }

        $rows = (new Transaction())->all([
            'invoice_relid' =>  $invoiceId,
            'type'          =>  Transaction::REFUND,
        ]);

        return Money::round(Money::sum(array_map(
            static fn(array $row): string => (string) ($row['amount'] ?? '0'),
            $rows
        )));
    }

    /**
     * How Much An Invoice Ever Received
     *
     * Payments and credit together, because credit applied to an invoice is
     * money the customer had already handed over. What has come back is not
     * subtracted here - see refundableOn().
     * @param array $invoice Invoice Row
     * @return string Decimal string
     */
    public function receivedOn(array $invoice): string
    {
        return Money::round(Money::add(
            (string) ($invoice['amount_paid'] ?? '0'),
            (string) ($invoice['credit_applied'] ?? '0')
        ));
    }

    /**
     * What Is Left To Refund On One Payment
     *
     * The smaller of what this payment moved and what the invoice has left. See
     * the class docblock for why the invoice bound is the one that matters.
     * @param array $payment Transaction Row Of Type payment
     * @return string Decimal string. Never negative
     */
    public function refundableOn(array $payment): string
    {
        if ((string) ($payment['type'] ?? '') !== Transaction::PAYMENT) {
            return '0';
        }

        $invoiceId = (int) ($payment['invoice_relid'] ?? 0);

        if ($invoiceId <= 0) {
            return '0';
        }

        $invoice = (new Invoice())->find($invoiceId);

        if ($invoice === null) {
            return '0';
        }

        $room = Money::sub($this->receivedOn($invoice), $this->refundedOn($invoiceId));
        $paid = Money::round((string) ($payment['amount'] ?? '0'));

        $left = Money::isGreater($room, $paid) ? $paid : $room;

        return Money::isGreater($left, '0') ? Money::round($left) : '0';
    }

    /**
     * Which Gateway a Payment Came Through, When It Can Refund
     *
     * Null covers three different situations that all end the same way - the
     * payment carries no gateway, the module has been removed or switched off,
     * or the driver will not build - and the screen says which, because "no
     * refund button" with no explanation is a support ticket.
     * @param array $payment Transaction Row
     * @return ?array The gateway row, or null
     */
    public function gatewayFor(array $payment): ?array
    {
        $gatewayId = (int) ($payment['gateway_relid'] ?? 0);

        if ($gatewayId <= 0) {
            return null;
        }

        $gateway = (new Gateway())->find($gatewayId);

        if ($gateway === null) {
            return null;
        }

        return (new Gateway())->driverFor($gateway) === null ? null : $gateway;
    }

    /**
     * Why a Payment Cannot Be Refunded Through Its Gateway, Or Null If It Can
     *
     * A REASON rather than a boolean, the same shape as `Promo::refusal()`:
     * each of these tells the operator a different thing to do next, and the
     * one that matters most - the processor is reachable but this payment has
     * no reference at it - is invisible in a boolean.
     * @param array $payment Transaction Row
     * @return ?string A reason key, or null when the gateway can be asked
     */
    public function gatewayRefusal(array $payment): ?string
    {
        if ((string) ($payment['type'] ?? '') !== Transaction::PAYMENT) {
            return 'not_a_payment';
        }

        if ((int) ($payment['gateway_relid'] ?? 0) <= 0) {
            return 'no_gateway';
        }

        if ($this->gatewayFor($payment) === null) {
            return 'no_driver';
        }

        if (trim((string) ($payment['transaction_ref'] ?? '')) === '') {
            // The processor identifies the original charge by its own
            // reference. Without one there is nothing to name in the API call,
            // and sending a blank refunds nothing or refunds something else.
            return 'no_reference';
        }

        return null;
    }

    ####################################################################################
    /*=================================== WRITING ====================================*/
    ####################################################################################

    /**
     * Ask The Processor To Send The Money Back
     *
     * The driver call comes FIRST and nothing is recorded unless it succeeds.
     *
     * WHAT THE GATEWAY SAYS IT REFUNDED IS WHAT GETS RECORDED, when it says
     * anything - 27.1's rule about the registry's answer, and for the same
     * reason: the processor is the system of record for money it moved, and a
     * ledger holding our request rather than their action is a ledger that
     * disagrees with the statement.
     * @param int|string $key Transaction ID Or Uid Of The Original Payment
     * @param int|float|string|null $amount Amount. Null refunds everything left
     * @param string $reason Why, for the gateway's records and ours
     * @return int New Transaction ID
     * @throws RuntimeException
     */
    public function throughGateway(int|string $key, int|float|string|null $amount = null, string $reason = ''): int
    {
        $payment = $this->payment($key);
        $refusal = $this->gatewayRefusal($payment);

        if ($refusal !== null) {
            throw new RuntimeException($this->refusalMessage($refusal));
        }

        $asking = $this->amountFor($payment, $amount);
        $gateway = $this->gatewayFor($payment);
        $driver = $gateway === null ? null : (new Gateway())->driverFor($gateway);

        if ($driver === null) {
            throw new RuntimeException($this->refusalMessage('no_driver'));
        }

        try {
            $result = $driver->refund(
                (string) $payment['transaction_ref'],
                $asking,
                $reason
            );
        } catch (Throwable $e) {
            // A module that throws and a module that returns false are two
            // shapes of the same answer - 22.4's finding. Neither one refunded
            // anybody, so neither one writes a row.
            throw new RuntimeException(
                'The payment processor could not be asked: ' . $e->getMessage()
            );
        }

        if (!is_array($result) || ($result['success'] ?? false) !== true) {
            throw new RuntimeException(
                trim((string) ($result['message'] ?? '')) !== ''
                    ? (string) $result['message']
                    : 'The payment processor refused the refund. Nothing has been recorded.'
            );
        }

        // What they say they sent, when they say. A gateway that returns a
        // different figure has partially refunded, and recording our request
        // instead would leave the difference invisible for ever.
        $sent = trim((string) ($result['amount'] ?? ''));
        $sent = $sent !== '' && Money::isGreater($sent, '0') ? Money::round($sent) : $asking;

        $id = (new Transaction())->refund($payment['tx_id'], $sent, $this->describe($reason, $sent, $asking));

        // The processor's own reference for the REFUND goes in gateway_data,
        // not in transaction_ref: that column carries the original payment's
        // reference so the row says which charge it reverses, which is the
        // behaviour GatewayCallbackSchema's docblock already depends on.
        (new Transaction())->recordGatewayData($id, [
            'refund_of'        =>  (int) $payment['tx_id'],
            'refund_method'    =>  self::GATEWAY,
            'refund_reference' =>  $result['reference'] ?? null,
            'requested'        =>  $asking,
            'raw'              =>  $result['raw'] ?? [],
        ]);

        // No restate() call here: Transaction::refund() moves the invoice as
        // part of writing the row, so the two cannot come apart.
        return $id;
    }

    /**
     * Record a Refund The Operator Has Already Made
     *
     * For money that went back some other way - a bank transfer, a cheque, a
     * reversal at the till. Deliberately a separate method with a separate
     * button, never reached by falling out of throughGateway().
     * @param int|string $key Transaction ID Or Uid Of The Original Payment
     * @param int|float|string|null $amount Amount. Null refunds everything left
     * @param string $reason Why
     * @return int New Transaction ID
     * @throws RuntimeException
     */
    public function byHand(int|string $key, int|float|string|null $amount = null, string $reason = ''): int
    {
        $payment = $this->payment($key);
        $asking = $this->amountFor($payment, $amount);

        $id = (new Transaction())->refund(
            $payment['tx_id'],
            $asking,
            trim($reason) !== '' ? trim($reason) : 'Refunded outside the system'
        );

        (new Transaction())->recordGatewayData($id, [
            'refund_of'     =>  (int) $payment['tx_id'],
            'refund_method' =>  self::MANUAL,
        ]);

        return $id;
    }

    /**
     * Give It Back As Account Credit Instead
     *
     * No money moves and no processor is involved: the customer keeps the value
     * and spends it on what they buy next. It is a credit note, so it is
     * Action\CreditNote that does the work - this only says which invoice and
     * how much, and refuses the amounts a refund would refuse.
     *
     * NOT recorded as a `refund` in the ledger, deliberately. Nothing has been
     * refunded - `Transaction::income()` subtracts refunds, and counting this
     * one would take money out of the income report that is still very much the
     * operator's.
     * @param int|string $key Transaction ID Or Uid Of The Original Payment
     * @param int|float|string|null $amount Amount. Null credits everything left
     * @param string $reason Why, for the customer to read on the note
     * @return int New Credit Note ID
     * @throws RuntimeException
     */
    public function toCredit(int|string $key, int|float|string|null $amount = null, string $reason = ''): int
    {
        $payment = $this->payment($key);
        $asking = $this->amountFor($payment, $amount);

        return (new CreditNote())->issue([
            'client_relid'   =>  (int) $payment['client_relid'],
            'invoice_relid'  =>  (int) $payment['invoice_relid'],
            'currency_relid' =>  (int) ($payment['currency_relid'] ?? 0),
            'amount'         =>  $asking,
            'reason'         =>  $reason,
        ]);
    }

    ####################################################################################
    /*=================================== HELPERS ====================================*/
    ####################################################################################

    /**
     * Move An Invoice To `refunded` Once Everything Has Gone Back
     *
     * Forwards only. A part refund leaves the status where it was, because the
     * customer still holds most of what they bought and the invoice still
     * records what they were charged for it.
     *
     * `amount_paid` is untouched - see the class docblock.
     * @param int $invoiceId Invoice ID
     * @return bool Whether the invoice moved
     */
    public function restate(int $invoiceId): bool
    {
        if ($invoiceId <= 0) {
            return false;
        }

        $invoices = new Invoice();
        $invoice = $invoices->find($invoiceId);

        if ($invoice === null) {
            return false;
        }

        $received = $this->receivedOn($invoice);
        $returned = $this->refundedOn($invoiceId);

        if (Money::isZero($received) || Money::isGreater($received, $returned)) {
            return false;
        }

        $status = $invoices->statusId('refunded');

        if ($status === null || (int) ($invoice['status_relid'] ?? 0) === $status) {
            return false;
        }

        return $invoices->update($invoiceId, ['status_relid' => $status]) > 0;
    }

    /**
     * Find The Payment, Or Say Why Not
     * @param int|string $key Transaction ID Or Uid
     * @return array
     * @throws RuntimeException
     */
    private function payment(int|string $key): array
    {
        $payment = (new Transaction())->find($key);

        if ($payment === null) {
            throw new RuntimeException('That transaction no longer exists.');
        }

        if ((string) ($payment['type'] ?? '') !== Transaction::PAYMENT) {
            throw new RuntimeException('Only a payment can be refunded.');
        }

        if ((int) ($payment['invoice_relid'] ?? 0) <= 0) {
            // Without an invoice there is no document to credit and nothing
            // that can tell a second refund of this payment from the first, so
            // the cap below would not hold. An operator with a payment like
            // this records the movement on the transactions screen instead.
            throw new RuntimeException(
                'This payment is not against an invoice, so there is nothing to refund it on.'
            );
        }

        return $payment;
    }

    /**
     * What Will Actually Be Refunded, Or Why It Cannot Be
     * @param array $payment Payment Row
     * @param int|float|string|null $amount Requested Amount, Or Null For All Of It
     * @return string Decimal string
     * @throws RuntimeException
     */
    private function amountFor(array $payment, int|float|string|null $amount): string
    {
        $left = $this->refundableOn($payment);

        if (Money::isZero($left)) {
            throw new RuntimeException('There is nothing left to refund on this payment.');
        }

        $asking = $amount === null ? $left : Money::round((string) $amount);

        if (!Money::isGreater($asking, '0')) {
            throw new RuntimeException('A refund has to be for more than zero.');
        }

        if (Money::isGreater($asking, $left)) {
            throw new RuntimeException(
                'That is more than is left to refund on this payment (' . Money::format($left) . ').'
            );
        }

        return $asking;
    }

    /**
     * The Description a Refund Row Carries
     *
     * Says so out loud when the processor sent back less than was asked for.
     * The figures are both in the ledger either way; the sentence is what stops
     * somebody having to notice.
     * @param string $reason The operator's own words
     * @param string $sent What the gateway said it refunded
     * @param string $asking What was requested
     * @return string
     */
    private function describe(string $reason, string $sent, string $asking): string
    {
        $text = trim($reason);

        if (Money::round($sent) !== Money::round($asking)) {
            $note = 'The processor refunded ' . Money::format($sent)
                . ' of the ' . Money::format($asking) . ' requested.';

            return $text !== '' ? $text . ' ' . $note : $note;
        }

        return $text;
    }

    /**
     * Say Why The Gateway Cannot Be Asked
     *
     * English, not local(). Action classes are driven by cron as well as by a
     * controller and no catalogue is loaded there, so local() would throw and
     * mask the real error - the split the constraints section spells out.
     * @param string $reason Reason Key
     * @return string
     */
    private function refusalMessage(string $reason): string
    {
        return match ($reason) {
            'not_a_payment' => 'Only a payment can be refunded.',
            'no_gateway'    => 'This payment did not come through a gateway, so there is nothing to '
                . 'send it back through. Refund it the way it was taken and record that instead.',
            'no_driver'     => 'The gateway this payment came through is no longer installed or '
                . 'switched on, so it cannot be asked for a refund.',
            'no_reference'  => 'This payment has no reference at the gateway, so the processor has '
                . 'no way to tell which charge to reverse.',
            default         => 'This payment cannot be refunded through its gateway.',
        };
    }
}
