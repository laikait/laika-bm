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

namespace LBM\Controller\Client;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use RuntimeException;
use Laika\Service\Redirect;
use Laika\Service\Request;
use LBM\Service\Gateway;
use LBM\Service\GatewayCallback;
use LBM\Service\Invoice;
use LBM\Service\Money;
use LBM\Service\PayMethod;
use LBM\Service\Transaction;

/**
 * A client's invoices.
 *
 * Read, print, and settle from account credit. Everything here is scoped by
 * ownership through Invoice::forClientKey(), so another account's invoice uid
 * is not found rather than found and refused.
 */
class InvoiceController extends ClientController
{
    protected function nav(): string
    {
        return 'invoices';
    }

    /**
     * The Client's Invoices
     * @return string
     */
    public function index(): string
    {
        $this->allow('invoice');

        $clientId = $this->owner();

        $page = Invoice::browseForClient(
            $clientId,
            $this->conditions(['status' => 'status_relid'])
        );

        // The balance is not a column - it is the total less what has been paid
        // and credited - so it is worked out once here rather than in the view.
        foreach ($page['rows'] as $index => $row) {
            $page['rows'][$index]['balance'] = Invoice::balance($row);
            $page['rows'][$index]['overdue'] = Invoice::isOverdue($row);
        }

        return $this->screen('invoices', local('my_invoices'), [
            'pager'       =>  $page,
            'statuses'    =>  Invoice::statuses(),
            'outstanding' =>  Invoice::outstandingFor($clientId),
        ]);
    }

    /**
     * One Invoice
     * @param string $invoice Invoice Uid
     * @return string
     */
    public function show(string $invoice): string
    {
        $this->allow('invoice');

        $row = $this->invoice($invoice);
        $id = (int) $row['invoice_id'];

        return $this->screen('invoice', local('invoice_titled', $row['invoice_number']), [
            'invoice'      =>  $row,
            'items'        =>  Invoice::items($id),
            'transactions' =>  Transaction::forInvoice($id),
            'balance'      =>  Invoice::balance($row),
            'tax_amount'   =>  Invoice::taxAmount($row),
            // One row per rate charged. An invoice raised by the shop
            // carries its rates on the LINES, so `invoice.tax` is 0 on it
            // and a template keyed on that would hide the tax entirely.
            'tax_bands'    =>  Invoice::taxBreakdown((int) $row['invoice_id']),
            'settled'      =>  Invoice::isSettled($row),
            'overdue'      =>  Invoice::isOverdue($row),
            'credit'       =>  $this->creditAvailable(),

            // Only gateways that are active AND whose driver actually builds.
            // An empty list is an ordinary state - a fresh installation has none
            // configured - and the view says so rather than offering a button
            // that cannot take money.
            'gateways'     =>  $this->withKinds(Gateway::payable()),

            // Phase 48: the client's saved cards that can be charged now, and a
            // card field for every gateway on offer that takes the card here.
            'cards'        =>  PayMethod::forClient($this->owner()),
            'card_fields'  =>  $this->cardFields($row),
        ]);
    }

