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

namespace LBM\Controller\Admin;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Laika\Service\Request;
use LBM\Service\Client;
use LBM\Service\Currency;
use LBM\Service\Invoice;
use LBM\Service\Transaction;

/**
 * The money ledger.
 *
 * Append-only in spirit: a payment that turns out to be wrong is corrected by
 * recording a refund against it, never by editing the original. There is a
 * delete, for the operator who has just typed the same payment twice, and the
 * action refuses it once the payment has moved an invoice.
 */
class TransactionController extends AdminController
{
    protected function nav(): string
    {
        return 'transactions';
    }

    /**
     * The Ledger
     * @return string
     */
    public function index(): string
    {
        $where = $this->conditions(['status' => 'status_relid']);

        // Type is a string column, so it is not one of the numeric filters
        // conditions() handles.
        $type = trim((string) Request::input('type', ''));

        if ($type !== '' && in_array($type, Transaction::types(), true)) {
            $where['type'] = $type;
        }

        return $this->screen('transactions', 'Transactions', [
            'pager'    =>  Transaction::browseWithClients($where, $this->search()),
            'statuses' =>  Transaction::statuses(),
            'types'    =>  Transaction::types(),
            'income'   =>  Transaction::income(date('Y-m-01 00:00:00'), date('Y-m-t 23:59:59')),
        ]);
    }

    /**
     * Record a Payment By Hand
     *
     * For money that arrived outside a gateway - a bank transfer, a cheque,
     * cash at a counter. Everything else records itself.
     * @return ?string
     */
    public function create(): ?string
    {
        if (Request::isPost()) {
            $input = Request::inputs();

            $this->require([
                'client_relid' =>  local('choose_a_client_msg'),
                'amount'       =>  local('how_much_was_paid'),
            ], $input);

            if (Request::errors() === []) {
                return $this->attempt(
                    function () use ($input): void {
                        $id = Transaction::pay($input);
                        $row = Transaction::find($id);

                        $this->log(
                            'transaction.created',
                            'Recorded a payment of ' . money($row['amount'], $row['currency_relid'])
                        );
                    },
                    'staff.transactions',
                    local('payment_recorded')
                );
            }
        }

        return $this->screen('transaction-form', local('record_a_payment'), [
            'clients'    =>  $this->clientChoices(),
            'currencies' =>  $this->currencyChoices(),
            'invoices'   =>  $this->invoiceChoices(),
        ]);
    }

    /**
     * One Transaction
     * @param string $transaction Transaction Uid
     * @return string
     */
    public function show(string $transaction): string
    {
        $row = $this->record(Transaction::find($transaction), 'transaction');

        return $this->screen('transaction', local('transaction_numbered', $row['tx_id']), [
            'transaction' =>  $row,
            'client'      =>  Client::find((int) $row['client_relid']),
            'invoice'     =>  $row['invoice_relid'] ? Invoice::find((int) $row['invoice_relid']) : null,
            'refunded'    =>  Transaction::refundedAgainst((int) $row['tx_id']),
        ]);
    }

    /**
     * Refund a Payment
     * @param string $transaction Transaction Uid
     * @return ?string
     */
    public function refund(string $transaction): ?string
    {
        $row = $this->record(Transaction::find($transaction), 'transaction');
        $input = Request::inputs();

        $amount = trim((string) ($input['amount'] ?? ''));

        return $this->attempt(
            function () use ($row, $input, $amount): void {
                Transaction::refund(
                    (int) $row['tx_id'],
                    $amount !== '' ? $amount : null,
                    (string) ($input['reason'] ?? '')
                );

                $this->log('transaction.refunded', 'Refunded transaction #' . $row['tx_id']);
            },
            'staff.transaction',
            local('refund_recorded'),
            ['transaction' => $row['uid']]
        );
    }

    /**
     * Delete a Transaction
     * @param string $transaction Transaction Uid
     * @return ?string
     */
    public function delete(string $transaction): ?string
    {
        $row = $this->record(Transaction::find($transaction), 'transaction');

        return $this->attempt(
            function () use ($row): void {
                Transaction::remove((int) $row['tx_id']);

                $this->log('transaction.deleted', 'Deleted transaction #' . $row['tx_id']);
            },
            'staff.transactions',
            local('transaction_deleted')
        );
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Client Choices
     * @return array<int,string>
     */
    private function clientChoices(): array
    {
        $choices = [];

        foreach (Client::all([], 'ASC', 'first_name') as $row) {
            $label = trim((string) ($row['company_name'] ?? '')) !== ''
                ? $row['company_name'] . ' (' . $row['first_name'] . ' ' . $row['last_name'] . ')'
                : $row['first_name'] . ' ' . $row['last_name'];

            $choices[(int) $row['cid']] = $label;
        }

        return $choices;
    }

    /**
     * Currency Choices
     * @return array<int,string>
     */
    private function currencyChoices(): array
    {
        $choices = [];

        foreach (Currency::listing(true) as $row) {
            $choices[(int) $row['currency_id']] = (string) $row['currency_code'];
        }

        return $choices;
    }

    /**
     * Unpaid Invoice Choices
     *
     * Only what is still owed something: attaching a payment to an invoice that
     * is already settled is almost always a mistake, and offering it invites one.
     * @return array<int,string>
     */
    private function invoiceChoices(): array
    {
        $choices = [];

        foreach (Invoice::browseUnpaid(100)['rows'] as $row) {
            $choices[(int) $row['invoice_id']] = $row['invoice_number']
                . ' — ' . money($row['balance'], $row['currency_relid']);
        }

        return $choices;
    }
}
