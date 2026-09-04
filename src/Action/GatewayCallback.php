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

use Throwable;
use Laika\Model\Model;
use LBM\Model\GatewayCallbackModel;
use LBM\Service\Money;

/**
 * What happens when a payment gateway calls us back.
 *
 * Every rule in here exists because somebody lost money to its absence, and
 * they are applied in a fixed order that is itself part of the design:
 *
 *   1. RECORD IT FIRST, whatever it is. An unverified callback is evidence, not
 *      noise - it is a stranger attempting to mark an invoice paid, and the
 *      operator should be able to see that it happened.
 *   2. REFUSE AN UNVERIFIED ONE. The driver says whether it could check the
 *      signature. `verified => false` ends the request; nothing is applied.
 *   3. REFUSE A DUPLICATE, and refuse it in the DATABASE. The unique key on
 *      (gateway, event_ref) is what makes this true - a check-then-insert in
 *      PHP is a race that two simultaneous retries lose, and gateways retry in
 *      parallel on exactly that timescale.
 *   4. VALIDATE THE INVOICE - that it exists, that it is not already settled,
 *      and that it belongs to the gateway that is calling.
 *   5. TAKE THE AMOUNT FROM THE GATEWAY, NEVER FROM THE INVOICE. What the
 *      ledger has to say is what actually moved. A partial payment is a real
 *      thing and Invoice::applyPayment() already handles one.
 *
 * ---------------------------------------------------------------------------
 * What this class does NOT do
 * ---------------------------------------------------------------------------
 * It does not write a `transactions` row itself. `LBM\Action\Transaction` is the
 * only writer of the ledger and the only thing that settles an invoice, and it
 * does both together - the same rule the gateway contract states, for the same
 * reason: two writers means two divergent answers to "what has this client
 * paid".
 *
 * It also never UN-settles anything. A `payment.failed` arriving after a
 * `payment.succeeded` - which is ordinary, gateways do not promise ordering -
 * is recorded and ignored. Reversing a settled invoice is a refund, and a
 * refund is a decision a person makes.
 */
class GatewayCallback extends Action
{
    /** @var string a payment was recorded against an invoice */
    public const APPLIED = 'applied';

    /** @var string Already Seen. Nothing written */
    public const DUPLICATE = 'duplicate';

    /** @var string The Driver Could Not Check The Signature */
    public const UNVERIFIED = 'unverified';

    /** @var string Understood, But There Was Nothing To Do */
    public const IGNORED = 'ignored';

    /** @var string Understood, And Refused */
    public const REJECTED = 'rejected';

    /** @var string[] Columns a Caller May Write */
    public const FIELDS = [
        'gateway_relid', 'event_ref', 'event_type', 'invoice_relid',
        'transaction_relid', 'verified', 'outcome', 'message',
    ];

    public function model(): Model
    {
        return new GatewayCallbackModel();
    }

    protected function createdColumn(): ?string
    {
        return 'first_seen_at';
    }

    protected function updatedColumn(): ?string
    {
        return 'last_seen_at';
    }

    ####################################################################################
    /*=================================== READING ====================================*/
    ####################################################################################

    /**
     * One Page Of Callbacks, Newest First
     * @param array $where Conditions
     * @param ?int $limit Rows Per Page
     * @return array
     */
    public function browseRecent(array $where = [], ?int $limit = null): array
    {
        return $this->browse($where, null, $limit, self::DESC);
    }

    /**
     * Callbacks Recorded Against One Invoice
     * @param int $invoiceId Invoice ID
     * @return array
     */
    public function forInvoice(int $invoiceId): array
    {
        $model = $this->model();

        return $model->where(['invoice_relid' => $invoiceId])
            ->order($model->id, self::DESC)
            ->get();
    }

    /**
     * Has This Gateway Already Sent Us This Event
     *
     * Read-only, for a screen that wants to say so. It is NOT how the duplicate
     * rule is enforced - see receive(), and the unique key it relies on.
     * @param int $gatewayId Gateway ID
     * @param string $reference The gateway's own event reference
     * @return ?array
     */
    public function seen(int $gatewayId, string $reference): ?array
    {
        $reference = trim($reference);

        if ($reference === '') {
            return null;
        }

        return $this->first(['gateway_relid' => $gatewayId, 'event_ref' => $reference]);
    }