    /**
     * Start Paying an Invoice Through a Gateway
     *
     * What this does NOT do is settle the invoice. The gateway reports what
     * happened and `LBM\Action\Transaction` records it; a driver that returns
     * `pending` - every offline one does, because the money has not moved yet -
     * leaves the invoice exactly as it was. An invoice marked paid on the
     * customer's say-so is the one mistake a billing system may not make.
     *
     * A redirecting gateway comes back with a `redirect` URL and confirms later
     * through its own webhook route, which is 22.3.
     * @param string $invoice Invoice Uid
     * @return ?string
     */
    public function checkout(string $invoice): ?string
    {
        $this->allow('invoice', self::UPDATE);

        $row = $this->invoice($invoice);

        // done() rather than attempt(), deliberately. attempt() fixes its success
        // message before the work runs, and the message here IS the outcome -
        // "here is how to pay us" for a pending gateway, "that is settled" for
        // one that took the money. A fixed string could only be vague enough to
        // cover both, which on a payment screen is the wrong kind of tidy.
        try {
            if (Invoice::isSettled($row)) {
                throw new RuntimeException(local('invoice_already_settled'));
            }

            // Read from the form, then looked up among the PAYABLE gateways
            // only. A slug that is not on offer is refused exactly like no slug
            // at all - the customer chooses from a list, they do not get to
            // name a class.
            //
            // Phase 48: or a SAVED card, which names its own gateway. It is
            // found through the client's own cards, so another account's is not
            // found rather than found and refused.
            $saved = null;
            $card = trim((string) Request::input('pay_method', ''));

            if ($card !== '') {
                $saved = PayMethod::forClientKey($this->owner(), $card);

                if ($saved === null) {
                    throw new RuntimeException(local('card_not_found'));
                }

                // The card names its own gateway, so a gateway the form also
                // posted is not read: its dropdown sits beside the card list and
                // posts its first option whatever the customer chose above it.
                $gateway = PayMethod::gatewayOf($saved);
            } else {
                $gateway = Gateway::payableBySlug((string) Request::input('gateway', ''));
            }

            if ($gateway === null) {
                throw new RuntimeException(local($saved === null ? 'choose_a_payment_method' : 'payment_method_unavailable'));
            }

            $driver = Gateway::driverFor($gateway);

            if ($driver === null) {
                throw new RuntimeException(local('payment_method_unavailable'));
            }

            // A gateway that takes the card on the site is handed the TOKEN its
            // provider's field made. No field of this form carries a card number
            // and nothing here reads one: a post without a token is refused
            // before anything is sent.
            $tokenizer = Gateway::isTokenizer($gateway);
            $token = trim((string) Request::input('token', ''));

            if ($tokenizer && $saved === null && $token === '') {
                throw new RuntimeException(local('card_details_missing'));
            }

            // The invoice row carries `currency_relid`, not a code - it is read
            // bare by forClientKey() - so every gateway was handed an empty
            // currency from Phase 22.1 until a real one needed it (Phase 47).
            // Only the invoice's own currency: falling back to the install's
            // default would charge the right number in the wrong money.
            $currencyId = (int) ($row['currency_relid'] ?? 0);
            $currency = $currencyId > 0 ? Money::get($currencyId) : null;
            $balance = Invoice::balance($row);
            $cardToken = $saved !== null ? PayMethod::token($saved) : $token;

            // The amount comes from the invoice, never from the request.
            $result = $driver->charge([
                'amount'      =>  $balance,
                'currency'    =>  (string) ($currency['currency_code'] ?? ''),
                'invoice_id'  =>  (int) $row['invoice_id'],
                'client_id'   =>  (int) $row['client_relid'],
                'description' =>  (string) $row['invoice_number'],
                'client'      =>  $this->client(),
                'return_url'  =>  $tokenizer
                    ? named('client.invoice.card.return', ['invoice' => $row['uid'], 'gateway' => (string) $gateway['gateway_slug']])
                    : named('client.invoice', ['invoice' => $row['uid']]),
                'token'       =>  $tokenizer && $saved === null ? $token : '',
                'pay_method'  =>  $tokenizer && $saved !== null ? $cardToken : '',
                'save'        =>  $tokenizer && $saved === null && (string) Request::input('save_card', '') !== '',
                'off_session' =>  false,
                'attempt_key' =>  $tokenizer ? Gateway::attemptKey((int) $row['invoice_id'], $balance, $cardToken) : '',
            ]);

            $outcome = $this->finishCharge($row, $gateway, $result);
        } catch (RuntimeException $e) {
            return $this->done('client.invoice', $e->getMessage(), false, ['invoice' => $row['uid']]);
        }

        // A redirecting gateway takes the customer to its own pages, and its
        // real outcome arrives later at the module's webhook route (22.3).
        // Redirect::to() sends an absolute URL straight out - it checks for a
        // host before treating the argument as a route name.
        //
        // Stripe Checkout sends the customer to Stripe, and Stripe Card sends
        // them to their bank for 3-D Secure (Phase 48) - both through here. A
        // silently ignored redirect would strand the customer on a screen
        // saying the payment had started when it had not.
        if (trim((string) ($result['redirect'] ?? '')) !== '') {
            Redirect::to((string) $result['redirect']);

            return null;
        }

        return $this->done('client.invoice', $outcome, true, ['invoice' => $row['uid']]);
    }

