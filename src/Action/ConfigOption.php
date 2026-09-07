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
use LBM\Model\ClientServiceConfigValueModel;
use LBM\Model\OrderItemConfigValueModel;
use LBM\Model\ProductConfigGroupMapModel;
use LBM\Model\ProductConfigGroupModel;
use LBM\Model\ProductConfigOptionModel;
use LBM\Model\ProductConfigOptionPricingModel;
use LBM\Model\ProductConfigOptionSubModel;
use LBM\Service\Money;
use RuntimeException;

/**
 * Configurable options - how one product is sold in more than one size.
 *
 * Five tables from Phase 0 with nothing reading them: `product_config_groups`
 * (the field), `product_config_options` (its type), `product_config_option_subs`
 * (the choices), `product_config_option_pricing` (what each choice costs) and
 * `client_service_config_values` (what somebody actually chose). The catalogue
 * could sell "VPS" and "VPS with more RAM" as two products and nothing else.
 *
 * ---------------------------------------------------------------------------
 * A CONFIGURABLE OPTION IS NOT AN ORDER LINE. IT IS PART OF THE PLAN'S PRICE
 * ---------------------------------------------------------------------------
 * This is the decision the whole phase turns on, and it is the OPPOSITE of the
 * one Phase 26.1 made for addons - so it needs saying why rather than assuming.
 *
 * The schema decided it first. `client_service_addons` carries five columns of
 * commercial life of its own: an amount, a currency, a cycle, a status and a due
 * date. `client_service_config_values` carries NONE of them - a chosen sub, a
 * quantity, a text value, and no money anywhere. One of those is a thing that is
 * bought; the other is an answer about the thing that was bought.
 *
 * And that matches what they are. A customer can cancel daily backups and keep
 * the hosting. Nobody can cancel the RAM in their VPS and keep the VPS. An
 * option with its own status would offer a button that cannot mean anything.
 *
 * So the price of the choices is added to the plan's own unit price, the order
 * line's `amount` carries the sum, and `client_services.amount` - which is what
 * every renewal is billed from - carries it onward for free. The invoice names
 * the choices in the line's description, because "VPS Hosting 65.00" where 20 of
 * it is RAM is still an invoice somebody rings up about.
 *
 * ---------------------------------------------------------------------------
 * THE GROUP IS THE FIELD
 * ---------------------------------------------------------------------------
 * `product_config_options` has no name column. The only label anywhere is
 * `product_config_groups.config_group_name`, so a group holding two options
 * would put two fields on the order form under one heading, with nothing to tell
 * the customer which is which. One group, one option row, created and deleted
 * together - and optionFor() reads the first, so a hand-written second row is
 * ignored rather than half-rendered.
 *
 * ---------------------------------------------------------------------------
 * WHAT EACH TYPE MEANS
 * ---------------------------------------------------------------------------
 *   - `dropdown` / `radio` - exactly one choice. The same thing with different
 *     markup, which is why they share every rule below.
 *   - `checkbox`   - any number of its choices, including none.
 *   - `quantity`   - one choice, priced per unit, times a number the customer
 *     types. `client_service_config_values.quantity` exists for exactly this.
 *   - `text`       - free text and NO PRICE, because pricing keys on a chosen
 *     sub and a text field has none. That is the schema being honest rather than
 *     a gap: a hostname or a licence name is information, not a charge.
 *
 * ---------------------------------------------------------------------------
 * A MISSING PRICE IS NOT A FREE ONE
 * ---------------------------------------------------------------------------
 * `product_config_option_pricing` is keyed on (sub, currency, cycle), so a
 * choice the operator never priced on the cycle being ordered is UNAVAILABLE -
 * the whole line is refused rather than the choice being quietly dropped or
 * charged at zero. Product::price() and Addon::price() have the same rule and
 * 22.2 is where the reason was learned: a checkout that silently drops part of
 * what somebody chose invoices them for the rest of it.
 *
 * ORDERING IS BY ID, deliberately. Neither the options nor the subs have a sort
 * column, and sorting "16 GB" and "8 GB" alphabetically puts them in the wrong
 * order. Creation order is what the operator typed, which is the closest honest
 * answer available without a migration.
 */
class ConfigOption extends Action
{
    /** @var string[] Columns The Group Form May Write */
    public const GROUP_FIELDS = ['config_group_name', 'description'];

    /** @var string[] Columns The Choice Form May Write */
    public const SUB_FIELDS = ['option_name', 'is_active'];

    /** @var string[] What Kind Of Field a Group Is */
    public const TYPES = ['dropdown', 'radio', 'checkbox', 'text', 'quantity'];

