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
use LBM\Model\ClientModel;
use LBM\Model\InvoiceModel;
use LBM\Model\InvoiceItemModel;
use LBM\Service\Money;
use LBM\Service\Status;
use Laika\Service\Uid;

/**
 * Invoices and their line items.
 *
 * How an invoice adds up, in one place so nothing else has to guess:
 *
 *   item.total     = quantity * unit_price - discount
 *   subtotal       = sum of every item total
 *   taxable        = subtotal - invoice discount
 *   total          = taxable + tax% of taxable
 *   balance due    = total - amount_paid - credit_applied
 *
 * `tax` is a percentage on both the invoice and its items - the column is
 * decimal(7,4), which tops out at 999.9999 and so was never a money column.
 * `discount` is an amount.
 *
 * Every step runs through Money, which is bcmath over decimal strings. Nothing
 * here touches a float: the whole point of a billing system is that the numbers
 * are exactly what they say they are.
 */
class Invoice extends Action
{
    /** @var string Status Lookup Table */
    public const STATUSES = 'invoice_statuses';

    /** @var string[] Columns a Form May Write */
    public const FIELDS = [
        'client_relid', 'currency_relid', 'status_relid', 'discount', 'tax',
        'invoice_due_date', 'payment_gateway', 'terms',
    ];

    /** @var string[] Columns An Item Form May Write */
    public const ITEM_FIELDS = [
        'item_type_relid', 'description', 'quantity', 'unit_price', 'tax',
        'discount', 'service_relid', 'domain_relid', 'period_start', 'period_end',
    ];

    public function model(): Model
    {
        return new InvoiceModel();
    }

    protected function searchable(): array
    {
        return ['invoice_number'];
    }

    protected function createdColumn(): ?string
    {
        return 'invoice_created_at';
    }

    protected function updatedColumn(): ?string
    {
        return 'invoice_updated_at';
    }

    ####################################################################################
    /*=================================== READING ====================================*/
    ####################################################################################

    /**
     * One Page Of Invoices, With The Client They Belong To
     * @param array $where Conditions
     * @param ?string $search Search Term. Matches the invoice number or the client
     * @param ?int $limit Rows Per Page
     * @return array
     */
    public function browseWithClients(array $where = [], ?string $search = null, ?int $limit = null): array
    {
        $invoices = new InvoiceModel();
        $clients  = new ClientModel();

        $i = $invoices->table;
        $c = $clients->table;

        $where = $this->qualify($where, $i);
        $search = $search === null ? '' : trim($search);

        $counted = new InvoiceModel();
        $this->conditions($counted, $where);

        $listed = new InvoiceModel();
        $listed->select([
            "{$i}.*",
            "{$c}.first_name AS client_first_name",
            "{$c}.last_name AS client_last_name",
            "{$c}.company_name AS client_company_name",
        ])->join($c, "{$c}.{$clients->id}", '=', "{$i}.client_relid");

        $this->conditions($listed, $where);

        // Searching an invoice by the customer's name is what a back office
        // actually does, so the term spans both tables. The count needs the same
        // join to agree with the page - many-to-one, so it cannot inflate.
        if ($search !== '') {
            $term = '%' . $search . '%';
            $columns = [
                "{$i}.invoice_number"  =>  $term,
                "{$c}.first_name"      =>  $term,
                "{$c}.last_name"       =>  $term,
                "{$c}.company_name"    =>  $term,
            ];

            $counted->join($c, "{$c}.{$clients->id}", '=', "{$i}.client_relid")
                ->whereGroup(static function (Model $group) use ($columns): void {
                    $group->where($columns, 'LIKE', 'OR');
                });

            $listed->whereGroup(static function (Model $group) use ($columns): void {
                $group->where($columns, 'LIKE', 'OR');
            });
        }

        return $this->paginate($listed, $counted, $limit, self::DESC);
    }

