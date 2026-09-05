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
use Laika\Service\Uid;
use LBM\Model\ClientServiceAddonModel;
use LBM\Model\ProductAddonMapModel;
use LBM\Model\ProductAddonModel;
use LBM\Model\ProductAddonPricingModel;
use LBM\Service\Money;
use RuntimeException;

/**
 * Addons - the extras sold alongside a product.
 *
 * Four tables that have existed since Phase 0 with nothing reading them:
 * `product_addons` (what the extra is), `product_addon_map` (which products
 * offer it), `product_addon_pricing` (what it costs, per currency and cycle) and
 * `client_service_addons` (what somebody actually bought). `order_items` was
 * shaped for them too - its `type` enum already carries `addon`, and
 * `addon_relid` beside it.
 *
 * AN ADDON IS ITS OWN ORDER LINE, ATTACHED TO A SERVICE
 *
 * Not a modifier folded into the product's price. Two reasons, and both of them
 * are about what happens later rather than what happens at checkout:
 *
 *   - A customer cancels one extra and keeps the plan. That is an ordinary thing
 *     to do and it is only expressible if the extra is its own row with its own
 *     status and its own due date.
 *   - An invoice has to say what the money is for. "Hosting 45.00" where 15 of
 *     it is backups is an invoice somebody queries, and staff cannot answer.
 *
 * So an addon line carries BOTH `addon_relid` and the `product_relid` of the
 * plan it hangs off. That second one is what lets Provision put the addon on the
 * right service when an order holds two plans: order lines have no parent link,
 * and grouping by (product, domain) is the arrangement already in place.
 *
 * PRICING IS PER CURRENCY AND CYCLE, LIKE A PRODUCT'S
 *
 * `product_addon_pricing` is keyed on (addon, currency, cycle), so an addon
 * offered on a cycle the operator never priced is simply not offered - the same
 * rule `Product::price()` follows, and the same reason: a missing price is a
 * question the catalogue must not answer with zero.
 *
 * A `one_time` addon is priced on the `one_time` cycle and billed once, the way
 * 22.2's setup fee is. It never reaches `client_service_addons`, because there
 * is nothing recurring to record.
 */
class Addon extends Action
{
    /** @var string[] Columns a Form May Write */
    public const FIELDS = [
        'addon_name', 'description', 'pricing_model', 'is_active',
    ];

    /** @var string[] How An Addon Is Billed */
    public const PRICING_MODELS = ['recurring', 'one_time'];

    public function model(): Model
    {
        return new ProductAddonModel();
    }

    protected function searchable(): array
    {
        return ['addon_name'];
    }

    protected function createdColumn(): ?string
    {
        return 'addon_created_at';
    }

    protected function updatedColumn(): ?string
    {
        return 'addon_updated_at';
    }

    ####################################################################################
    /*================================= EXTERNAL API =================================*/
    ####################################################################################

    /**
     * Every Addon
     * @param bool $activeOnly Only Addons Currently Offered
     * @return array
     */
    public function listing(bool $activeOnly = false): array
    {
        $model = $this->model();

        if ($activeOnly) {
            $model->where(['is_active' => 'yes']);
        }

        return $model->order('addon_name', self::ASC)->get();
    }

    /**
     * The Addons One Product Offers
     *
     * Withdrawn addons are dropped rather than shown greyed out: an addon a
     * customer cannot order is not information, it is a dead control.
     * @param int $productId Product ID
     * @param bool $activeOnly Only Addons Currently Offered
     * @return array
     */
    public function forProduct(int $productId, bool $activeOnly = true): array
    {
        $ids = $this->mappedIds($productId);

        if ($ids === []) {
            return [];
        }

        $model = $this->model();
        $model->whereIn('addon_id', $ids);

        if ($activeOnly) {
            $model->where(['is_active' => 'yes']);
        }

        return $model->order('addon_name', self::ASC)->get();
    }

    /**
     * The Addon Ids One Product Offers
     * @param int $productId Product ID
     * @return int[]
     */
    public function mappedIds(int $productId): array
    {
        $ids = [];

        foreach ((new ProductAddonMapModel())->where(['product_relid' => $productId])->get() as $row) {
            $ids[] = (int) $row['addon_relid'];
        }

        return $ids;
    }

