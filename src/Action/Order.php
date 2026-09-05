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

use RuntimeException;
use Laika\Model\Model;
use Laika\Service\Visitor;
use LBM\Model\ClientModel;
use LBM\Model\OrderModel;
use LBM\Model\OrderItemModel;
use LBM\Service\Money;
use LBM\Service\Status;
use Laika\Service\Uid;

/**
 * Orders - what a client asked for, before it becomes something they are billed
 * for.
 *
 * An order is a request. Accepting one is what turns it into money owed: that
 * step raises the invoice, links the two, and moves the order to `active`. Until
 * then nothing has been charged, which is why an order can be edited freely and
 * an invoice cannot.
 */
class Order extends Action
{
    /** @var string Status Lookup Table */
    public const STATUSES = 'order_statuses';

    /** @var string[] Columns a Form May Write */
    public const FIELDS = [
        'client_relid', 'currency_relid', 'status_relid', 'promo_relid', 'amount',
    ];

    /** @var string[] Columns An Item Form May Write */
    public const ITEM_FIELDS = [
        'type', 'product_relid', 'addon_relid', 'billing_cycle', 'domain',
        'quantity', 'amount',
    ];

    /** @var string[] What An Order Line Can Be */
    public const ITEM_TYPES = ['product', 'addon', 'domain'];

    public function model(): Model
    {
        return new OrderModel();
    }

    protected function searchable(): array
    {
        return ['order_number'];
    }

    protected function createdColumn(): ?string
    {
        return 'order_created_at';
    }

    protected function updatedColumn(): ?string
    {
        return 'order_updated_at';
    }

    ####################################################################################
    /*=================================== READING ====================================*/
    ####################################################################################