    /**
     * Every Line Item On An Invoice
     * @param int $invoiceId Invoice ID
     * @return array
     */
    public function items(int $invoiceId): array
    {
        $model = new InvoiceItemModel();

        return $model->where(['invoice_relid' => $invoiceId])
            ->order($model->id, self::ASC)
            ->get();
    }

    /**
     * What Is Still Owed On An Invoice
     * @param array $invoice Invoice Row
     * @return string Decimal string. Never negative
     */
    public function balance(array $invoice): string
    {
        $due = Money::sub(
            (string) ($invoice['total'] ?? '0'),
            Money::add(
                (string) ($invoice['amount_paid'] ?? '0'),
                (string) ($invoice['credit_applied'] ?? '0')
            )
        );

        // An overpayment is a credit on the client account, not a negative
        // balance on the invoice.
        return Money::isGreater($due, '0') ? $due : '0';
    }

    /**
     * How Much Tax Is On An Invoice, In Money
     *
     * `tax` is a percentage, and a percentage on its own is not something a
     * person can check against their own books - or, in a good many places,
     * something an invoice is allowed to state without the amount beside it.
     *
     * Derived rather than stored, from the same contract the totals are built
     * on: taxable is the subtotal less the invoice discount, and the tax is
     * whatever the total is above it. Recomputing the percentage here instead
     * would give a second answer to a question the stored total has already
     * settled, and the two would disagree on any invoice whose total was ever
     * adjusted by hand.
     * @param array $invoice Invoice Row
     * @return string Decimal string. Never negative
     */
    public function taxAmount(array $invoice): string
    {
        $taxable = Money::sub(
            (string) ($invoice['subtotal'] ?? '0'),
            (string) ($invoice['discount'] ?? '0')
        );

        $tax = Money::sub((string) ($invoice['total'] ?? '0'), $taxable);

        return Money::isGreater($tax, '0') ? Money::round($tax) : '0';
    }

    /**
     * Whether An Invoice Is Fully Settled
     * @param array $invoice Invoice Row
     * @return bool
     */
    public function isSettled(array $invoice): bool
    {
        return Money::isZero($this->balance($invoice));
    }

    /**
     * Whether An Invoice Is Past Its Due Date And Still Owing
     * @param array $invoice Invoice Row
     * @return bool
     */
    public function isOverdue(array $invoice): bool
    {
        $due = $invoice['invoice_due_date'] ?? null;

        if ($due === null || $due === '' || $this->isSettled($invoice)) {
            return false;
        }

        return strtotime((string) $due) < strtotime($this->now());
    }

    /**
     * Invoices Owned By One Client
     * @param int $clientId Client ID
     * @param array $where Extra Conditions
     * @param ?int $limit Rows Per Page
     * @return array
     */
    public function browseForClient(int $clientId, array $where = [], ?int $limit = null): array
    {
        return $this->browse($where + ['client_relid' => $clientId], null, $limit, self::DESC);
    }

    /**
     * Find An Invoice, Scoped To Its Client
     *
     * The ownership check is part of the lookup, so somebody else's invoice uid
     * is not found rather than found and refused.
     * @param int|string $key Invoice ID Or Uid
     * @param int $clientId Client ID
     * @return ?array
     */
    public function forClientKey(int|string $key, int $clientId): ?array
    {
        $model = $this->model();

        $row = $this->key($model, $key)->where(['client_relid' => $clientId])->first();

        return is_array($row) ? $row : null;
    }

    /**
     * The Status Ids That Mean "Nothing Left To Chase"
     *
     * Paid and cancelled. Kept in one place because three screens and two jobs
     * all need the same exclusion, and a list that drifted between them would
     * show different totals on the dashboard and the invoice list.
     * @return int[]
     */
    public function settledStatusIds(): array
    {
        return array_values(array_filter([
            Status::idOf(self::STATUSES, 'paid'),
            Status::idOf(self::STATUSES, 'cancelled'),
        ]));
    }