    /**
     * @var int Most Of Any One Quantity Option
     *
     * The schema has no min or max columns, so there is nothing per-option to
     * honour and this is the only bound there is. Cart::MAX_QUANTITY's
     * reasoning: a posted number has to stop somewhere before it reaches a
     * session row or a decimal(18,4).
     */
    public const MAX_QUANTITY = 999;

    /** @var int Longest a Text Answer May Be - the column is varchar(500) */
    public const MAX_TEXT = 500;

    public function model(): Model
    {
        return new ProductConfigGroupModel();
    }

    protected function searchable(): array
    {
        return ['config_group_name'];
    }

    protected function createdColumn(): ?string
    {
        return 'pcg_created_at';
    }

    protected function updatedColumn(): ?string
    {
        return null;
    }

    ####################################################################################
    /*=================================== CATALOGUE ==================================*/
    ####################################################################################

    /**
     * Every Group, Each Carrying Its Option Row And Its Choices
     * @param bool $withSubs Load The Choices Too
     * @return array<int,array<string,mixed>>
     */
    public function listing(bool $withSubs = true): array
    {
        $rows = [];

        foreach ($this->model()->order('config_group_name', self::ASC)->get() as $group) {
            $rows[] = $this->detail($group, $withSubs, false);
        }

        return $rows;
    }

    /**
     * One Group, With Its Option Row And Its Choices
     * @param int|string $key Group ID Or Uid
     * @param bool $activeOnly Only Choices Still Offered
     * @return ?array
     */
    public function detailed(int|string $key, bool $activeOnly = false): ?array
    {
        $group = $this->find($key);

        return $group === null ? null : $this->detail($group, true, $activeOnly);
    }

    /**
     * The Option Row Belonging To One Group
     *
     * The FIRST one. See the class docblock: a group is one field, so a second
     * option row is data nobody can render and is ignored rather than guessed
     * at.
     * @param int $groupId Group ID
     * @return ?array
     */
    public function optionFor(int $groupId): ?array
    {
        $model = new ProductConfigOptionModel();

        $row = $model->where(['config_group_relid' => $groupId])
            ->order($model->id, self::ASC)
            ->first();

        return is_array($row) ? $row : null;
    }

    /**
     * The Choices Under One Option
     *
     * By id, not by name - see the class docblock. There is no sort column and
     * "16 GB" sorts before "8 GB".
     * @param int $optionId Option ID
     * @param bool $activeOnly Only Choices Still Offered
     * @return array
     */
    public function subs(int $optionId, bool $activeOnly = false): array
    {
        $model = new ProductConfigOptionSubModel();
        $model->where(['pco_relid' => $optionId]);

        if ($activeOnly) {
            $model->where(['is_active' => 'yes']);
        }

        return $model->order($model->id, self::ASC)->get();
    }

    /**
     * One Choice
     * @param int $subId Sub ID
     * @return ?array
     */
    public function sub(int $subId): ?array
    {
        $row = (new ProductConfigOptionSubModel())->where(['pcos_id' => $subId])->first();

        return is_array($row) ? $row : null;
    }

    /**
     * The Groups One Product Offers
     *
     * Withdrawn choices are dropped from each group rather than shown greyed
     * out, and a group left with no choices at all is dropped with them: a
     * dropdown with nothing in it is a required question nobody can answer.
     * A `text` group has no choices by definition and is always kept.
     * @param int $productId Product ID
     * @param bool $activeOnly Only Choices Still Offered
     * @return array<int,array<string,mixed>>
     */
    public function forProduct(int $productId, bool $activeOnly = true): array
    {
        $ids = $this->mappedIds($productId);

        if ($ids === []) {
            return [];
        }

        $model = $this->model();
        $groups = $model->whereIn('pcg_id', $ids)
            ->order('config_group_name', self::ASC)
            ->get();

        $rows = [];

        foreach ($groups as $group) {
            $detail = $this->detail($group, true, $activeOnly);

            if ($detail['option'] === null) {
                continue;
            }

            if ($detail['type'] !== 'text' && $detail['subs'] === []) {
                continue;
            }

            $rows[] = $detail;
        }

        return $rows;
    }

    /**
     * The Group Ids One Product Offers
     * @param int $productId Product ID
     * @return int[]
     */
    public function mappedIds(int $productId): array
    {
        $ids = [];

        foreach ((new ProductConfigGroupMapModel())->where(['product_relid' => $productId])->get() as $row) {
            $ids[] = (int) $row['config_group_relid'];
        }

        return $ids;
    }

