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

use RuntimeException;
use Laika\Service\Request;
use LBM\Service\Client;
use LBM\Service\Currency;
use LBM\Service\Invoice;
use LBM\Service\Mail;
use LBM\Service\Money;
use LBM\Service\Transaction;

/**
 * Invoices.
 *
 * The one screen in the application where the arithmetic has to be exactly
 * right, so none of it happens here: every total comes from LBM\Action\Invoice,
 * which recalculates from the line items after any change. A controller that
 * did its own sums would be a second implementation to keep in step.
 *
 * Recording a payment goes through LBM\Action\Transaction rather than straight
 * onto the invoice, because a payment is a ledger entry first - an invoice
 * marked paid with nothing in the ledger to show for it is how books stop
 * balancing.
 */
class InvoiceController extends AdminController
{
    protected function nav(): string
    {
        return 'invoices';
    }

    /**
     * The Invoice List
     * @return string
     */
    public function index(): string
    {
        $page = Invoice::browseWithClients(
            $this->conditions(['status' => 'status_relid']),
            $this->search()
        );

        // The balance is total less payments and credit - not a column - so it
        // is worked out once here rather than in the template.
        foreach ($page['rows'] as $i => $row) {
            $page['rows'][$i]['balance'] = Invoice::balance($row);
        }

        return $this->screen('invoices', 'Invoices', [
            'pager'    =>  $page,
            'statuses' =>  Invoice::statuses(),
        ]);
    }

    /**
     * Raise an Invoice
     * @return ?string
     */
    public function create(): ?string
    {
        if (Request::isPost()) {
            $input = Request::inputs();
            $items = $this->items($input);

            $this->require(['client_relid' => local('choose_a_client_msg')], $input);

            if ($items === []) {
                Request::addError('items', local('invoice_needs_line'));
            }

            if (Request::errors() === []) {
                $id = Invoice::store($input, $items);
                $invoice = Invoice::find($id);

                $this->log('invoice.created', 'Raised invoice ' . $invoice['invoice_number']);

                return $this->done('staff.invoice', local('invoice_raised_msg'), true, ['invoice' => $invoice['uid']]);
            }
        }

        return $this->form(null, local('new_invoice'));
    }

    /**
     * One Invoice
     * @param string $invoice Invoice Uid
     * @return string
     */
    public function show(string $invoice): string
    {
        $row = $this->record(Invoice::find($invoice), 'invoice');
        $id = (int) $row['invoice_id'];

        return $this->screen('invoice', local('invoice_titled', $row['invoice_number']), [
            'invoice'      =>  $row,
            'client'       =>  Client::find((int) $row['client_relid']),
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
        ]);
    }

    /**
     * Edit an Invoice
     * @param string $invoice Invoice Uid
     * @return ?string
     */
    public function edit(string $invoice): ?string
    {
        $row = $this->record(Invoice::find($invoice), 'invoice');
        $id = (int) $row['invoice_id'];

        if (Request::isPost()) {
            $input = Request::inputs();
            $items = $this->items($input);

            Invoice::modify($id, $input);

            if ($items !== []) {
                Invoice::replaceItems($id, $items);
            }

            $this->log('invoice.updated', 'Updated invoice ' . $row['invoice_number']);

            return $this->done('staff.invoice', local('invoice_updated'), true, ['invoice' => $row['uid']]);
        }

        return $this->form($row, local('edit_invoice_titled', $row['invoice_number']));
    }

    /**
     * A Printable Invoice
     *
     * Its own layout with no navigation: this is what gets sent to a customer
     * or saved as a PDF from the browser, and a sidebar has no business on it.
     * @param string $invoice Invoice Uid
     * @return string
     */
    public function print(string $invoice): string
    {
        $row = $this->record(Invoice::find($invoice), 'invoice');

        return $this->render('invoice-print', [
            'page_title' =>  local('invoice_titled', $row['invoice_number']),
            'invoice'    =>  $row,
            'client'     =>  Client::find((int) $row['client_relid']),
            'items'      =>  Invoice::items((int) $row['invoice_id']),
            'balance'    =>  Invoice::balance($row),
            'tax_amount' =>  Invoice::taxAmount($row),
            'tax_bands'  =>  Invoice::taxBreakdown((int) $row['invoice_id']),
            'settled'    =>  Invoice::isSettled($row),
        ]);
    }

