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

namespace LBM\Controller\Client;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use RuntimeException;
use Laika\Service\Redirect;
use Laika\Service\Request;
use LBM\Service\Gateway;
use LBM\Service\Invoice;
use LBM\Service\Money;
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
            'settled'      =>  Invoice::isSettled($row),
            'overdue'      =>  Invoice::isOverdue($row),
            'credit'       =>  $this->creditAvailable(),

            // Only gateways that are active AND whose driver actually builds.
            // An empty list is an ordinary state - a fresh installation has none
            // configured - and the view says so rather than offering a button
            // that cannot take money.
            'gateways'     =>  Gateway::payable(),
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
            $gateway = Gateway::payableBySlug((string) Request::input('gateway', ''));

            if ($gateway === null) {
                throw new RuntimeException(local('choose_a_payment_method'));
            }

            $driver = Gateway::driverFor($gateway);

            if ($driver === null) {
                throw new RuntimeException(local('payment_method_unavailable'));
            }

            // The amount comes from the invoice, never from the request.
            $result = $driver->charge([
                'amount'      =>  Invoice::balance($row),
                'currency'    =>  (string) ($row['currency_code'] ?? ''),
                'invoice_id'  =>  (int) $row['invoice_id'],
                'client_id'   =>  (int) $row['client_relid'],
                'description' =>  (string) $row['invoice_number'],
                'client'      =>  $this->client(),
                'return_url'  =>  named('client.invoice', ['invoice' => $row['uid']]),
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
        // Nothing in 22.1 returns a redirect, but the contract allows one, and a
        // silently ignored redirect would strand the first driver that used it
        // on a screen saying the payment had started when it had not.
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
     *              arrives.
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

        if (($result['success'] ?? false) === true) {
            // The amount recorded is the driver's, not the request's, and not
            // the invoice's: a gateway may have taken a different sum, and the
            // ledger has to say what actually happened.
            $amount = (string) ($result['amount'] ?? Invoice::balance($invoice));

            $transaction = Transaction::pay([
                'client_relid'   =>  (int) $invoice['client_relid'],
                'invoice_relid'  =>  (int) $invoice['invoice_id'],
                'currency_relid' =>  (int) $invoice['currency_relid'],
                'gateway_relid'  =>  (int) $gateway['gateway_id'],
                'transaction_ref' =>  (string) ($result['reference'] ?? ''),
                'amount'         =>  $amount,
                'fee'            =>  (string) ($result['fee'] ?? '0'),
                'description'    =>  $gateway['display_name'] . ' payment',
            ]);

            if (is_array($result['raw'] ?? null) && $result['raw'] !== []) {
                Transaction::recordGatewayData($transaction, $result['raw']);
            }

            $this->log(
                'invoice.paid',
                'Paid invoice ' . $invoice['invoice_number'] . ' through ' . $gateway['display_name'] . '.'
            );

            return local('invoice_paid_through', $gateway['display_name']);
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
            'settled'      =>  Invoice::isSettled($row),
        ]);
    }

    /**
     * Settle An Invoice From Account Credit
     *
     * This is the only kind of payment a client can make themselves, and that
     * is deliberate. Credit is money the operator has already received and
     * recorded; spending it against an invoice moves a number that is provably
     * theirs. Anything else - a card, a bank transfer - is a gateway's job, and
     * the gateway runtime is a later phase.
     *
     * What this route must never become is "the client says they paid": a form
     * that let somebody record their own payment would let anyone mark their
     * own invoice settled. When gateways arrive, the payment will be recorded
     * from the gateway's callback, not from a form the payer controls.
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

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

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