    /**
     * Replace Which Groups a Product Offers
     *
     * The whole set at once, for Addon::mapToProduct()'s reason: the product
     * screen posts every checkbox, and reconciling one at a time leaves a
     * half-applied map behind a request that died in the middle.
     * @param int $productId Product ID
     * @param int[] $groupIds Group Ids To Offer
     * @return int How many are mapped afterwards
     */
    public function mapToProduct(int $productId, array $groupIds): int
    {
        $wanted = [];

        foreach ($groupIds as $id) {
            $id = (int) $id;

            // Only groups that exist. A posted id for a deleted group would sit
            // in the map for ever, unresolvable and invisible.
            if ($id > 0 && $this->find($id) !== null) {
                $wanted[$id] = $id;
            }
        }

        $map = new ProductConfigGroupMapModel();

        $map->transaction(function (ProductConfigGroupMapModel $m) use ($productId, $wanted): void {
            $m->where(['product_relid' => $productId])->delete();

            foreach ($wanted as $groupId) {
                $m->insert([
                    'uid'                =>  Uid::make(),
                    'product_relid'      =>  $productId,
                    'config_group_relid' =>  $groupId,
                ]);
            }
        });

        return count($wanted);
    }

    /**
     * How Many Products Offer Each Group, Keyed By Group ID
     * @return array<int,int>
     */
    public function productCounts(): array
    {
        $counts = [];

        foreach ((new ProductConfigGroupMapModel())->get() as $row) {
            $id = (int) $row['config_group_relid'];
            $counts[$id] = ($counts[$id] ?? 0) + 1;
        }

        return $counts;
    }

    ####################################################################################
    /*==================================== PRICING ===================================*/
    ####################################################################################

    /**
     * Every Price On One Choice
     * @param int $subId Sub ID
     * @return array
     */
    public function pricing(int $subId): array
    {
        return (new ProductConfigOptionPricingModel())->where(['pcos_relid' => $subId])->get();
    }

    /**
     * What One Choice Costs, On One Cycle, In One Currency
     *
     * Null when the operator has not priced that combination, which is what
     * keeps it off the order form. Zero would be a choice included at no extra
     * charge, and those are different answers.
     * @param int $subId Sub ID
     * @param int $currencyId Currency ID
     * @param int $cycleId Billing Cycle ID
     * @return ?array
     */
    public function price(int $subId, int $currencyId, int $cycleId): ?array
    {
        $row = (new ProductConfigOptionPricingModel())->where([
            'pcos_relid'          =>  $subId,
            'currency_relid'      =>  $currencyId,
            'billing_cycle_relid' =>  $cycleId,
        ])->first();

        return is_array($row) ? $row : null;
    }

    /**
     * Set Or Clear One Choice's Price
     *
     * A blank price DELETES the row rather than storing zero. No row is "not
     * offered on this cycle"; zero is "offered, included". The product and addon
     * grids have the same rule.
     *
     * A setup fee with no price beside it still writes a row, because a choice
     * that costs something once and nothing thereafter is a real thing to sell.
     * @param int $subId Sub ID
     * @param int $currencyId Currency ID
     * @param int $cycleId Billing Cycle ID
     * @param int|float|string|null $price Price, Or Null To Withdraw
     * @param int|float|string|null $setup Setup Fee
     * @return void
     */
    public function setPrice(
        int $subId,
        int $currencyId,
        int $cycleId,
        int|float|string|null $price,
        int|float|string|null $setup = null
    ): void {
        $where = [
            'pcos_relid'          =>  $subId,
            'currency_relid'      =>  $currencyId,
            'billing_cycle_relid' =>  $cycleId,
        ];

        $blank = static fn(int|float|string|null $v): bool => $v === null || trim((string) $v) === '';

        if ($blank($price) && $blank($setup)) {
            (new ProductConfigOptionPricingModel())->where($where)->delete();

            return;
        }

        $data = [
            'price'     =>  Money::round($blank($price) ? '0' : (string) $price),
            'setup_fee' =>  Money::round($blank($setup) ? '0' : (string) $setup),
        ];

        $existing = (new ProductConfigOptionPricingModel())->where($where)->first();

        if (is_array($existing)) {
            (new ProductConfigOptionPricingModel())
                ->where(['pcop_id' => (int) $existing['pcop_id']])
                ->update($data);

            return;
        }

        (new ProductConfigOptionPricingModel())->insert([
            ...$where,
            ...$data,
            'uid' =>  Uid::make(),
        ]);
    }

    ####################################################################################
    /*=================================== RESOLVING ==================================*/
    ####################################################################################