    /**
     * One Page Of Invoices That Are Still Owed Something
     *
     * The exclusion is a NOT IN, which the where-shorthand cannot express, so
     * this builds the pair of models itself rather than going through browse().
     * @param ?int $limit Rows Per Page
     * @return array
     */
    public function browseUnpaid(?int $limit = null): array
    {
        $invoices = new InvoiceModel();
        $clients  = new ClientModel();

        $i = $invoices->table;
        $c = $clients->table;

        $excluded = $this->settledStatusIds();

        $counted = new InvoiceModel();
        $listed  = new InvoiceModel();

        $listed->select([
            "{$i}.*",
            "{$c}.first_name AS client_first_name",
            "{$c}.last_name AS client_last_name",
            "{$c}.company_name AS client_company_name",
        ])->join($c, "{$c}.{$clients->id}", '=', "{$i}.client_relid");

        if ($excluded !== []) {
            $counted->whereNotIn("{$i}.status_relid", $excluded);
            $listed->whereNotIn("{$i}.status_relid", $excluded);
        }

        $page = $this->paginate($listed, $counted, $limit, self::DESC);

        // The balance is not a column - it is total less what has been paid and
        // credited - so it is worked out here rather than in the view, where
        // three screens would each have to get the same arithmetic right.
        foreach ($page['rows'] as $index => $row) {
            $page['rows'][$index]['balance'] = $this->balance($row);
        }

        return $page;
    }

    /**
     * The Total Of Every Unpaid Invoice For a Client
     * @param int $clientId Client ID
     * @return string Decimal string
     */
    public function outstandingFor(int $clientId): string
    {
        $model = $this->model();
        $model->where(['client_relid' => $clientId]);

        $excluded = $this->settledStatusIds();

        if ($excluded !== []) {
            $model->whereNotIn('status_relid', $excluded);
        }

        $rows = $model->get();
        $total = '0';

        foreach ($rows as $row) {
            $total = Money::add($total, $this->balance($row));
        }

        return $total;
    }

    ####################################################################################
    /*=================================== WRITING ====================================*/
    ####################################################################################

    /**
     * Create An Invoice, With Its Line Items
     *
     * The invoice number is assigned from the primary key after the insert
     * rather than derived from a count beforehand. Counting rows to pick the
     * next number is a race: two invoices raised in the same second would both
     * read the same count and collide on the unique index. The key is already
     * unique, so numbering from it cannot.
     * @param array $input Submitted Data
     * @param array $items Line Items
     * @return int New Invoice ID
     */
    public function store(array $input, array $items = []): int
    {
        $data = $this->fields($input);

        $data['status_relid'] = (int) ($data['status_relid'] ?? 0)
            ?: (Status::idOf(self::STATUSES, 'unpaid') ?? 1);

        if (empty($data['invoice_due_date'])) {
            $data['invoice_due_date'] = $this->defaultDueDate();
        }

        $uid = Uid::make();
        $data['uid'] = $uid;

        // A placeholder that is already unique, replaced with the real number
        // inside the same transaction. invoice_number is NOT NULL UNIQUE, so it
        // cannot simply be left empty until the id is known.
        $data['invoice_number'] = $uid;

        $id = 0;

        $this->model()->transaction(function (InvoiceModel $m) use ($data, $items, &$id): void {
            $id = (int) $m->insert($this->stamp($data, true));

            foreach ($items as $item) {
                $this->insertItem($id, $item);
            }

            $m->where([$m->id => $id])->update(['invoice_number' => $this->number($id)]);
        });

        $this->recalculate($id);

        return $id;
    }

    /**
     * Update An Invoice's Own Fields
     * @param int|string $key Invoice ID Or Uid
     * @param array $input Submitted Data
     * @return int Affected rows
     */
    public function modify(int|string $key, array $input): int
    {
        $invoice = $this->find($key);

        if ($invoice === null) {
            return 0;
        }

        $affected = $this->update((int) $invoice['invoice_id'], $this->fields($input));

        // Discount and tax live on the invoice, so a change to either moves the
        // total even though no item was touched.
        $this->recalculate((int) $invoice['invoice_id']);

        return $affected;
    }