    /**
     * One Page Of Orders, With The Client Who Placed Them
     * @param array $where Conditions
     * @param ?string $search Search Term
     * @param ?int $limit Rows Per Page
     * @return array
     */
    public function browseWithClients(array $where = [], ?string $search = null, ?int $limit = null): array
    {
        $orders = new OrderModel();
        $clients = new ClientModel();

        $o = $orders->table;
        $c = $clients->table;

        $qualified = [];

        foreach ($where as $column => $value) {
            $key = str_contains((string) $column, '.') ? (string) $column : "{$o}.{$column}";
            $qualified[$key] = $value;
        }

        $counted = new OrderModel();
        $this->conditions($counted, $qualified);

        $listed = new OrderModel();
        $listed->select([
            "{$o}.*",
            "{$c}.first_name AS client_first_name",
            "{$c}.last_name AS client_last_name",
            "{$c}.company_name AS client_company_name",
        ])->join($c, "{$c}.{$clients->id}", '=', "{$o}.client_relid");

        $this->conditions($listed, $qualified);

        if ($search !== null && trim($search) !== '') {
            $term = '%' . trim($search) . '%';
            $columns = [
                "{$o}.order_number" =>  $term,
                "{$c}.first_name"   =>  $term,
                "{$c}.last_name"    =>  $term,
                "{$c}.company_name" =>  $term,
            ];

            $counted->join($c, "{$c}.{$clients->id}", '=', "{$o}.client_relid")
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
     * Every Line On An Order
     * @param int $orderId Order ID
     * @return array
     */
    public function items(int $orderId): array
    {
        $model = new OrderItemModel();

        return $model->where(['order_relid' => $orderId])
            ->order($model->id, self::ASC)
            ->get();
    }

    /**
     * Orders Placed By One Client
     * @param int $clientId Client ID
     * @param ?int $limit Rows Per Page
     * @return array
     */
    public function browseForClient(int $clientId, ?int $limit = null): array
    {
        return $this->browse(['client_relid' => $clientId], null, $limit, self::DESC);
    }

    /**
     * Find An Order, Scoped To Its Client
     * @param int|string $key Order ID Or Uid
     * @param int $clientId Client ID
     * @return ?array
     */
    public function forClientKey(int|string $key, int $clientId): ?array
    {
        $model = $this->model();

        $row = $this->key($model, $key)->where(['client_relid' => $clientId])->first();

        return is_array($row) ? $row : null;
    }

    ####################################################################################
    /*=================================== WRITING ====================================*/
    ####################################################################################

    /**
     * Place An Order
     *
     * Numbered from the primary key after the insert, for the same reason as an
     * invoice: deriving the next number from a count is a race that two orders
     * placed in the same second lose.
     * @param array $input Submitted Data
     * @param array $items Order Lines
     * @return int New Order ID
     */
    public function store(array $input, array $items = []): int
    {
        $data = $this->fields($input);

        $data['status_relid'] = (int) ($data['status_relid'] ?? 0)
            ?: (Status::idOf(self::STATUSES, 'pending') ?? 1);

        $data['order_from_ip'] = $data['order_from_ip'] ?? $this->ip();

        $uid = Uid::make();
        $data['uid'] = $uid;
        $data['order_number'] = $uid;
        $data['amount'] = $data['amount'] ?? '0';

        $id = 0;

        $this->model()->transaction(function (OrderModel $m) use ($data, $items, &$id): void {
            $id = (int) $m->insert($this->stamp($data, true));

            foreach ($items as $item) {
                $this->insertItem($id, $item);
            }

            $m->where([$m->id => $id])->update(['order_number' => $this->number($id)]);
        });

        $this->recalculate($id);

        return $id;
    }

    /**
     * Update An Order
     * @param int|string $key Order ID Or Uid
     * @param array $input Submitted Data
     * @return int Affected rows
     */
    public function modify(int|string $key, array $input): int
    {
        return $this->update($key, $this->fields($input));
    }

    /**
     * Replace An Order's Lines
     * @param int $orderId Order ID
     * @param array $items Order Lines
     * @return void
     */
    public function replaceItems(int $orderId, array $items): void
    {
        (new OrderItemModel())->transaction(function (OrderItemModel $m) use ($orderId, $items): void {
            $m->where(['order_relid' => $orderId])->delete();

            foreach ($items as $item) {
                $this->insertItem($orderId, $item);
            }
        });

        $this->recalculate($orderId);
    }

    /**
     * Recalculate An Order's Total From Its Lines
     * @param int $orderId Order ID
     * @return string The new total
     */
    public function recalculate(int $orderId): string
    {
        $total = '0';

        foreach ($this->items($orderId) as $item) {
            $total = Money::add(
                $total,
                Money::mul((string) ($item['quantity'] ?? '1'), (string) ($item['amount'] ?? '0'))
            );
        }

        $total = Money::round($total);

        $model = $this->model();
        $model->where([$model->id => $orderId])->update([
            'amount'           =>  $total,
            'order_updated_at' =>  $this->now(),
        ]);

        return $total;
    }

    /**
     * Accept An Order And Raise Its Invoice
     *
     * One invoice per order, and the link is recorded on the order so accepting
     * twice cannot produce two. That check is the whole reason this is a single
     * method rather than a controller calling Invoice::store() itself.
     *
     * `$activate` separates the two halves of what "accept" used to mean, and
     * they are not the same act. Raising the invoice says what is owed; moving
     * the order to `active` says the operator has agreed to deliver it. A member
     * of staff pressing Accept means both, which is why that stays the default
     * and the admin panel is unchanged.
     *
     * A customer checking out means only the first. Their order needs an invoice
     * to pay against, but nothing has been paid yet - and an order that goes
     * active because somebody pressed a button is an order provisioned for free.
     * It stays pending until staff accept it, or until Phase 22.4 does when the
     * invoice settles.
     * @param int|string $key Order ID Or Uid
     * @param bool $activate Whether To Move The Order To Active
     * @return int The invoice ID
     * @throws RuntimeException
     */
    public function accept(int|string $key, bool $activate = true): int
    {
        $order = $this->find($key);

        if ($order === null) {
            throw new RuntimeException('That order no longer exists.');
        }

        if (!empty($order['invoice_relid'])) {
            throw new RuntimeException('This order has already been invoiced.');
        }

        $orderId = (int) $order['oid'];
        $lines = $this->items($orderId);

        if ($lines === []) {
            throw new RuntimeException('An order with no items cannot be invoiced.');
        }

        // The client is read once for the whole order, and each product once
        // however many lines it is on. rateForClient() would do both again per
        // line, and an order with ten lines is not the place to find that out.
        $client    = (new Client())->find((int) ($order['client_relid'] ?? 0));
        $tax       = new Tax();
        $inclusive = $tax->inclusive();
        $products  = [];

        $items = [];

        foreach ($lines as $line) {
            $productId = (int) ($line['product_relid'] ?? 0);

            if ($productId > 0 && !array_key_exists($productId, $products)) {
                $products[$productId] = (new Product())->find($productId);
            }

            // Snapshotted here and never asked for again. An invoice states
            // what was charged on a day; re-deriving its rate from whatever the
            // rules say later would reprice a document somebody has already
            // paid. Action\Tax makes the whole argument.
            $rate   = $tax->rateFor($client, $products[$productId] ?? null);
            $amount = (string) ($line['amount'] ?? '0');

            $items[] = [
                'description'   =>  $this->describe($line),
                'quantity'      =>  (string) ($line['quantity'] ?? '1'),

                // Under inclusive pricing the catalogue figure already has the
                // tax in it, so the net is what goes on the line and the tax is
                // added back on top - which lands on the same total the
                // customer was shown, by a route the invoice can state.
                'unit_price'    =>  $inclusive ? $tax->netOf($amount, $rate) : $amount,
                'tax'           =>  $rate,
                'service_relid' =>  $line['service_relid'] ?? null,
                'domain_relid'  =>  $line['domain_relid'] ?? null,
            ];
        }

        $invoiceId = (new Invoice())->store([
            'client_relid'   =>  $order['client_relid'],
            'currency_relid' =>  $order['currency_relid'],
        ], $items);

        $data = ['invoice_relid' => $invoiceId];

        $active = $activate ? Status::idOf(self::STATUSES, 'active') : null;

        if ($active !== null) {
            $data['status_relid'] = $active;
        }

        $this->update($orderId, $data);

        return $invoiceId;
    }

    /**
     * Cancel An Order
     * @param int|string $key Order ID Or Uid
     * @return int Affected rows
     */
    public function cancel(int|string $key): int
    {
        $status = Status::idOf(self::STATUSES, 'cancelled');

        return $status === null ? 0 : $this->update($key, ['status_relid' => $status]);
    }

    /**
     * Delete An Order And Its Lines
     *
     * Refuses once an invoice has been raised: the invoice is the financial
     * record, and deleting what it was raised from leaves it unexplained.
     * @param int|string $key Order ID Or Uid
     * @return int Affected rows
     * @throws RuntimeException
     */
    public function remove(int|string $key): int
    {
        $order = $this->find($key);

        if ($order === null) {
            return 0;
        }

        if (!empty($order['invoice_relid'])) {
            throw new RuntimeException(
                'This order has been invoiced. Cancel it instead of deleting it.'
            );
        }

        $id = (int) $order['oid'];
        $affected = 0;

        $this->model()->transaction(function (OrderModel $m) use ($id, &$affected): void {
            (new OrderItemModel())->where(['order_relid' => $id])->delete();

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
     * What An Order Line Can Be
     *
     * A method rather than the constant, because a relay facade forwards method
     * calls and not constants.
     * @return string[]
     */
    public function itemTypes(): array
    {
        return self::ITEM_TYPES;
    }

    /**
     * Insert One Order Line
     * @param int $orderId Order ID
     * @param array $item Submitted Line
     * @return int New Line ID
     */
    private function insertItem(int $orderId, array $item): int
    {
        $data = $this->nullable(
            $this->only($item, self::ITEM_FIELDS),
            ['product_relid', 'addon_relid', 'billing_cycle', 'domain']
        );

        $type = (string) ($data['type'] ?? 'product');

        $data['type'] = in_array($type, self::ITEM_TYPES, true) ? $type : 'product';
        $data['order_relid'] = $orderId;
        $data['quantity'] = (int) ($data['quantity'] ?? 1) ?: 1;
        $data['amount'] = Money::round((string) ($data['amount'] ?? '0'));

        $model = new OrderItemModel();

        return (int) $model->insert([
            ...$data,
            $model->uid                =>  Uid::make(),
            'order_item_created_at'    =>  $this->now(),
            'order_item_updated_at'    =>  $this->now(),
        ]);
    }

    /**
     * A Human Description Of An Order Line, For The Invoice
     * @param array $line Order Line
     * @return string
     */
    private function describe(array $line): string
    {
        $type = (string) ($line['type'] ?? 'product');

        if ($type === 'domain') {
            return 'Domain: ' . (string) ($line['domain'] ?? 'domain registration');
        }

        $product = null;

        if (!empty($line['product_relid'])) {
            $product = (new Product())->find((int) $line['product_relid']);
        }

        $name = $product['product_name'] ?? ucfirst($type);
        $cycle = (string) ($line['billing_cycle'] ?? '');

        return $cycle === '' ? $name : "{$name} ({$cycle})";
    }

    /**
     * Build An Order Number From a Primary Key
     * @param int $orderId Order ID
     * @return string
     */
    private function number(int $orderId): string
    {
        $prefix = option('order_prefix', 'ORD-') ?? 'ORD-';

        return $prefix . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT);
    }

    /**
     * The Visitor's IP, Or Nothing In a Non-Web Context
     * @return ?string
     */
    private function ip(): ?string
    {
        $ip = Visitor::ip();

        return is_string($ip) && $ip !== '' ? $ip : null;
    }

    /**
     * Reduce Submitted Input To Writable Columns
     * @param array $input Submitted Data
     * @return array
     */
    private function fields(array $input): array
    {
        $data = $this->nullable($this->only($input, self::FIELDS), ['promo_relid']);

        if (array_key_exists('amount', $data)) {
            $data['amount'] = Money::round((string) $data['amount']);
        }

        return $data;
    }
}
