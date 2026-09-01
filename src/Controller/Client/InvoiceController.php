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
        ]);
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