    /**
     * Replace An Invoice's Line Items
     *
     * Replace rather than reconcile: an item form posts the whole set, and
     * matching submitted rows against stored ones to work out which were edited
     * is a great deal of machinery for no visible difference.
     * @param int $invoiceId Invoice ID
     * @param array $items Line Items
     * @return void
     */
    public function replaceItems(int $invoiceId, array $items): void
    {
        (new InvoiceItemModel())->transaction(
            function (InvoiceItemModel $m) use ($invoiceId, $items): void {
                $m->where(['invoice_relid' => $invoiceId])->delete();

                foreach ($items as $item) {
                    $this->insertItem($invoiceId, $item);
                }
            }
        );

        $this->recalculate($invoiceId);
    }

    /**
     * Add One Line Item
     * @param int $invoiceId Invoice ID
     * @param array $item Line Item
     * @return int New Item ID
     */
    public function addItem(int $invoiceId, array $item): int
    {
        $id = $this->insertItem($invoiceId, $item);

        $this->recalculate($invoiceId);

        return $id;
    }

    /**
     * Remove One Line Item
     * @param int $invoiceId Invoice ID
     * @param int|string $key Item ID Or Uid
     * @return int Affected rows
     */
    public function removeItem(int $invoiceId, int|string $key): int
    {
        $model = new InvoiceItemModel();

        $affected = $this->key($model, $key)->where(['invoice_relid' => $invoiceId])->delete();

        $this->recalculate($invoiceId);

        return $affected;
    }

    /**
     * Recalculate An Invoice's Totals From Its Items
     *
     * Called after any change to the invoice or its items, so the stored total
     * is always what the lines actually add up to and no screen has to sum them
     * again to be sure.
     * @param int $invoiceId Invoice ID
     * @return array{subtotal:string,total:string}
     */
    public function recalculate(int $invoiceId): array
    {
        $invoice = $this->find($invoiceId);

        if ($invoice === null) {
            return ['subtotal' => '0', 'total' => '0'];
        }

        $subtotal = '0';

        foreach ($this->items($invoiceId) as $item) {
            $subtotal = Money::add($subtotal, $this->itemTotal($item));
        }

        $discount = (string) ($invoice['discount'] ?? '0');
        $taxRate  = (string) ($invoice['tax'] ?? '0');

        $taxable = Money::sub($subtotal, $discount);
        $total   = Money::add($taxable, Money::percent($taxable, $taxRate));

        $subtotal = Money::round($subtotal);
        $total    = Money::round($total);

        $model = $this->model();
        $model->where([$model->id => $invoiceId])->update([
            'subtotal'           =>  $subtotal,
            'total'              =>  $total,
            'invoice_updated_at' =>  $this->now(),
        ]);

        return ['subtotal' => $subtotal, 'total' => $total];
    }

    /**
     * Record a Payment Against An Invoice
     *
     * Only the invoice side: the transactions row is written by
     * LBM\Action\Transaction, which calls this once the payment itself is
     * recorded. Splitting them keeps a payment from existing without a ledger
     * entry, or the other way round.
     * @param int $invoiceId Invoice ID
     * @param int|float|string $amount Amount Paid
     * @return bool Whether the invoice is now settled
     */
    public function applyPayment(int $invoiceId, int|float|string $amount): bool
    {
        $settled = false;

        $this->model()->transaction(function (InvoiceModel $m) use ($invoiceId, $amount, &$settled): void {
            $invoice = $m->where([$m->id => $invoiceId])->first();

            if (!is_array($invoice)) {
                return;
            }

            $paid = Money::round(Money::add((string) ($invoice['amount_paid'] ?? '0'), (string) $amount));
            $invoice['amount_paid'] = $paid;

            $settled = $this->isSettled($invoice);

            $data = [
                'amount_paid'        =>  $paid,
                'invoice_updated_at' =>  $this->now(),
            ];

            if ($settled) {
                $data['invoice_paid_date'] = $this->now();

                $paidStatus = Status::idOf(self::STATUSES, 'paid');

                if ($paidStatus !== null) {
                    $data['status_relid'] = $paidStatus;
                }
            }

            $m->where([$m->id => $invoiceId])->update($data);
        });

        // Outside the transaction, and only once it committed. Provisioning
        // writes its own rows; doing that inside somebody else's transaction
        // means a later failure silently un-creates a service the customer has
        // already been told about.
        //
        // Rows only - no module, no network. See Action\Provision, and note
        // that this call is an optimisation rather than the mechanism: cron
        // reconciles regardless, so a settlement path that forgets to call it
        // is late, not broken.
        if ($settled) {
            (new Provision())->forInvoice($invoiceId);

            // And the other direction: a service this invoice was holding
            // suspended comes back now rather than at the next tick. Dunning
            // checks its own switch and every other condition itself, so this
            // is a nudge and not a decision.
            (new Dunning())->forInvoice($invoiceId);
        }

        return $settled;
    }