    /**
     * Replace Which Addons a Product Offers
     *
     * The whole set at once, because the product screen posts every checkbox -
     * and reconciling one at a time would leave a half-applied map if the
     * request died between two of them.
     * @param int $productId Product ID
     * @param int[] $addonIds Addon Ids To Offer
     * @return int How many are mapped afterwards
     */
    public function mapToProduct(int $productId, array $addonIds): int
    {
        $wanted = [];

        foreach ($addonIds as $id) {
            $id = (int) $id;

            // Only addons that exist. A posted id for a deleted addon would
            // otherwise sit in the map for ever, unresolvable and invisible.
            if ($id > 0 && $this->find($id) !== null) {
                $wanted[$id] = $id;
            }
        }

        $map = new ProductAddonMapModel();

        $map->transaction(function (ProductAddonMapModel $m) use ($productId, $wanted): void {
            $m->where(['product_relid' => $productId])->delete();

            foreach ($wanted as $addonId) {
                $m->insert([
                    'uid'           =>  Uid::make(),
                    'product_relid' =>  $productId,
                    'addon_relid'   =>  $addonId,
                ]);
            }
        });

        return count($wanted);
    }

    /**
     * Every Price On One Addon
     * @param int $addonId Addon ID
     * @return array
     */
    public function pricing(int $addonId): array
    {
        return (new ProductAddonPricingModel())->where(['addon_relid' => $addonId])->get();
    }

    /**
     * What One Addon Costs, On One Cycle, In One Currency
     *
     * Null when the operator has not priced that combination, which is the
     * answer that keeps it off the order form. Zero would be a free addon, and
     * those are different things.
     * @param int $addonId Addon ID
     * @param int $currencyId Currency ID
     * @param int $cycleId Billing Cycle ID
     * @return ?array
     */
    public function price(int $addonId, int $currencyId, int $cycleId): ?array
    {
        $row = (new ProductAddonPricingModel())->where([
            'addon_relid'         =>  $addonId,
            'currency_relid'      =>  $currencyId,
            'billing_cycle_relid' =>  $cycleId,
        ])->first();

        return is_array($row) ? $row : null;
    }

    /**
     * Set Or Clear One Addon Price
     *
     * A blank price DELETES the row rather than storing zero, because those mean
     * opposite things: no row is "not offered on this cycle", zero is "offered,
     * free". The product pricing grid has the same rule.
     * @param int $addonId Addon ID
     * @param int $currencyId Currency ID
     * @param int $cycleId Billing Cycle ID
     * @param int|float|string|null $price Price, Or Null To Withdraw
     * @return void
     */
    public function setPrice(
        int $addonId,
        int $currencyId,
        int $cycleId,
        int|float|string|null $price
    ): void {
        $model = new ProductAddonPricingModel();

        $where = [
            'addon_relid'         =>  $addonId,
            'currency_relid'      =>  $currencyId,
            'billing_cycle_relid' =>  $cycleId,
        ];

        if ($price === null || trim((string) $price) === '') {
            (new ProductAddonPricingModel())->where($where)->delete();

            return;
        }

        $amount = Money::round((string) $price);
        $existing = $model->where($where)->first();

        if (is_array($existing)) {
            (new ProductAddonPricingModel())
                ->where(['pap_id' => (int) $existing['pap_id']])
                ->update(['addon_price' => $amount]);

            return;
        }

        (new ProductAddonPricingModel())->insert([
            ...$where,
            'uid'         =>  Uid::make(),
            'addon_price' =>  $amount,
        ]);
    }

    /**
     * Create An Addon
     * @param array $input Submitted Data
     * @return int The addon ID
     * @throws RuntimeException
     */
    public function store(array $input): int
    {
        $data = $this->clean($input);

        if ($this->nameTaken((string) $data['addon_name'], null)) {
            throw new RuntimeException('An addon called that already exists.');
        }

        return $this->create($data);
    }

    /**
     * Update An Addon
     * @param int|string $key Addon ID Or Uid
     * @param array $input Submitted Data
     * @return int Affected rows
     * @throws RuntimeException
     */
    public function modify(int|string $key, array $input): int
    {
        $addon = $this->find($key);

        if ($addon === null) {
            throw new RuntimeException('That addon no longer exists.');
        }

        $data = $this->clean($input);
        $id = (int) $addon['addon_id'];

        if ($this->nameTaken((string) $data['addon_name'], $id)) {
            throw new RuntimeException('An addon called that already exists.');
        }

        return $this->update($id, $data);
    }