    /**
     * Decide What a Driver's Answer Means
     *
     * Three outcomes, and only one of them touches the invoice:
     *
     *   success  - the money moved. Transaction::pay() writes the ledger row and
     *              settles the invoice, together, because a payment that exists
     *              without the invoice knowing is worse than neither.
     *   pending  - nothing has moved yet. The customer is told what to do next,
     *              and the invoice is left exactly as it was. Every offline
     *              gateway lives here, and so does a redirect before its webhook
     *              arrives. `pending: true` outranks `success`, so a driver
     *              answering both lands here too.
     *   failure  - say so, and record nothing.
     *
     * @param array $invoice Invoice Row
     * @param array $gateway Gateway Row
     * @param array $result What the driver returned
     * @return string What to tell the customer
     * @throws RuntimeException
     */
    private function finishCharge(array $invoice, array $gateway, array $result): string
    {
        $message = trim((string) ($result['message'] ?? ''));

        // PENDING OUTRANKS SUCCESS. `pending: true` means no money has moved,
        // whatever `success` says: a redirect driver answering "success,
        // pending" means the CALL worked, and reading that as payment marks an
        // invoice paid because somebody was sent to a page. GatewayInterface
        // states the rule.
        $pending = ($result['pending'] ?? false) === true;

        if (!$pending && ($result['success'] ?? false) === true) {
            $this->record($invoice, $gateway, $result);

            // Phase 48: a card the customer asked to keep comes back with the
            // payment. Keeping it cannot undo the payment, so a refusal here is
            // said beside the thank-you rather than instead of it.
            $kept = $this->keepCard($gateway, $result);

            $this->log(
                'invoice.paid',
                'Paid invoice ' . $invoice['invoice_number'] . ' through ' . $gateway['display_name'] . '.'
            );

            return local('invoice_paid_through', $gateway['display_name']) . ($kept === null ? '' : ' ' . $kept);
        }

        if (($result['pending'] ?? false) === true) {
            $this->log(
                'invoice.payment.started',
                'Started paying invoice ' . $invoice['invoice_number']
                    . ' through ' . $gateway['display_name'] . '. Nothing has been settled.'
            );

            // The instructions ARE the outcome for an offline gateway, so they
            // are returned rather than swallowed. Nothing was recorded and the
            // invoice is untouched, which is the whole point.
            return $message !== ''
                ? local('payment_instructions', $gateway['display_name'], $message)
                : local('payment_started');
        }

        throw new RuntimeException($message !== '' ? $message : local('payment_failed'));
    }

    /**
     * A Printable Invoice
     *
     * Its own layout rather than a print stylesheet on the normal one: what a
     * person wants on paper is the document, not the page it was shown on, and
     * a sidebar hidden by @media print is still in the DOM for anything that
     * reads the page some other way.
     * @param string $invoice Invoice Uid
     * @return string
     */
    public function print(string $invoice): string
    {
        $this->allow('invoice');

        $row = $this->invoice($invoice);
        $id = (int) $row['invoice_id'];

        return $this->render('invoice-print', [
            'page_title'   =>  local('invoice_titled', $row['invoice_number']),
            'client'       =>  $this->client(),
            'invoice'      =>  $row,
            'items'        =>  Invoice::items($id),
            'transactions' =>  Transaction::forInvoice($id),
            'balance'      =>  Invoice::balance($row),
            'tax_amount'   =>  Invoice::taxAmount($row),
            // One row per rate charged. An invoice raised by the shop
            // carries its rates on the LINES, so `invoice.tax` is 0 on it
            // and a template keyed on that would hide the tax entirely.
            'tax_bands'    =>  Invoice::taxBreakdown((int) $row['invoice_id']),
            'settled'      =>  Invoice::isSettled($row),
        ]);
    }