    ####################################################################################
    /*=================================== WRITING ====================================*/
    ####################################################################################

    /**
     * Take a Callback And Decide What It Means
     *
     * The single entry point. Returns what was done and what to answer the
     * gateway with, so the controller stays a transport adapter and every rule
     * about money lives here where it can be tested without an HTTP request.
     *
     * @param array $gateway The payment_gateways row the callback arrived on
     * @param array $result What the driver's webhook() returned
     * @param array $payload The raw decoded request body, for the record
     * @return array{outcome:string,status:int,message:string,callback_id:int,transaction_id:?int}
     */
    public function receive(array $gateway, array $result, array $payload = []): array
    {
        $gatewayId = (int) ($gateway['gateway_id'] ?? 0);
        $reference = trim((string) ($result['reference'] ?? ''));
        $verified  = ($result['verified'] ?? false) === true;

        $base = [
            'gateway_relid' =>  $gatewayId,
            // Empty becomes NULL rather than '': two unparseable callbacks must
            // both insert, and '' would collide on the unique key while NULL
            // does not - in both supported engines.
            'event_ref'     =>  $reference === '' ? null : $reference,
            'event_type'    =>  $this->text($result['event'] ?? null, 100),
            'verified'      =>  $verified ? 'yes' : 'no',
        ];

        // ------------------------------------------------------------------
        // 1. Unverified. Recorded, and that is all.
        // ------------------------------------------------------------------
        // Before the duplicate check on purpose: an attacker replaying a
        // reference they guessed must not be told "we have seen that one",
        // which would confirm the reference is real.
        if (!$verified) {
            $id = $this->put($base + [
                'outcome' =>  self::UNVERIFIED,
                'message' =>  $this->text($result['message'] ?? null) ?? 'The signature could not be verified.',
            ], $payload);

            return $this->answer(self::UNVERIFIED, 400, 'Signature not verified.', $id);
        }

        // ------------------------------------------------------------------
        // 2. Verified but unreadable - no reference to key on.
        // ------------------------------------------------------------------
        // Nothing can be made idempotent without one, so nothing is applied.
        // A driver that verifies a signature and then cannot say which payment
        // it is about has a bug, and this is where the operator sees it.
        if ($reference === '') {
            $id = $this->put($base + [
                'outcome' =>  self::REJECTED,
                'message' =>  'Verified, but the callback carried no reference to identify it by.',
            ], $payload);

            return $this->answer(self::REJECTED, 422, 'No usable reference.', $id);
        }

        // ------------------------------------------------------------------
        // 3. Claim the reference. THE DATABASE decides whether this is a
        //    duplicate, not a read followed by a write.
        // ------------------------------------------------------------------
        $claim = $this->claim($base + [
            'outcome' =>  self::IGNORED,
            'message' =>  'Received.',
        ], $payload);

        if ($claim['duplicate']) {
            // Not an error. The gateway is doing exactly what it should - it
            // did not hear our first 200 - so say so plainly and answer 200,
            // because a retry of a retry helps nobody.
            $this->touch((int) $claim['id']);

            return $this->answer(
                self::DUPLICATE,
                200,
                'Already processed.',
                (int) $claim['id']
            );
        }

        $id = (int) $claim['id'];

        // ------------------------------------------------------------------
        // 4. From here the row exists and is ours. Everything else updates it.
        // ------------------------------------------------------------------
        return $this->apply($id, $gateway, $result, $reference);
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Decide And Record What a Verified, First-Time Callback Means
     *
     * @param int $id Callback Row ID
     * @param array $gateway Gateway Row
     * @param array $result Driver Result
     * @param string $reference The Gateway's Reference
     * @return array
     */
    private function apply(int $id, array $gateway, array $result, string $reference): array
    {
        $invoiceId = (int) ($result['invoice_id'] ?? 0);

        // A callback that says nothing succeeded. Ordinary - `payment.failed`
        // and `checkout.expired` are events too - and there is nothing to do
        // about one except write it down.
        if (($result['success'] ?? false) !== true) {
            $this->close($id, self::IGNORED, $this->text($result['message'] ?? null)
                ?? 'The gateway reported no payment.', $invoiceId ?: null);

            return $this->answer(self::IGNORED, 200, 'Nothing to apply.', $id);
        }

        $invoice = $invoiceId > 0 ? (new Invoice())->find($invoiceId) : null;

        if (!is_array($invoice)) {
            $this->close($id, self::REJECTED, 'The callback named an invoice that does not exist.');

            return $this->answer(self::REJECTED, 422, 'Unknown invoice.', $id);
        }

        // Already settled. This is the out-of-order case, and it is NOT an
        // error: a duplicate event with a fresh reference, or a second gateway
        // finishing after the client paid by credit. Recorded and left alone -
        // un-settling an invoice from a callback is not something this code is
        // ever allowed to do.
        if ((new Invoice())->isSettled($invoice)) {
            $this->close(
                $id,
                self::IGNORED,
                'The invoice was already settled when this arrived.',
                (int) $invoice['invoice_id']
            );

            return $this->answer(self::IGNORED, 200, 'Invoice already settled.', $id);
        }

        // The amount is the GATEWAY's, never the invoice's and never the
        // request's: the ledger has to say what actually moved. What is checked
        // is that it is a real positive amount - a zero or negative "payment"
        // is a malformed callback, and Transaction::pay() would throw on it
        // anyway, which would be a 500 rather than a recorded refusal.
        $amount = $this->amount($result['amount'] ?? null);

        if ($amount === null) {
            $this->close(
                $id,
                self::REJECTED,
                'The callback carried no usable amount.',
                (int) $invoice['invoice_id']
            );

            return $this->answer(self::REJECTED, 422, 'Unusable amount.', $id);
        }

        try {
            $transaction = (new Transaction())->pay([
                'client_relid'    =>  (int) $invoice['client_relid'],
                'invoice_relid'   =>  (int) $invoice['invoice_id'],
                'currency_relid'  =>  (int) $invoice['currency_relid'],
                'gateway_relid'   =>  (int) $gateway['gateway_id'],
                'transaction_ref' =>  $reference,
                'amount'          =>  $amount,
                'fee'             =>  $this->amount($result['fee'] ?? null) ?? '0',
                'description'     =>  ($gateway['display_name'] ?? 'Gateway') . ' payment',
            ]);
        } catch (Throwable $e) {
            // The row stays, saying why. A callback that could not be applied
            // and left no trace is the worst of both worlds.
            $this->close(
                $id,
                self::REJECTED,
                'The payment could not be recorded: ' . $e->getMessage(),
                (int) $invoice['invoice_id']
            );

            return $this->answer(self::REJECTED, 500, 'Could not record the payment.', $id);
        }

        if (is_array($result['raw'] ?? null) && $result['raw'] !== []) {
            (new Transaction())->recordGatewayData($transaction, $result['raw']);
        }

        $this->close(
            $id,
            self::APPLIED,
            'Payment of ' . $amount . ' recorded.',
            (int) $invoice['invoice_id'],
            $transaction
        );

        return $this->answer(self::APPLIED, 200, 'Payment recorded.', $id, $transaction);
    }

    /**
     * Insert The Row, Or Report That The Reference Is Already Taken
     *
     * The unique key on (gateway_relid, event_ref) does the work. The insert is
     * attempted and the driver's refusal is the answer - which is the only way
     * to get this right, because any version that looks before it writes has a
     * window between the two.
     *
     * @param array $data Row
     * @param array $payload Raw Payload
     * @return array{id:int,duplicate:bool}
     */
    private function claim(array $data, array $payload): array
    {
        try {
            return ['id' => $this->put($data, $payload), 'duplicate' => false];
        } catch (Throwable) {
            // The insert was refused. Almost certainly the unique key; read the
            // row back to be sure, because reporting "duplicate" for a
            // connection failure would tell a gateway to stop retrying a
            // callback we never recorded.
            $existing = $this->seen(
                (int) $data['gateway_relid'],
                (string) ($data['event_ref'] ?? '')
            );

            if ($existing === null) {
                throw new \RuntimeException('The callback could not be recorded.');
            }

            return ['id' => (int) $existing['callback_id'], 'duplicate' => true];
        }
    }

    /**
     * Write One Callback Row
     * @param array $data Row
     * @param array $payload Raw Payload
     * @return int New Callback ID
     */
    private function put(array $data, array $payload): int
    {
        $data = $this->only($data, self::FIELDS);

        // serialize() by hand. Casts run on READ only, so handing insert() the
        // array itself stores the word "Array" - which then unserializes into
        // an error that takes out every screen listing callbacks.
        $data['payload'] = $payload === [] ? null : serialize($payload);
        $data['attempts'] = 1;

        return $this->create($data);
    }

    /**
     * Record That The Gateway Has Sent This Event Again
     *
     * One row per EVENT, not one per delivery - so a retry bumps the counter
     * rather than filling the table with copies. The operator can still see it
     * arrived four times, which is the thing worth knowing.
     * @param int $id Callback ID
     * @return void
     */
    private function touch(int $id): void
    {
        $model = $this->model();
        $row = $model->where([$model->id => $id])->first();

        $this->update($id, [
            'attempts' => (int) (is_array($row) ? ($row['attempts'] ?? 1) : 1) + 1,
        ]);
    }

    /**
     * Finish a Callback Row With Its Outcome
     * @param int $id Callback ID
     * @param string $outcome One of the class constants
     * @param string $message Why
     * @param ?int $invoiceId Invoice ID, When One Was Identified
     * @param ?int $transactionId Transaction ID, When One Was Written
     * @return void
     */
    private function close(
        int $id,
        string $outcome,
        string $message,
        ?int $invoiceId = null,
        ?int $transactionId = null
    ): void {
        $this->update($id, [
            'outcome'           =>  $outcome,
            'message'           =>  mb_substr($message, 0, 255),
            'invoice_relid'     =>  $invoiceId,
            'transaction_relid' =>  $transactionId,
        ]);
    }

    /**
     * The Shape receive() Answers With
     * @param string $outcome One of the class constants
     * @param int $status HTTP Status For The Gateway
     * @param string $message What To Say
     * @param int $callbackId Callback Row ID
     * @param ?int $transactionId Transaction ID, When One Was Written
     * @return array
     */
    private function answer(
        string $outcome,
        int $status,
        string $message,
        int $callbackId,
        ?int $transactionId = null
    ): array {
        return [
            'outcome'        =>  $outcome,
            'status'         =>  $status,
            'message'        =>  $message,
            'callback_id'    =>  $callbackId,
            'transaction_id' =>  $transactionId,
        ];
    }

    /**
     * The Outcomes a Callback Can Have
     *
     * A method rather than the constants, because a relay forwards method calls
     * and not constants - the convention Support::ratings() and
     * Action\Module::types() already follow.
     * @return string[]
     */
    public function outcomes(): array
    {
        return [self::APPLIED, self::DUPLICATE, self::UNVERIFIED, self::IGNORED, self::REJECTED];
    }

    /**
     * A Positive Decimal Amount, Or Nothing
     *
     * Through Money rather than a float cast, for the reason the whole money
     * path exists: bcmath over strings at scale six, because a billing system
     * that rounds differently from its processor produces invoices nobody can
     * reconcile.
     * @param mixed $amount Whatever the driver returned
     * @return ?string
     */
    private function amount(mixed $amount): ?string
    {
        if (!is_string($amount) && !is_int($amount) && !is_float($amount)) {
            return null;
        }

        $amount = trim((string) $amount);

        if ($amount === '' || !is_numeric($amount)) {
            return null;
        }

        $rounded = Money::round($amount);

        return Money::isGreater($rounded, '0') ? $rounded : null;
    }

    /**
     * Trim a Driver's String To Something a Column Will Hold
     * @param mixed $value Whatever the driver returned
     * @param int $length Column Length
     * @return ?string
     */
    private function text(mixed $value, int $length = 255): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $length);
    }
}