    /**
     * Apply a Client's Credit Balance To An Invoice
     *
     * Takes the smaller of what is owed and what the client holds, so credit is
     * never spent past the balance due and the invoice never over-settles.
     * @param int $invoiceId Invoice ID
     * @return string The amount of credit applied
     */
    public function applyCredit(int $invoiceId): string
    {
        $invoice = $this->find($invoiceId);

        if ($invoice === null) {
            return '0';
        }

        $clientId = (int) $invoice['client_relid'];
        $client = (new Client())->find($clientId);

        if ($client === null) {
            return '0';
        }

        $available = (string) ($client['credit_balance'] ?? '0');
        $owed = $this->balance($invoice);

        if (Money::isZero($available) || Money::isZero($owed)) {
            return '0';
        }

        $applied = Money::round(Money::isGreater($available, $owed) ? $owed : $available);

        $this->model()->transaction(function (InvoiceModel $m) use ($invoiceId, $invoice, $applied): void {
            $m->where([$m->id => $invoiceId])->update([
                'credit_applied'     =>  Money::round(
                    Money::add((string) ($invoice['credit_applied'] ?? '0'), $applied)
                ),
                'invoice_updated_at' =>  $this->now(),
            ]);
        });

        (new Client())->adjustCredit($clientId, '-' . $applied);

        $refreshed = $this->find($invoiceId);

        if ($refreshed !== null && $this->isSettled($refreshed)) {
            $this->markPaid($invoiceId);
        }

        return $applied;
    }

    /**
     * Mark An Invoice Paid
     * @param int $invoiceId Invoice ID
     * @return int Affected rows
     */
    public function markPaid(int $invoiceId): int
    {
        $data = ['invoice_paid_date' => $this->now()];

        $status = Status::idOf(self::STATUSES, 'paid');

        if ($status !== null) {
            $data['status_relid'] = $status;
        }

        $updated = $this->update($invoiceId, $data);

        // The other settlement path. applyCredit() does NOT go through
        // applyPayment() - it writes credit_applied and calls this directly -
        // and staff can reach markPaid() on its own, so both need the nudge or
        // a credit-settled invoice would wait for the next cron tick.
        (new Provision())->forInvoice($invoiceId);
        (new Dunning())->forInvoice($invoiceId);

        return $updated;
    }

    /**
     * Cancel An Invoice
     * @param int|string $key Invoice ID Or Uid
     * @return int Affected rows
     */
    public function cancel(int|string $key): int
    {
        $status = Status::idOf(self::STATUSES, 'cancelled');

        return $status === null ? 0 : $this->update($key, ['status_relid' => $status]);
    }

    /**
     * Delete An Invoice And Its Items
     * @param int|string $key Invoice ID Or Uid
     * @return int Affected rows
     */
    public function remove(int|string $key): int
    {
        $invoice = $this->find($key);

        if ($invoice === null) {
            return 0;
        }

        $id = (int) $invoice['invoice_id'];
        $affected = 0;

        $this->model()->transaction(function (InvoiceModel $m) use ($id, &$affected): void {
            (new InvoiceItemModel())->where(['invoice_relid' => $id])->delete();

            $affected = $m->where([$m->id => $id])->delete();
        });

        return $affected;
    }