    /**
     * Settle An Invoice From Account Credit
     *
     * The one payment a client makes without a gateway, and that is
     * deliberate. Credit is money the operator has already received and
     * recorded; spending it against an invoice moves a number that is provably
     * theirs. Anything else - a card, a bank transfer - is a gateway's job:
     * see checkout() and cardReturn(), and Action\GatewayCallback.
     *
     * What this route must never become is "the client says they paid": a form
     * that let somebody record their own payment would let anyone mark their
     * own invoice settled. A gateway payment is recorded from the gateway's
     * callback, or from the charge the product itself made - never from a form
     * the payer controls.
     * @param string $invoice Invoice Uid
     * @return ?string
     */
    public function pay(string $invoice): ?string
    {
        $this->allow('invoice', self::UPDATE);

        $row = $this->invoice($invoice);
        $id = (int) $row['invoice_id'];

        return $this->attempt(
            function () use ($row, $id): void {
                if (Invoice::isSettled($row)) {
                    throw new RuntimeException(local('invoice_already_settled'));
                }

                $applied = Invoice::applyCredit($id);

                if (Money::isZero($applied)) {
                    throw new RuntimeException(
                        local('no_credit_available')
                    );
                }

                $this->log(
                    'invoice.credit.applied',
                    'Put ' . Money::format($applied, $row['currency_relid'] ?? null)
                    . ' of account credit towards invoice ' . $row['invoice_number'] . '.'
                );
            },
            'client.invoice',
            local('credit_applied_to_invoice'),
            ['invoice' => $row['uid']]
        );
    }