    /**
     * Turn What a Customer Posted Into Priced, Checked Choices
     *
     * The one method the shop side depends on. Given the raw `config` array off
     * a form - keyed by option id - it answers either a list of resolved choices
     * or NULL, and null means the whole line is unorderable.
     *
     * Null rather than a filtered list, deliberately. 22.2's finding: a checkout
     * that drops the part of somebody's choice it cannot honour shows them a
     * refusal and invoices them for the rest. The caller refuses the line.
     *
     * Every resolved choice is priced HERE, out of the database, at the moment
     * it is needed - Cart's rule, and the reason a cart holds no money.
     *
     * @param array $chosen Submitted config, keyed by option id
     * @param int $productId Product ID
     * @param int $currencyId Currency ID
     * @param int $cycleId Billing Cycle ID
     * @return ?array<int,array<string,mixed>>
     */
    public function resolve(
        array $chosen,
        int $productId,
        int $currencyId,
        int $cycleId
    ): ?array {
        $resolved = [];

        foreach ($this->forProduct($productId) as $group) {
            $option = $group['option'];
            $optionId = (int) $option['pco_id'];
            $type = (string) $group['type'];
            $required = (string) ($option['is_required'] ?? 'no') === 'yes';

            $answer = $chosen[$optionId] ?? ($chosen[(string) $optionId] ?? null);

            if ($type === 'text') {
                $text = trim((string) (is_array($answer) ? '' : ($answer ?? '')));

                if ($text === '' && $required) {
                    return null;
                }

                if ($text === '') {
                    continue;
                }

                $resolved[] = [
                    'pco'         =>  $optionId,
                    'group'       =>  (string) $group['name'],
                    'type'        =>  $type,
                    'sub'         =>  null,
                    'choice'      =>  mb_substr($text, 0, self::MAX_TEXT),
                    'quantity'    =>  null,
                    'price'       =>  '0',
                    'setup_fee'   =>  '0',
                    'text'        =>  mb_substr($text, 0, self::MAX_TEXT),
                ];

                continue;
            }

            $subs = [];

            foreach ($group['subs'] as $sub) {
                $subs[(int) $sub['pcos_id']] = $sub;
            }

            $picked = $this->pickedSubs($answer, $type, $subs);

            if ($picked === null) {
                return null;
            }

            if ($picked === [] && $required) {
                return null;
            }

            $quantity = null;

            if ($type === 'quantity') {
                $quantity = $this->clampQuantity($answer);

                // Zero is a legitimate answer to "how many extra IP addresses" -
                // it means none - and a required quantity option is one the
                // customer has to answer with a number, not with a number above
                // zero. So zero drops the choice entirely rather than pricing
                // a line at nothing.
                if ($quantity < 1) {
                    continue;
                }
            }

            foreach ($picked as $subId) {
                $row = $subs[$subId];
                $price = $this->price($subId, $currencyId, $cycleId);

                // Not priced on this cycle in this currency. The whole line
                // goes, for the reason in the docblock above.
                if (!is_array($price)) {
                    return null;
                }

                // BOTH FIGURES OFF THE SAME ROW. The recurring price and the
                // one-off fee for a choice are two columns of one (choice,
                // currency, cycle) row, exactly as they are for a product -
                // so there is one place to read either and no second source to
                // add in. An earlier draft also looked up the `one_time` row
                // and summed its setup fee onto this one, which charges the
                // operator's setup fee twice the moment they fill both in.
                $each = Money::round((string) ($price['price'] ?? '0'));
                $setup = Money::round((string) ($price['setup_fee'] ?? '0'));

                $multiplier = (string) ($quantity ?? 1);

                $resolved[] = [
                    'pco'       =>  $optionId,
                    'group'     =>  (string) $group['name'],
                    'type'      =>  $type,
                    'sub'       =>  $subId,
                    'choice'    =>  (string) ($row['option_name'] ?? ''),
                    'quantity'  =>  $quantity,
                    'price'     =>  Money::round(Money::mul($multiplier, $each)),
                    'setup_fee' =>  Money::round(Money::mul($multiplier, $setup)),
                    'text'      =>  null,
                ];
            }
        }

        return $resolved;
    }

    /**
     * What The Chosen Options Add To The Recurring Price
     * @param array $resolved Choices From resolve()
     * @return string Decimal string
     */
    public function priceOf(array $resolved): string
    {
        $total = '0';

        foreach ($resolved as $choice) {
            $total = Money::add($total, (string) ($choice['price'] ?? '0'));
        }

        return Money::round($total);
    }

    /**
     * What The Chosen Options Add To The One-Off Charge
     * @param array $resolved Choices From resolve()
     * @return string Decimal string
     */
    public function setupOf(array $resolved): string
    {
        $total = '0';

        foreach ($resolved as $choice) {
            $total = Money::add($total, (string) ($choice['setup_fee'] ?? '0'));
        }

        return Money::round($total);
    }

