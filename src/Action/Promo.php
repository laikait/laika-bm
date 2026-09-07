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
use LBM\Model\PromoCodeModel;
use LBM\Service\Money;
use RuntimeException;

/**
 * Promotional codes - money off, at the operator's invitation.
 *
 * `promo_codes` has existed since Phase 0 with no reader anywhere, and
 * `orders.promo_relid` beside it has been written as NULL by every order the
 * product has ever placed.
 *
 * ---------------------------------------------------------------------------
 * A PROMO DISCOUNTS LINES, NOT THE INVOICE
 * ---------------------------------------------------------------------------
 * `invoices.discount` exists and is the wrong column for this. `applies_to`
 * restricts a code to products or to domains, and an invoice-level figure
 * cannot express "20% off the hosting and nothing off the domain beside it" -
 * it can only be a number somebody has to trust.
 *
 * `invoice_items.discount` can, and Phase 25 already made it tax-correct:
 * `itemTotal()` is `quantity * unit_price - discount`, and `bands()` taxes that
 * - so a discounted line is taxed on what was actually charged for it, with
 * nothing here to get right.
 *
 * ---------------------------------------------------------------------------
 * A FIXED AMOUNT IS SHARED OUT; A PERCENTAGE IS NOT
 * ---------------------------------------------------------------------------
 * Ten off a cart holding three eligible lines is TEN, not thirty. So a fixed
 * code is apportioned pro rata across the lines it applies to, which is the only
 * split whose parts add back up to the discount - the same arithmetic
 * Invoice::bands() uses to share an invoice discount before tax.
 *
 * A percentage needs no such care: it is the same fraction of every line and the
 * parts add up by construction.
 *
 * ---------------------------------------------------------------------------
 * A DISCOUNT NEVER EXCEEDS THE LINE
 * ---------------------------------------------------------------------------
 * Fifty off a twenty-pound line takes twenty. A line total below zero is money
 * the invoice owes the customer, which is a credit note and not a discount, and
 * `Invoice::itemTotal()` would happily produce one.
 *
 * ---------------------------------------------------------------------------
 * A FIXED CODE IS SINGLE-CURRENCY
 * ---------------------------------------------------------------------------
 * `promo_codes.currency_relid` says so - the column comment reads "for fixed
 * type only" - and a customer checking out in another currency simply cannot use
 * it. `Money::convert()` exists, has never been called by anything, and would
 * produce a number here: `currencies.exchange_rate` defaults to 1 on every
 * install, so converting would take 10 EUR off for a 10 USD code and look
 * exactly like a working shop. Product, addon and TLD pricing all refuse for the
 * same reason.
 *
 * A PERCENTAGE has no currency and works in all of them, which is why the column
 * is nullable.
 *
 * ---------------------------------------------------------------------------
 * `used_count` IS CLAIMED, NOT INCREMENTED
 * ---------------------------------------------------------------------------
 * Read-then-write is a race that two checkouts in the same second both win, and
 * "the first 100 customers" going to 102 is a cost somebody has agreed to pay
 * exactly 100 times. claim() is a compare-and-set: it updates the row only while
 * `used_count` is still the value it read, and a zero affected-row count means
 * somebody else got there first. No raw SQL, and no lock held across a network
 * call.
 */
class Promo extends Action
{
    /** @var string[] Columns a Form May Write */
    public const FIELDS = [
        'promo_code', 'promo_type', 'promo_value', 'currency_relid',
        'max_uses', 'applies_to', 'start_date', 'end_date', 'is_active',
    ];

    /** @var string[] How The Value Is Read */
    public const TYPES = ['percentage', 'fixed'];

    /** @var string[] What a Code May Be Spent On */
    public const SCOPES = ['all', 'products', 'domains'];

    /** @var int How Many Times claim() Retries a Lost Race */
    public const CLAIM_TRIES = 3;

    public function model(): Model
    {
        return new PromoCodeModel();
    }

    protected function searchable(): array
    {
        return ['promo_code'];
    }

    protected function createdColumn(): ?string
    {
        return 'promo_created_at';
    }