    /**
     * Back From The Bank After 3-D Secure - Phase 48
     *
     * A GET, because it is the bank's browser redirect. It changes nothing the
     * gateway has not confirmed: complete() asks the provider what happened to
     * the payment the query string names, and refuses one made for another
     * invoice. What it answers goes through finishCharge() like any charge, so
     * a webhook that already recorded it makes this a duplicate, not a second
     * payment.
     * @param string $invoice Invoice Uid
     * @param string $gateway Gateway Slug
     * @return ?string
     */
    public function cardReturn(string $invoice, string $gateway): ?string
    {
        $this->allow('invoice', self::UPDATE);

        $row = $this->invoice($invoice);
        $params = ['invoice' => $row['uid']];
        $chosen = Gateway::payableBySlug($gateway);
        $driver = $chosen === null ? null : Gateway::tokenizerFor($chosen);

        if ($driver === null) {
            return $this->done('client.invoice', local('payment_method_unavailable'), false, $params);
        }

        try {
            $result = $driver->complete(Request::inputs(), [
                'purpose'    =>  'payment',
                'invoice_id' =>  (int) $row['invoice_id'],
                'client_id'  =>  $this->owner(),
            ]);

            // Back from the bank, a second request to go there is a no.
            $result['redirect'] = null;

            $outcome = $this->finishCharge($row, $chosen, $result);
        } catch (RuntimeException $e) {
            return $this->done('client.invoice', $e->getMessage(), false, $params);
        } catch (\Throwable) {
            return $this->done('client.invoice', local('payment_failed'), false, $params);
        }

        return $this->done('client.invoice', $outcome, true, $params);
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Record Money a Gateway Says It Took - Once
     *
     * Through GatewayCallback, keyed on the gateway's reference: a tokenizer
     * knows the answer at once and its webhook says it again, and the ledger's
     * UNIQUE (gateway, reference) lets exactly one of them record it. Applied,
     * duplicate and ignored (already settled) all mean the money is recorded.
     *
     * A success with NO reference is recorded directly, as before this phase -
     * refusing to record money that moved is worse than a missing duplicate
     * guard - and the activity log says so.
     * @param array $invoice Invoice Row
     * @param array $gateway Gateway Row
     * @param array $result The driver's answer
     * @return void
     * @throws RuntimeException
     */
    private function record(array $invoice, array $gateway, array $result): void
    {
        $reference = trim((string) ($result['reference'] ?? ''));

        if ($reference !== '') {
            $answer = GatewayCallback::recordCharge($gateway, $invoice, $result, 'panel');

            if (!in_array((string) ($answer['outcome'] ?? ''), ['applied', 'duplicate', 'ignored'], true)) {
                throw new RuntimeException(local('payment_not_recorded', (string) ($answer['message'] ?? '')));
            }

            return;
        }

        // The amount recorded is the driver's, not the request's, and not
        // the invoice's: a gateway may have taken a different sum, and the
        // ledger has to say what actually happened.
        $amount = (string) ($result['amount'] ?? Invoice::balance($invoice));

        $transaction = Transaction::pay([
            'client_relid'    =>  (int) $invoice['client_relid'],
            'invoice_relid'   =>  (int) $invoice['invoice_id'],
            'currency_relid'  =>  (int) $invoice['currency_relid'],
            'gateway_relid'   =>  (int) $gateway['gateway_id'],
            'transaction_ref' =>  '',
            'amount'          =>  $amount,
            'fee'             =>  (string) ($result['fee'] ?? '0'),
            'description'     =>  $gateway['display_name'] . ' payment',
        ]);

        if (is_array($result['raw'] ?? null) && $result['raw'] !== []) {
            Transaction::recordGatewayData($transaction, $result['raw']);
        }

        $this->log(
            'invoice.payment.unreferenced',
            $gateway['display_name'] . ' took a payment for invoice ' . $invoice['invoice_number']
                . ' and gave no reference, so it could not be checked against a second report of it.'
        );
    }

    /**
     * Keep a Card The Customer Asked To Save
     * @param array $gateway Gateway Row
     * @param array $result The driver's answer, carrying `saved`
     * @return ?string What to add to the message, if anything
     */
    private function keepCard(array $gateway, array $result): ?string
    {
        if (!is_array($result['saved'] ?? null)) {
            return null;
        }

        try {
            PayMethod::store($this->owner(), $gateway, $result['saved']);
        } catch (RuntimeException $e) {
            return local('card_not_saved', $e->getMessage());
        }

        return local('card_saved_too');
    }

    /**
     * The Payable Gateways, Each Saying Which Kind It Is
     * @param array $gateways Gateway Rows
     * @return array
     */
    private function withKinds(array $gateways): array
    {
        foreach ($gateways as $index => $gateway) {
            $gateways[$index]['kind'] = Gateway::kindOf($gateway);
        }

        return $gateways;
    }

    /**
     * A Card Field For Every Gateway On Offer That Takes The Card On The Site
     * @param array $row Invoice Row
     * @return array
     */
    private function cardFields(array $row): array
    {
        $currencyId = (int) ($row['currency_relid'] ?? 0);
        $currency = $currencyId > 0 ? Money::get($currencyId) : null;
        $fields = [];

        foreach (Gateway::payable() as $gateway) {
            if (!Gateway::isTokenizer($gateway)) {
                continue;
            }

            $field = Gateway::cardField($gateway, [
                'purpose'  => 'payment',
                'amount'   => Invoice::balance($row),
                'currency' => (string) ($currency['currency_code'] ?? ''),
                'client'   => $this->client() ?? [],
            ]);

            if ($field !== null) {
                $field['script_url'] = named('client.gateway.script', ['gateway' => (string) $gateway['gateway_slug']]);
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * Resolve One Of The Client's Own Invoices, Or 404
     * @param string $uid Invoice Uid
     * @return array
     */
    private function invoice(string $uid): array
    {
        return $this->mine(
            static fn(int|string $key, int $clientId): ?array => Invoice::forClientKey($key, $clientId),
            $uid,
            'invoice'
        );
    }

    /**
     * What The Client Holds In Credit
     * @return string Decimal string
     */
    private function creditAvailable(): string
    {
        $client = $this->client();

        return (string) ($client['credit_balance'] ?? '0');
    }
}