    /**
     * Delete An Addon
     *
     * Refused while anybody is paying for it. A deleted addon takes its prices
     * and its product mappings with it, and a `client_service_addons` row
     * pointing at nothing is a charge on a customer's account that no screen can
     * name - so this is deactivation's job, not deletion's.
     * @param int|string $key Addon ID Or Uid
     * @return int Affected rows
     * @throws RuntimeException
     */
    public function remove(int|string $key): int
    {
        $addon = $this->find($key);

        if ($addon === null) {
            return 0;
        }

        $id = (int) $addon['addon_id'];
        $sold = (new ClientServiceAddonModel())->where(['addon_relid' => $id])->count();

        if ($sold > 0) {
            throw new RuntimeException(
                "This addon is on {$sold} service(s). Deactivate it instead of deleting it."
            );
        }

        (new ProductAddonPricingModel())->where(['addon_relid' => $id])->delete();
        (new ProductAddonMapModel())->where(['addon_relid' => $id])->delete();

        return $this->delete($id);
    }

    /**
     * The Addons On One Service
     * @param int $serviceId Service ID
     * @return array
     */
    public function forService(int $serviceId): array
    {
        $model = new ClientServiceAddonModel();

        return $model->where(['service_relid' => $serviceId])
            ->order($model->id, self::ASC)
            ->get();
    }

    /**
     * The Addons On One Service, Each Carrying The Name Of What It Is
     *
     * `client_service_addons` stores an id and an amount, so a screen reading
     * straight off it says "#7, 5.00" - which the customer being charged for it
     * cannot act on and staff cannot answer questions about. Both the panel and
     * the admin screen use this rather than each joining it themselves.
     * @param int $serviceId Service ID
     * @return array<int,array<string,mixed>>
     */
    public function detailedFor(int $serviceId): array
    {
        $rows = [];

        foreach ($this->forService($serviceId) as $row) {
            $addon = $this->find((int) ($row['addon_relid'] ?? 0));

            $rows[] = $row + [
                'addon_name' =>  (string) ($addon['addon_name'] ?? 'Addon'),
                'addon_uid'  =>  (string) ($addon['uid'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * Record An Addon Against a Service
     *
     * The service's own cycle and due date, not the addon's: an extra that
     * renewed on a different day from the plan it hangs off would raise its own
     * invoice for a few pounds every month, which is a worse experience than the
     * money involved justifies.
     * @param array $service Service Row
     * @param int $addonId Addon ID
     * @param string $amount What Is Being Charged
     * @return int The new row id
     */
    public function attach(array $service, int $addonId, string $amount): int
    {
        $model = new ClientServiceAddonModel();

        return (int) $model->insert([
            'uid'                 =>  Uid::make(),
            'service_relid'       =>  (int) $service['service_id'],
            'addon_relid'         =>  $addonId,
            'billing_cycle_relid' =>  (int) $service['billing_cycle_relid'],
            'currency_relid'      =>  (int) $service['currency_relid'],
            'amount'              =>  Money::round($amount),
            'status_relid'        =>  (int) $service['status_relid'],
            'next_due_date'       =>  $service['next_due_date'] ?? null,
            'csa_created_at'      =>  $this->now(),
        ]);
    }

    /**
     * What The Addons On a Service Add Up To
     * @param int $serviceId Service ID
     * @return string Decimal string
     */
    public function totalFor(int $serviceId): string
    {
        $total = '0';

        foreach ($this->forService($serviceId) as $row) {
            $total = Money::add($total, (string) ($row['amount'] ?? '0'));
        }

        return Money::round($total);
    }

    /**
     * How An Addon Is Billed
     * @return string[]
     */
    public function pricingModels(): array
    {
        return self::PRICING_MODELS;
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Reduce And Normalise Submitted Input
     * @param array $input Submitted Data
     * @return array
     * @throws RuntimeException
     */
    private function clean(array $input): array
    {
        $data = $this->only($input, self::FIELDS);

        $name = trim((string) ($data['addon_name'] ?? ''));

        if ($name === '') {
            throw new RuntimeException('An addon needs a name.');
        }

        $model = (string) ($data['pricing_model'] ?? 'recurring');

        $data['addon_name']    = $name;
        $data['description']   = trim((string) ($data['description'] ?? ''));
        $data['pricing_model'] = in_array($model, self::PRICING_MODELS, true) ? $model : 'recurring';
        $data['is_active']     = $this->flag($data['is_active'] ?? 'yes');

        return $data;
    }

    /**
     * Whether Another Addon Already Has That Name
     *
     * `addon_name` is UNIQUE, so this is the difference between a message the
     * operator can act on and a driver exception on the form.
     * @param string $name Addon Name
     * @param ?int $ignore Addon ID To Skip
     * @return bool
     */
    private function nameTaken(string $name, ?int $ignore): bool
    {
        $row = (new ProductAddonModel())->where(['addon_name' => $name])->first();

        if (!is_array($row)) {
            return false;
        }

        return $ignore === null || (int) $row['addon_id'] !== $ignore;
    }
}