    protected function updatedColumn(): ?string
    {
        return 'promo_updated_at';
    }

    ####################################################################################
    /*=================================== READING ====================================*/
    ####################################################################################

    /**
     * Every Code
     * @param bool $activeOnly Only Codes Currently Switched On
     * @return array
     */
    public function listing(bool $activeOnly = false): array
    {
        $model = $this->model();

        if ($activeOnly) {
            $model->where(['is_active' => 'yes']);
        }

        return $model->order($model->id, self::DESC)->get();
    }

    /**
     * Find One Code By What The Customer Typed
     *
     * Case-insensitive and trimmed, because a code is read off a poster or
     * pasted out of an email and neither preserves case reliably.
     * `promo_codes.promo_code` is UNIQUE, so at most one row can match.
     * @param string $code Submitted Code
     * @return ?array
     */
    public function byCode(string $code): ?array
    {
        $code = $this->normalise($code);

        if ($code === '') {
            return null;
        }

        foreach ($this->model()->get() as $row) {
            if ($this->normalise((string) $row['promo_code']) === $code) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Why a Code Cannot Be Used, Or Null If It Can
     *
     * A REASON rather than a boolean, because there are five ways for this to
     * fail and they are five different things for the customer to do next: a
     * mistyped code is retyped, an expired one is not, and a code that is fine
     * but priced in another currency is not the customer's problem at all.
     * @param ?array $promo Promo Row, Or Null When Nothing Matched
     * @param int $currencyId The Currency Being Checked Out In
     * @return ?string A reason key, or null when the code is good
     */
    public function refusal(?array $promo, int $currencyId): ?string
    {
        if (!is_array($promo)) {
            return 'unknown';
        }

        if ((string) ($promo['is_active'] ?? 'no') !== 'yes') {
            // Deliberately the same answer as `unknown`. A code that is real but
            // switched off must not be distinguishable from one that does not
            // exist, or the shop is an oracle for guessing codes.
            return 'unknown';
        }

        $now = strtotime($this->now());
        $start = strtotime((string) ($promo['start_date'] ?? ''));
        $end = (string) ($promo['end_date'] ?? '');

        if ($start !== false && $now < $start) {
            return 'not_started';
        }

        if ($end !== '' && strtotime($end) !== false && $now > strtotime($end)) {
            return 'expired';
        }

        if ($this->usedUp($promo)) {
            return 'used_up';
        }

        // A fixed amount is money, and money has a currency. See the class
        // docblock for why this is a refusal rather than a conversion.
        if ((string) ($promo['promo_type'] ?? '') === 'fixed'
            && (int) ($promo['currency_relid'] ?? 0) !== $currencyId) {
            return 'currency';
        }

        return null;
    }

    /**
     * Whether a Code Has Been Spent As Often As It May Be
     * @param array $promo Promo Row
     * @return bool
     */
    public function usedUp(array $promo): bool
    {
        $max = $promo['max_uses'] ?? null;

        // NULL is unlimited, and 0 is too - the column is unsigned and a
        // campaign nobody may use is not a thing an operator means to create.
        if ($max === null || (int) $max <= 0) {
            return false;
        }

        return (int) ($promo['used_count'] ?? 0) >= (int) $max;
    }

    /**
     * Whether One Line Is Something This Code May Be Spent On
     *
     * A line is `product`, `addon` or `domain`. An ADDON counts as a product:
     * it is sold on a product page, it hangs off a plan, and a customer given
     * "20% off hosting" who is charged full price for the backups on it has been
     * given something other than what the words say.
     * @param array $promo Promo Row
     * @param array $line A Line: type, product, amount
     * @return bool
     */
    public function covers(array $promo, array $line): bool
    {
        $scope = (string) ($promo['applies_to'] ?? 'all');
        $type = (string) ($line['type'] ?? 'product');

        if ($scope === 'domains') {
            return $type === 'domain';
        }

        if ($scope === 'products') {
            if ($type === 'domain') {
                return false;
            }

            $only = $promo['product_ids'] ?? null;

            // No list means every product. A list means only those, and a line
            // with no product behind it cannot be on any list.
            if (!is_array($only) || $only === []) {
                return true;
            }

            $productId = (int) ($line['product'] ?? 0);

            return $productId > 0 && in_array($productId, array_map('intval', $only), true);
        }

        return true;
    }

    /**
     * What One Code Takes Off a Set Of Lines
     *
     * The single place a promo turns into money. Both the cart and the invoice
     * go through it, so the figure a customer is shown and the figure they are
     * charged are the same arithmetic on the same inputs - which is the whole
     * reason 22.2 stopped the cart storing prices.
     *
     * @param array $promo Promo Row
     * @param array<int|string,array{type:string,product?:int,amount:string}> $lines
     * @return array<int|string,string> The discount per line key, zeroes omitted
     */
    public function discountFor(array $promo, array $lines): array
    {
        $eligible = [];
        $subtotal = '0';

        foreach ($lines as $key => $line) {
            if (!$this->covers($promo, $line)) {
                continue;
            }

            $amount = Money::round((string) ($line['amount'] ?? '0'));

            if (Money::isZero($amount)) {
                continue;
            }

            $eligible[$key] = $amount;
            $subtotal = Money::add($subtotal, $amount);
        }

        if ($eligible === [] || Money::isZero($subtotal)) {
            return [];
        }

        $value = Money::round((string) ($promo['promo_value'] ?? '0'));

        if (Money::isZero($value)) {
            return [];
        }

        if ((string) ($promo['promo_type'] ?? 'percentage') === 'percentage') {
            $out = [];

            foreach ($eligible as $key => $amount) {
                // Capped at 100. A percentage above it is somebody typing 120
                // into a box, and the line below zero that follows is a credit
                // note pretending to be a discount.
                $rate = Money::isGreater($value, '100') ? '100' : $value;
                $out[$key] = Money::round(Money::percent($amount, $rate));
            }

            return $out;
        }

        // Fixed. Never more than the eligible lines come to in total, then
        // shared pro rata - see the class docblock.
        $taking = Money::isGreater($value, $subtotal) ? $subtotal : $value;

        return $this->share($taking, $eligible, $subtotal);
    }

    /**
     * What a Discount Set Adds Up To
     * @param array<int|string,string> $discounts From discountFor()
     * @return string Decimal string
     */
    public function totalOf(array $discounts): string
    {
        $total = '0';

        foreach ($discounts as $amount) {
            $total = Money::add($total, (string) $amount);
        }

        return Money::round($total);
    }

    /**
     * How a Code Reads On a Screen
     * @param array $promo Promo Row
     * @return string
     */
    public function describe(array $promo): string
    {
        $value = (string) ($promo['promo_value'] ?? '0');

        return (string) ($promo['promo_type'] ?? 'percentage') === 'percentage'
            ? Money::round($value) . '%'
            : money($value, $promo['currency_relid'] ?? null);
    }

    /**
     * How a Code May Be Written
     * @return string[]
     */
    public function types(): array
    {
        return self::TYPES;
    }

    /**
     * What a Code May Be Spent On
     * @return string[]
     */
    public function scopes(): array
    {
        return self::SCOPES;
    }

    ####################################################################################
    /*=================================== WRITING ====================================*/
    ####################################################################################

    /**
     * Spend One Use Of a Code
     *
     * COMPARE-AND-SET, not read-then-increment. See the class docblock: two
     * checkouts in the same second both win a read-then-write, and a code with a
     * hundred uses on it goes to a hundred and two.
     *
     * The update carries the value that was read in its WHERE, so exactly one of
     * two simultaneous callers changes a row and the other is told to look
     * again. Retried a few times because losing the race is ordinary rather than
     * exceptional - the second caller is usually still entitled to the code.
     *
     * Model methods only, and no lock held across anything slow.
     * @param int $promoId Promo ID
     * @return bool Whether a use was taken
     */
    public function claim(int $promoId): bool
    {
        for ($try = 0; $try < self::CLAIM_TRIES; $try++) {
            $promo = $this->find($promoId);

            if ($promo === null) {
                return false;
            }

            if ($this->usedUp($promo)) {
                return false;
            }

            $seen = (int) ($promo['used_count'] ?? 0);

            $model = new PromoCodeModel();
            $affected = (int) $model->where([
                'promo_id'   =>  $promoId,
                'used_count' =>  $seen,
            ])->update(['used_count' => $seen + 1]);

            if ($affected > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Give One Use Back
     *
     * For an order that was deleted before it was ever invoiced. Not for a
     * cancelled one: the code WAS spent, the campaign counted it, and quietly
     * handing it back turns a limit somebody set into a suggestion.
     * @param int $promoId Promo ID
     * @return void
     */
    public function release(int $promoId): void
    {
        $promo = $this->find($promoId);

        if ($promo === null) {
            return;
        }

        $seen = (int) ($promo['used_count'] ?? 0);

        if ($seen <= 0) {
            return;
        }

        (new PromoCodeModel())->where([
            'promo_id'   =>  $promoId,
            'used_count' =>  $seen,
        ])->update(['used_count' => $seen - 1]);
    }

    /**
     * Create a Code
     * @param array $input Submitted Data
     * @return int The promo ID
     * @throws RuntimeException
     */
    public function store(array $input): int
    {
        $data = $this->clean($input);

        if ($this->codeTaken((string) $data['promo_code'], null)) {
            throw new RuntimeException('A code with that name already exists.');
        }

        return $this->create($data + [
            'product_ids' =>  $this->productIds($input),
            'used_count'  =>  0,
        ]);
    }

    /**
     * Update a Code
     *
     * `used_count` is NOT writable from a form. It is a count of what has
     * happened, and a box that lets somebody set it to zero is a way to give a
     * limited campaign away twice.
     * @param int|string $key Promo ID Or Uid
     * @param array $input Submitted Data
     * @return int Affected rows
     * @throws RuntimeException
     */
    public function modify(int|string $key, array $input): int
    {
        $promo = $this->find($key);

        if ($promo === null) {
            throw new RuntimeException('That code no longer exists.');
        }

        $data = $this->clean($input);
        $id = (int) $promo['promo_id'];

        if ($this->codeTaken((string) $data['promo_code'], $id)) {
            throw new RuntimeException('A code with that name already exists.');
        }

        return $this->update($id, $data + ['product_ids' => $this->productIds($input)]);
    }

    /**
     * Delete a Code
     *
     * Refused once an order has been placed with it. `orders.promo_relid` points
     * here, and an order whose code has gone is one no screen can explain the
     * price of - deactivation is what stops a code being used again.
     * @param int|string $key Promo ID Or Uid
     * @return int Affected rows
     * @throws RuntimeException
     */
    public function remove(int|string $key): int
    {
        $promo = $this->find($key);

        if ($promo === null) {
            return 0;
        }

        $id = (int) $promo['promo_id'];
        $used = (int) (new \LBM\Model\OrderModel())->where(['promo_relid' => $id])->count();

        if ($used > 0) {
            throw new RuntimeException(
                "This code is on {$used} order(s). Deactivate it instead of deleting it."
            );
        }

        return $this->delete($id);
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Share One Amount Across Lines, Pro Rata
     *
     * The remainder goes on the LAST line rather than being dropped. Rounding
     * each share independently loses a penny or two, and a discount whose parts
     * do not add up to the figure the customer was shown is one somebody queries
     * and nobody can explain.
     * @param string $taking The Whole Discount
     * @param array<int|string,string> $eligible Line amounts, keyed
     * @param string $subtotal What They Come To
     * @return array<int|string,string>
     */
    private function share(string $taking, array $eligible, string $subtotal): array
    {
        $out = [];
        $given = '0';
        $last = array_key_last($eligible);

        foreach ($eligible as $key => $amount) {
            if ($key === $last) {
                $out[$key] = Money::round(Money::sub($taking, $given));

                break;
            }

            $part = Money::round(Money::mul($taking, Money::div($amount, $subtotal)));
            $out[$key] = $part;
            $given = Money::add($given, $part);
        }

        return $out;
    }

    /**
     * A Code As It Is Compared
     * @param string $code Submitted Or Stored Code
     * @return string
     */
    private function normalise(string $code): string
    {
        return strtoupper(trim($code));
    }

    /**
     * The Product List, Encoded By Hand
     *
     * `product_ids` is a json column and the model's cast decodes on READ and
     * never encodes on write - 22.1's finding about `serialize` columns, and it
     * is the same here. Writing an array straight in would store the word
     * "Array" and every later read of the table would come back with nothing.
     * @param array $input Submitted Data
     * @return ?string
     */
    private function productIds(array $input): ?string
    {
        $ids = $input['product_ids'] ?? null;

        if (!is_array($ids)) {
            return null;
        }

        $clean = [];

        foreach ($ids as $id) {
            $id = (int) $id;

            if ($id > 0) {
                $clean[$id] = $id;
            }
        }

        return $clean === [] ? null : (string) json_encode(array_values($clean));
    }

    /**
     * Reduce And Normalise Submitted Input
     * @param array $input Submitted Data
     * @return array
     * @throws RuntimeException
     */
    private function clean(array $input): array
    {
        $data = $this->only($input, self::FIELDS);

        $code = $this->normalise((string) ($data['promo_code'] ?? ''));

        if ($code === '') {
            throw new RuntimeException('A code needs something to type.');
        }

        $type = (string) ($data['promo_type'] ?? 'percentage');
        $type = in_array($type, self::TYPES, true) ? $type : 'percentage';

        $scope = (string) ($data['applies_to'] ?? 'all');

        $value = Money::round((string) ($data['promo_value'] ?? '0'));

        if (!Money::isGreater($value, '0')) {
            throw new RuntimeException('A code has to take something off.');
        }

        $currency = (int) ($data['currency_relid'] ?? 0);

        // A fixed amount is money and money has a currency. A percentage has
        // none, and storing one would be a fact nobody can act on.
        if ($type === 'fixed' && $currency <= 0) {
            throw new RuntimeException('A fixed amount needs a currency.');
        }

        $start = trim((string) ($data['start_date'] ?? ''));
        $end = trim((string) ($data['end_date'] ?? ''));

        $startAt = $start === '' ? $this->now() : $this->when($start);
        $endAt = $end === '' ? null : $this->when($end);

        if ($endAt !== null && strtotime($endAt) < strtotime($startAt)) {
            throw new RuntimeException('A code cannot end before it starts.');
        }

        $max = $data['max_uses'] ?? null;

        return [
            'promo_code'     =>  mb_substr($code, 0, 100),
            'promo_type'     =>  $type,
            'promo_value'    =>  $value,
            'currency_relid' =>  $type === 'fixed' ? $currency : null,
            'max_uses'       =>  $max === null || trim((string) $max) === '' ? null : max(0, (int) $max),
            'applies_to'     =>  in_array($scope, self::SCOPES, true) ? $scope : 'all',
            'start_date'     =>  $startAt,
            'end_date'       =>  $endAt,
            'is_active'      =>  $this->flag($data['is_active'] ?? 'no'),
        ];
    }

    /**
     * A Submitted Date, As The Column Takes It
     * @param string $value Submitted Date
     * @return string
     * @throws RuntimeException
     */
    private function when(string $value): string
    {
        $time = strtotime($value);

        if ($time === false) {
            throw new RuntimeException('That is not a date this can read.');
        }

        return date('Y-m-d H:i:s', $time);
    }

    /**
     * Whether Another Code Already Reads The Same
     *
     * `promo_code` is UNIQUE, so this is the difference between a message the
     * operator can act on and a driver exception on the form. Compared the way
     * byCode() compares, because two codes differing only in case would be one
     * code to every customer who typed either.
     * @param string $code Code
     * @param ?int $ignore Promo ID To Skip
     * @return bool
     */
    private function codeTaken(string $code, ?int $ignore): bool
    {
        $found = $this->byCode($code);

        if (!is_array($found)) {
            return false;
        }

        return $ignore === null || (int) $found['promo_id'] !== $ignore;
    }
}