    /**
     * The Status Lookup Table This Resource Uses
     *
     * A method rather than the STATUSES constant, because a relay facade
     * forwards method calls and not constants - so a controller reaching this
     * through LBM\Service\* has no way to read the constant directly.
     * @return string
     */
    public function statusTable(): string
    {
        return self::STATUSES;
    }

    /**
     * The Id Of One Named Status
     * @param string $name Status Name. Example: 'active'
     * @return ?int Null when no status of that name exists
     */
    public function statusId(string $name): ?int
    {
        return Status::idOf(self::STATUSES, $name);
    }

    /**
     * The Status Choices a Form Offers
     * @return array
     */
    public function statuses(): array
    {
        return Status::all(self::STATUSES);
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Insert One Line Item, With Its Total Worked Out
     * @param int $invoiceId Invoice ID
     * @param array $item Submitted Item
     * @return int New Item ID
     */
    private function insertItem(int $invoiceId, array $item): int
    {
        $data = $this->nullable(
            $this->only($item, self::ITEM_FIELDS),
            ['service_relid', 'domain_relid', 'period_start', 'period_end']
        );

        $data['invoice_relid']  = $invoiceId;
        $data['item_type_relid'] = (int) ($data['item_type_relid'] ?? 1) ?: 1;
        $data['quantity']       = (string) ($data['quantity'] ?? '1');
        $data['unit_price']     = (string) ($data['unit_price'] ?? '0');
        $data['discount']       = (string) ($data['discount'] ?? '0');
        $data['tax']            = (string) ($data['tax'] ?? '0');
        $data['total']          = $this->itemTotal($data);

        $model = new InvoiceItemModel();

        return (int) $model->insert([
            ...$data,
            $model->uid                  =>  Uid::make(),
            'invoice_item_created_at'    =>  $this->now(),
            'invoice_item_updated_at'    =>  $this->now(),
        ]);
    }

    /**
     * What One Line Item Comes To
     * @param array $item Item Row Or Submitted Item
     * @return string Decimal string
     */
    private function itemTotal(array $item): string
    {
        $line = Money::mul(
            (string) ($item['quantity'] ?? '1'),
            (string) ($item['unit_price'] ?? '0')
        );

        return Money::round(Money::sub($line, (string) ($item['discount'] ?? '0')));
    }

    /**
     * Build An Invoice Number From a Primary Key
     * @param int $invoiceId Invoice ID
     * @return string
     */
    private function number(int $invoiceId): string
    {
        $prefix = option('invoice_prefix', 'INV-') ?? 'INV-';

        return $prefix . str_pad((string) $invoiceId, 6, '0', STR_PAD_LEFT);
    }

    /**
     * The Due Date a New Invoice Gets
     * @return string
     */
    private function defaultDueDate(): string
    {
        $days = option_int('invoice_due_days', 14);
        $days = $days > 0 ? $days : 14;

        return date('Y-m-d H:i:s', strtotime("+{$days} days"));
    }

    /**
     * Reduce Submitted Input To Writable Columns
     * @param array $input Submitted Data
     * @return array
     */
    private function fields(array $input): array
    {
        return $this->nullable(
            $this->only($input, self::FIELDS),
            ['invoice_due_date', 'payment_gateway', 'terms']
        );
    }

    /**
     * Prefix Bare Column Names With a Table
     *
     * A joined query has two `uid` columns and two `status_relid`s; an
     * unqualified name in a where clause is ambiguous and the database says so.
     * @param array $where Conditions
     * @param string $table Table Name
     * @return array
     */
    private function qualify(array $where, string $table): array
    {
        $qualified = [];

        foreach ($where as $column => $value) {
            $key = str_contains((string) $column, '.') ? (string) $column : "{$table}.{$column}";
            $qualified[$key] = $value;
        }

        return $qualified;
    }
}