    /**
     * The Choices, Written Out For An Invoice Line
     *
     * `invoice_items.description` is varchar(500) and the plan's own name is
     * already in it, so this is capped rather than trusted to fit. A truncated
     * description is a cosmetic problem; one the driver refuses outright takes
     * the checkout down.
     * @param array $resolved Choices From resolve()
     * @param int $limit Longest The Result May Be
     * @return string
     */
    public function describeChoices(array $resolved, int $limit = 300): string
    {
        $parts = [];

        foreach ($resolved as $choice) {
            $label = (string) ($choice['group'] ?? '');
            $value = (string) ($choice['choice'] ?? '');
            $quantity = $choice['quantity'] ?? null;

            if ($label === '' || $value === '') {
                continue;
            }

            $parts[] = $quantity === null
                ? $label . ': ' . $value
                : $label . ': ' . $value . ' x ' . (int) $quantity;
        }

        if ($parts === []) {
            return '';
        }

        $text = implode(', ', $parts);

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 1) . "\u{2026}" : $text;
    }

    ####################################################################################
    /*==================================== RECORDS ===================================*/
    ####################################################################################

    /**
     * Record The Choices Against An Order Line
     *
     * Inside the caller's transaction, because an order line whose choices did
     * not get written is a plan provisioned with the wrong configuration and
     * nothing anywhere to say what was meant.
     * @param int $orderItemId Order Item ID
     * @param array $resolved Choices From resolve()
     * @return int How many rows were written
     */
    public function attachToItem(int $orderItemId, array $resolved): int
    {
        if ($orderItemId <= 0 || $resolved === []) {
            return 0;
        }

        $model = new OrderItemConfigValueModel();
        $written = 0;

        foreach ($resolved as $choice) {
            $model->insert([
                'uid'              =>  Uid::make(),
                'order_item_relid' =>  $orderItemId,
                'pco_relid'        =>  (int) ($choice['pco'] ?? 0),
                'pcos_relid'       =>  $choice['sub'] === null ? null : (int) $choice['sub'],
                'quantity'         =>  $choice['quantity'] === null ? null : (int) $choice['quantity'],
                'text_value'       =>  $choice['text'],
            ]);

            $written++;
        }

        return $written;
    }

    /**
     * The Choices Recorded Against One Order Line
     * @param int $orderItemId Order Item ID
     * @return array
     */
    public function valuesForItem(int $orderItemId): array
    {
        $model = new OrderItemConfigValueModel();

        return $model->where(['order_item_relid' => $orderItemId])
            ->order($model->id, self::ASC)
            ->get();
    }

    /**
     * Forget The Choices On One Order Line
     *
     * Called wherever order lines are deleted. A value row pointing at a line
     * that no longer exists is unreachable from every screen and is still
     * copied onto a service by anything that reads by id.
     * @param int $orderItemId Order Item ID
     * @return int Affected rows
     */
    public function detachFromItem(int $orderItemId): int
    {
        return (int) (new OrderItemConfigValueModel())
            ->where(['order_item_relid' => $orderItemId])
            ->delete();
    }

    /**
     * Copy An Order Line's Choices Onto The Service It Made
     *
     * Column for column. The order-side and service-side tables are deliberately
     * the same shape - see OrderItemConfigValueSchema - so this is a copy rather
     * than a translation, and there is no room for a value to arrive at the
     * server different from the one that was paid for.
     *
     * THERE IS NO GUARD HERE AGAINST COPYING TWICE, deliberately. Provision
     * reaches this once per service because `order_items.service_relid` is
     * written to every line of the group the moment the service exists, and
     * groupLines() skips a line that carries one - so reconcile() can sweep as
     * often as it likes and never arrive here again. A draft of this method
     * did carry a "has this service any configuration already" check; it was
     * removed after a sabotage deleting it changed NOTHING, which is the whole
     * of the evidence that it was dead. Two idempotency stories where there is
     * one marker means somebody eventually trusts the weaker one.
     * @param int $orderItemId Order Item ID
     * @param int $serviceId Service ID
     * @return int How many rows were copied
     */
    public function copyToService(int $orderItemId, int $serviceId): int
    {
        if ($orderItemId <= 0 || $serviceId <= 0) {
            return 0;
        }

        $copied = 0;

        foreach ($this->valuesForItem($orderItemId) as $row) {
            (new ClientServiceConfigValueModel())->insert([
                'uid'           =>  Uid::make(),
                'service_relid' =>  $serviceId,
                'pco_relid'     =>  (int) ($row['pco_relid'] ?? 0),
                'pcos_relid'    =>  $this->orNull($row['pcos_relid'] ?? null),
                'quantity'      =>  $this->orNull($row['quantity'] ?? null),
                'text_value'    =>  $row['text_value'] ?? null,
            ]);

            $copied++;
        }

        return $copied;
    }

    /**
     * The Choices On One Service
     * @param int $serviceId Service ID
     * @return array
     */
    public function forService(int $serviceId): array
    {
        $model = new ClientServiceConfigValueModel();

        return $model->where(['service_relid' => $serviceId])
            ->order($model->id, self::ASC)
            ->get();
    }

    /**
     * The Choices On One Service, Each Carrying The Names Behind Its Ids
     *
     * A row here is three integers. A screen reading straight off it prints
     * "#4: #11" at the customer paying for it - which is 26.1's finding, live on
     * the panel for a whole release because the table it rendered was empty.
     * Both the panel and the admin service screen use this.
     * @param int $serviceId Service ID
     * @return array<int,array<string,mixed>>
     */
    public function detailedFor(int $serviceId): array
    {
        $rows = [];

        foreach ($this->forService($serviceId) as $row) {
            $rows[] = $this->nameValue($row, (int) ($row['pco_relid'] ?? 0));
        }

        return $rows;
    }

    /**
     * The Choices On One Order Line, Each Carrying The Names Behind Its Ids
     * @param int $orderItemId Order Item ID
     * @return array<int,array<string,mixed>>
     */
    public function detailedForItem(int $orderItemId): array
    {
        $rows = [];

        foreach ($this->valuesForItem($orderItemId) as $row) {
            $rows[] = $this->nameValue($row, (int) ($row['pco_relid'] ?? 0));
        }

        return $rows;
    }

    ####################################################################################
    /*==================================== WRITING ===================================*/
    ####################################################################################

    /**
     * Create a Group And The Option Row Under It
     *
     * Both together, because a group without one is a heading with no field
     * beneath it - invisible on the order form and impossible to tell from a
     * group whose option row failed to write.
     * @param array $input Submitted Data
     * @return int The group ID
     * @throws RuntimeException
     */
    public function store(array $input): int
    {
        $data = $this->clean($input);

        if ($this->nameTaken((string) $data['config_group_name'], null)) {
            throw new RuntimeException('A configurable option called that already exists.');
        }

        $groupId = $this->create($data);

        (new ProductConfigOptionModel())->insert([
            'uid'                =>  Uid::make(),
            'config_group_relid' =>  $groupId,
            'config_type'        =>  $this->cleanType($input['config_type'] ?? null),
            'is_required'        =>  $this->flag($input['is_required'] ?? 'no'),
        ]);

        return $groupId;
    }

    /**
     * Update a Group And Its Option Row
     * @param int|string $key Group ID Or Uid
     * @param array $input Submitted Data
     * @return int Affected rows
     * @throws RuntimeException
     */
    public function modify(int|string $key, array $input): int
    {
        $group = $this->find($key);

        if ($group === null) {
            throw new RuntimeException('That configurable option no longer exists.');
        }

        $data = $this->clean($input);
        $id = (int) $group['pcg_id'];

        if ($this->nameTaken((string) $data['config_group_name'], $id)) {
            throw new RuntimeException('A configurable option called that already exists.');
        }

        $affected = $this->update($id, $data);
        $option = $this->optionFor($id);

        $fields = [
            'config_type' =>  $this->cleanType($input['config_type'] ?? null),
            'is_required' =>  $this->flag($input['is_required'] ?? 'no'),
        ];

        // A group whose option row has gone - deleted by hand, or written by a
        // release that predates this one - gets a new one rather than silently
        // staying unrenderable.
        if ($option === null) {
            (new ProductConfigOptionModel())->insert([
                ...$fields,
                'uid'                =>  Uid::make(),
                'config_group_relid' =>  $id,
            ]);

            return $affected;
        }

        (new ProductConfigOptionModel())
            ->where(['pco_id' => (int) $option['pco_id']])
            ->update($fields);

        return $affected;
    }

    /**
     * Delete a Group, Its Option, Its Choices And Their Prices
     *
     * Refused while anybody is on a service configured with it. Addon::remove()
     * has the same rule for the same reason: a `client_service_config_values`
     * row pointing at nothing is part of what a customer is paying for that no
     * screen can name.
     * @param int|string $key Group ID Or Uid
     * @return int Affected rows
     * @throws RuntimeException
     */
    public function remove(int|string $key): int
    {
        $group = $this->find($key);

        if ($group === null) {
            return 0;
        }

        $id = (int) $group['pcg_id'];
        $option = $this->optionFor($id);
        $optionId = (int) ($option['pco_id'] ?? 0);

        if ($optionId > 0) {
            $sold = (new ClientServiceConfigValueModel())->where(['pco_relid' => $optionId])->count();

            if ($sold > 0) {
                throw new RuntimeException(
                    "This option is configured on {$sold} service(s). Remove it from your products instead of deleting it."
                );
            }

            foreach ($this->subs($optionId) as $sub) {
                $this->removeSub((int) $sub['pcos_id']);
            }

            (new ProductConfigOptionModel())->where(['pco_id' => $optionId])->delete();
        }

        (new ProductConfigGroupMapModel())->where(['config_group_relid' => $id])->delete();

        return $this->delete($id);
    }

    /**
     * Add a Choice To a Group
     * @param int $groupId Group ID
     * @param array $input Submitted Data
     * @return int The new sub ID
     * @throws RuntimeException
     */
    public function addSub(int $groupId, array $input): int
    {
        $option = $this->optionFor($groupId);

        if ($option === null) {
            throw new RuntimeException('That configurable option has no field to add choices to.');
        }

        $data = $this->cleanSub($input);

        return (int) (new ProductConfigOptionSubModel())->insert([
            ...$data,
            'uid'       =>  Uid::make(),
            'pco_relid' =>  (int) $option['pco_id'],
        ]);
    }

    /**
     * Update One Choice
     * @param int $subId Sub ID
     * @param array $input Submitted Data
     * @return int Affected rows
     * @throws RuntimeException
     */
    public function modifySub(int $subId, array $input): int
    {
        return (int) (new ProductConfigOptionSubModel())
            ->where(['pcos_id' => $subId])
            ->update($this->cleanSub($input));
    }

    /**
     * Delete One Choice And Its Prices
     *
     * Refused while a service is configured with it, for remove()'s reason.
     * @param int $subId Sub ID
     * @return int Affected rows
     * @throws RuntimeException
     */
    public function removeSub(int $subId): int
    {
        $sold = (new ClientServiceConfigValueModel())->where(['pcos_relid' => $subId])->count();

        if ($sold > 0) {
            throw new RuntimeException(
                "This choice is on {$sold} service(s). Deactivate it instead of deleting it."
            );
        }

        (new ProductConfigOptionPricingModel())->where(['pcos_relid' => $subId])->delete();

        return (int) (new ProductConfigOptionSubModel())->where(['pcos_id' => $subId])->delete();
    }

    /**
     * What Kind Of Field a Group Can Be
     * @return string[]
     */
    public function types(): array
    {
        return self::TYPES;
    }

    /**
     * Longest a Text Answer May Be
     *
     * A relay forwards method calls and NOT constants, so LBM\Service\n     * ConfigOption::MAX_TEXT is a fatal rather than a number. Cart needs the
     * bound and reaches the whole class through the facade, so it comes
     * through here - the accessor convention this project settled on.
     * @return int
     */
    public function maxText(): int
    {
        return self::MAX_TEXT;
    }

    /**
     * Most Of Any One Quantity Option
     * @return int
     */
    public function maxQuantity(): int
    {
        return self::MAX_QUANTITY;
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Fold a Group Row Together With Its Option And Choices
     * @param array $group Group Row
     * @param bool $withSubs Load The Choices
     * @param bool $activeOnly Only Choices Still Offered
     * @return array<string,mixed>
     */
    private function detail(array $group, bool $withSubs, bool $activeOnly): array
    {
        $groupId = (int) $group['pcg_id'];
        $option = $this->optionFor($groupId);
        $optionId = (int) ($option['pco_id'] ?? 0);

        return [
            'pcg_id'      =>  $groupId,
            'uid'         =>  (string) ($group['uid'] ?? ''),
            'name'        =>  (string) ($group['config_group_name'] ?? ''),
            'description' =>  (string) ($group['description'] ?? ''),
            'option'      =>  $option,
            'pco_id'      =>  $optionId,
            'type'        =>  (string) ($option['config_type'] ?? 'dropdown'),
            'required'    =>  (string) ($option['is_required'] ?? 'no') === 'yes',
            'subs'        =>  $withSubs && $optionId > 0 ? $this->subs($optionId, $activeOnly) : [],
            'group'       =>  $group,
        ];
    }

    /**
     * Put a Name Beside The Ids On One Stored Value
     * @param array $row Value Row
     * @param int $optionId Option ID
     * @return array<string,mixed>
     */
    private function nameValue(array $row, int $optionId): array
    {
        $group = null;

        if ($optionId > 0) {
            $option = (new ProductConfigOptionModel())->where(['pco_id' => $optionId])->first();

            if (is_array($option)) {
                $group = $this->find((int) $option['config_group_relid']);
            }
        }

        $subId = (int) ($row['pcos_relid'] ?? 0);
        $sub = $subId > 0 ? $this->sub($subId) : null;
        $quantity = (int) ($row['quantity'] ?? 0);
        $name = (string) ($group['config_group_name'] ?? 'Option');

        // The SAME KEYS resolve() hands back, so describeChoices() reads a
        // stored answer and a freshly chosen one without knowing which it
        // has. array_merge and not `+`, because the stored row already
        // carries a `quantity` of its own and `+` keeps the left-hand one -
        // which is the cast-to-int zero this is here to turn back into null.
        return array_merge($row, [
            'group'      =>  $name,
            'group_name' =>  $name,
            'choice'     =>  (string) ($sub['option_name'] ?? (string) ($row['text_value'] ?? '')),
            'quantity'   =>  $quantity > 0 ? $quantity : null,
        ]);
    }

    /**
     * Which Choices a Submitted Answer Names
     *
     * Null means the answer named something this option does not offer - a
     * withdrawn choice, or one belonging to another option entirely - and the
     * caller refuses the line rather than honouring the rest.
     * @param mixed $answer Submitted Answer
     * @param string $type Field Type
     * @param array<int,array> $subs Offered Choices, Keyed By Sub ID
     * @return ?int[]
     */
    private function pickedSubs(mixed $answer, string $type, array $subs): ?array
    {
        // A quantity option is priced against its single choice, and the number
        // the customer typed is the answer - so the sub is implied rather than
        // posted. The FIRST one, which is the only one a quantity field can
        // sensibly have.
        if ($type === 'quantity') {
            $first = array_key_first($subs);

            return $first === null ? null : [(int) $first];
        }

        if ($answer === null || $answer === '' || $answer === []) {
            return [];
        }

        $wanted = is_array($answer) ? $answer : [$answer];

        // Only a checkbox takes more than one. Anything else arriving as an
        // array is a form that has been tampered with, and honouring the first
        // element would charge for one choice while recording another.
        if ($type !== 'checkbox' && count($wanted) > 1) {
            return null;
        }

        $picked = [];

        foreach ($wanted as $value) {
            $id = (int) $value;

            if ($id <= 0) {
                continue;
            }

            if (!isset($subs[$id])) {
                return null;
            }

            $picked[$id] = $id;
        }

        return array_values($picked);
    }

    /**
     * Keep a Submitted Quantity Inside Its Bounds
     * @param mixed $answer Submitted Answer
     * @return int
     */
    private function clampQuantity(mixed $answer): int
    {
        $quantity = (int) (is_array($answer) ? 0 : $answer);

        if ($quantity < 0) {
            return 0;
        }

        return min($quantity, self::MAX_QUANTITY);
    }

    /**
     * A Nullable Integer That Stays Null
     *
     * The model casts `pcos_relid` and `quantity` to int, so a NULL read back
     * out of the database can arrive as 0 - and writing that 0 into the service
     * copy would turn "no choice" into "choice number zero", which nothing can
     * resolve and every screen renders blank.
     * @param mixed $value Stored Value
     * @return ?int
     */
    private function orNull(mixed $value): ?int
    {
        $value = (int) $value;

        return $value > 0 ? $value : null;
    }

    /**
     * Reduce And Normalise a Submitted Group
     * @param array $input Submitted Data
     * @return array
     * @throws RuntimeException
     */
    private function clean(array $input): array
    {
        $data = $this->only($input, self::GROUP_FIELDS);

        $name = trim((string) ($data['config_group_name'] ?? ''));

        if ($name === '') {
            throw new RuntimeException('A configurable option needs a name.');
        }

        $data['config_group_name'] = mb_substr($name, 0, 100);
        $data['description'] = trim((string) ($data['description'] ?? ''));

        return $data;
    }

    /**
     * Reduce And Normalise a Submitted Choice
     * @param array $input Submitted Data
     * @return array
     * @throws RuntimeException
     */
    private function cleanSub(array $input): array
    {
        $data = $this->only($input, self::SUB_FIELDS);

        $name = trim((string) ($data['option_name'] ?? ''));

        if ($name === '') {
            throw new RuntimeException('A choice needs a name.');
        }

        return [
            'option_name' =>  mb_substr($name, 0, 255),
            'is_active'   =>  $this->flag($data['is_active'] ?? 'yes'),
        ];
    }

    /**
     * Keep a Submitted Field Type Inside The Enum
     * @param mixed $type Submitted Type
     * @return string
     */
    private function cleanType(mixed $type): string
    {
        $type = (string) (is_array($type) ? '' : $type);

        return in_array($type, self::TYPES, true) ? $type : 'dropdown';
    }

    /**
     * Whether Another Group Already Has That Name
     *
     * `config_group_name` is UNIQUE, so this is the difference between a message
     * the operator can act on and a driver exception on the form.
     * @param string $name Group Name
     * @param ?int $ignore Group ID To Skip
     * @return bool
     */
    private function nameTaken(string $name, ?int $ignore): bool
    {
        $row = (new ProductConfigGroupModel())->where(['config_group_name' => $name])->first();

        if (!is_array($row)) {
            return false;
        }

        return $ignore === null || (int) $row['pcg_id'] !== $ignore;
    }
}