    /**
     * Email an Invoice To Its Client
     * @param string $invoice Invoice Uid
     * @return ?string
     */
    public function send(string $invoice): ?string
    {
        $row = $this->record(Invoice::find($invoice), 'invoice');
        $client = Client::find((int) $row['client_relid']);

        if ($client === null || empty($client['email'])) {
            return $this->done(
                'staff.invoice',
                local('no_client_email'),
                false,
                ['invoice' => $row['uid']]
            );
        }

        return $this->attempt(
            function () use ($row, $client): void {
                Mail::queueTemplate('invoice-created', (string) $client['email'], [
                    'first_name'     =>  $client['first_name'] ?? '',
                    'invoice_number' =>  $row['invoice_number'],
                    'total'          =>  money($row['total'], $row['currency_relid']),
                    'due_date'       =>  format_day($row['invoice_due_date'] ?? null),
                ], (int) $client['cid']);

                $this->log('invoice.sent', 'Queued invoice ' . $row['invoice_number'] . ' to ' . $client['email']);
            },
            'staff.invoice',
            local('invoice_queued'),
            ['invoice' => $row['uid']]
        );
    }

    /**
     * Record a Payment Against an Invoice
     * @param string $invoice Invoice Uid
     * @return ?string
     */
    public function pay(string $invoice): ?string
    {
        $row = $this->record(Invoice::find($invoice), 'invoice');
        $input = Request::inputs();

        // Defaults to what is still owed, which is what the operator means
        // nine times in ten when they click "record payment".
        $amount = trim((string) ($input['amount'] ?? ''));
        $amount = $amount !== '' ? $amount : Invoice::balance($row);

        return $this->attempt(
            function () use ($row, $input, $amount): void {
                if (!Money::isGreater($amount, '0')) {
                    throw new RuntimeException(local('payment_greater_than_zero'));
                }

                Transaction::pay([
                    'client_relid'    =>  $row['client_relid'],
                    'invoice_relid'   =>  $row['invoice_id'],
                    'currency_relid'  =>  $row['currency_relid'],
                    'amount'          =>  $amount,
                    'transaction_ref' =>  $input['transaction_ref'] ?? null,
                    'description'     =>  $input['description'] ?? null,
                ]);

                $this->log(
                    'invoice.paid',
                    'Recorded ' . money($amount, $row['currency_relid'])
                        . ' against invoice ' . $row['invoice_number']
                );
            },
            'staff.invoice',
            local('payment_recorded'),
            ['invoice' => $row['uid']]
        );
    }

    /**
     * Cancel an Invoice
     * @param string $invoice Invoice Uid
     * @return ?string
     */
    public function cancel(string $invoice): ?string
    {
        $row = $this->record(Invoice::find($invoice), 'invoice');

        Invoice::cancel((int) $row['invoice_id']);

        $this->log('invoice.cancelled', 'Cancelled invoice ' . $row['invoice_number']);

        return $this->done('staff.invoice', local('invoice_cancelled'), true, ['invoice' => $row['uid']]);
    }

    /**
     * Delete an Invoice
     * @param string $invoice Invoice Uid
     * @return ?string
     */
    public function delete(string $invoice): ?string
    {
        $row = $this->record(Invoice::find($invoice), 'invoice');
        $number = (string) $row['invoice_number'];

        Invoice::remove((int) $row['invoice_id']);

        $this->log('invoice.deleted', "Deleted invoice {$number} and its line items.");

        return $this->done('staff.invoices', local('deleted_invoice', $number));
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Render The Invoice Form
     * @param ?array $invoice Invoice, Or Null When Raising
     * @param string $title Page Title
     * @return string
     */
    private function form(?array $invoice, string $title): string
    {
        return $this->screen('invoice-form', $title, [
            'invoice'    =>  $invoice,
            'items'      =>  $invoice === null ? [] : Invoice::items((int) $invoice['invoice_id']),
            'clients'    =>  $this->clientChoices(),
            'currencies' =>  $this->currencyChoices(),
            'statuses'   =>  $this->statusChoices(Invoice::statuses()),
        ]);
    }

    /**
     * Pull The Line Items Out Of a Submission
     * @param array $input Submitted Data
     * @return array
     */
    private function items(array $input): array
    {
        $rows = $input['items'] ?? [];

        if (!is_array($rows)) {
            return [];
        }

        $items = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $description = trim((string) ($row['description'] ?? ''));

            // The blank row at the bottom of the table. A line with no
            // description is not a line.
            if ($description === '') {
                continue;
            }

            $items[] = [
                'description' =>  $description,
                'quantity'    =>  (string) ($row['quantity'] ?? '1'),
                'unit_price'  =>  (string) ($row['unit_price'] ?? '0'),
                'discount'    =>  (string) ($row['discount'] ?? '0'),
            ];
        }

        return $items;
    }

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
}
