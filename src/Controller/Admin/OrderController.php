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
use LBM\Service\Order;
use LBM\Service\Product;

/**
 * Orders - what a client asked for, before it becomes money owed.
 *
 * Accepting an order is the only thing here that touches the ledger: it raises
 * the invoice and links the two. Everything else edits a request that has not
 * been charged for yet, which is why an order can be changed freely and an
 * invoice cannot.
 */
class OrderController extends AdminController
{
    protected function nav(): string
    {
        return 'orders';
    }

    /**
     * The Order List
     * @return string
     */
    public function index(): string
    {
        $page = Order::browseWithClients(
            $this->conditions(['status' => 'status_relid']),
            $this->search()
        );

        return $this->screen('orders', 'Orders', [
            'pager'    =>  $page,
            'statuses' =>  Order::statuses(),
        ]);
    }

    /**
     * Place an Order
     * @return ?string
     */
    public function create(): ?string
    {
        if (Request::isPost()) {
            $input = Request::inputs();
            $items = $this->items($input);

            $this->require(['client_relid' => local('choose_a_client_msg')], $input);

            if ($items === []) {
                Request::addError('items', local('order_needs_line'));
            }

            if (Request::errors() === []) {
                $id = Order::store($input, $items);
                $order = Order::find($id);

                $this->log('order.created', 'Placed order ' . $order['order_number']);

                return $this->done('staff.order', local('order_placed'), true, ['order' => $order['uid']]);
            }
        }

        return $this->form(null, local('new_order'));
    }

    /**
     * One Order
     * @param string $order Order Uid
     * @return string
     */
    public function show(string $order): string
    {
        $row = $this->record(Order::find($order), 'order');

        return $this->screen('order', local('order_titled', $row['order_number']), [
            'order'   =>  $row,
            'client'  =>  Client::find((int) $row['client_relid']),
            'items'   =>  Order::items((int) $row['oid']),
            'invoice' =>  $row['invoice_relid']
                ? Invoice::find((int) $row['invoice_relid'])
                : null,
        ]);
    }

    /**
     * Edit an Order
     * @param string $order Order Uid
     * @return ?string
     */
    public function edit(string $order): ?string
    {
        $row = $this->record(Order::find($order), 'order');

        if (Request::isPost()) {
            $input = Request::inputs();
            $items = $this->items($input);

            Order::modify((int) $row['oid'], $input);

            if ($items !== []) {
                Order::replaceItems((int) $row['oid'], $items);
            }

            $this->log('order.updated', 'Updated order ' . $row['order_number']);

            return $this->done('staff.order', local('order_updated'), true, ['order' => $row['uid']]);
        }

        return $this->form($row, local('edit_order_titled', $row['order_number']));
    }

    /**
     * Accept an Order And Raise Its Invoice
     * @param string $order Order Uid
     * @return ?string
     */
    public function accept(string $order): ?string
    {
        $row = $this->record(Order::find($order), 'order');

        return $this->attempt(
            function () use ($row): void {
                $invoiceId = Order::accept((int) $row['oid']);

                $this->log(
                    'order.accepted',
                    'Accepted order ' . $row['order_number'] . ' and raised invoice #' . $invoiceId
                );
            },
            'staff.order',
            local('order_accepted'),
            ['order' => $row['uid']]
        );
    }

    /**
     * Cancel an Order
     * @param string $order Order Uid
     * @return ?string
     */
    public function cancel(string $order): ?string
    {
        $row = $this->record(Order::find($order), 'order');

        Order::cancel((int) $row['oid']);

        $this->log('order.cancelled', 'Cancelled order ' . $row['order_number']);

        return $this->done('staff.order', local('order_cancelled'), true, ['order' => $row['uid']]);
    }

    /**
     * Delete an Order
     * @param string $order Order Uid
     * @return ?string
     */
    public function delete(string $order): ?string
    {
        $row = $this->record(Order::find($order), 'order');
        $number = (string) $row['order_number'];

        return $this->attempt(
            function () use ($row, $number): void {
                Order::remove((int) $row['oid']);

                $this->log('order.deleted', "Deleted order {$number}.");
            },
            'staff.orders',
            local('deleted_order', $number)
        );
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Render The Order Form
     * @param ?array $order Order, Or Null When Adding
     * @param string $title Page Title
     * @return string
     */
    private function form(?array $order, string $title): string
    {
        return $this->screen('order-form', $title, [
            'order'      =>  $order,
            'items'      =>  $order === null ? [] : Order::items((int) $order['oid']),
            'clients'    =>  $this->clientChoices(),
            'products'   =>  $this->productChoices(),
            'currencies' =>  $this->currencyChoices(),
            'statuses'   =>  $this->statusChoices(Order::statuses()),
        ]);
    }

    /**
     * Pull The Line Items Out Of a Submission
     *
     * The form posts parallel arrays - one per column - because that is what a
     * table of rows with an "add another" button produces. A row with no
     * product and no amount is the blank one at the bottom nobody filled in, and
     * is dropped rather than stored as an empty line.
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

            $product = trim((string) ($row['product_relid'] ?? ''));
            $amount = trim((string) ($row['amount'] ?? ''));

            if ($product === '' && $amount === '') {
                continue;
            }

            $items[] = [
                'type'          =>  (string) ($row['type'] ?? 'product'),
                'product_relid' =>  $product !== '' ? (int) $product : null,
                'billing_cycle' =>  (string) ($row['billing_cycle'] ?? ''),
                'domain'        =>  (string) ($row['domain'] ?? ''),
                'quantity'      =>  (int) ($row['quantity'] ?? 1) ?: 1,
                'amount'        =>  $amount !== '' ? $amount : '0',
            ];
        }

        return $items;
    }

    /**
     * Client Choices
     *
     * Every client, because an order is placed for one of them and a paged
     * dropdown is not a thing. On an install with thousands this becomes a
     * search field; it is a select while the list is small enough to scroll.
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
     * Product Choices
     * @return array<int,string>
     */
    private function productChoices(): array
    {
        $choices = [];

        foreach (Product::all([], 'ASC', 'product_name') as $row) {
            $choices[(int) $row['pid']] = (string) $row['product_name'];
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
