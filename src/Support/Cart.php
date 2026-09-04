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

namespace LBM\Support;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Laika\Session\Session;
use Laika\Session\SessionManager;
use LBM\Model\BillingCycleModel;
use LBM\Service\Currency;
use LBM\Service\Money;
use LBM\Service\Product;

/**
 * The shopping cart - what a visitor has chosen, before there is an order.
 *
 * THE ONE RULE THIS CLASS EXISTS TO ENFORCE: the cart stores identifiers and a
 * quantity, and NOTHING ELSE. No price, no product name, no total. Every figure
 * a visitor is shown, and every figure that reaches an order, is read out of the
 * database at the moment it is needed.
 *
 * That is not tidiness. The cart lives in the session, and a session is written
 * on the strength of a request - so a price cached in it is a price the browser
 * had a hand in. Storing one would mean an operator's price change did not reach
 * a cart that was already open, and it would put the amount charged one bug away
 * from being attacker-chosen. Phase 22.3's callbacks have the same rule for the
 * same reason: never trust an amount that came from the browser.
 *
 * Consequences worth knowing:
 *
 *   - A product retired while a cart is open drops out of it, loudly. lines()
 *     marks the line with a `problem` instead of silently pricing it at zero.
 *   - A price withdrawn does the same.
 *   - The total moves if the operator changes a price mid-session. That is
 *     correct: the price on the page and the price charged are the same number
 *     because they are the same read.
 *
 * The cart is NOT scoped to a client. It belongs to the browser, so a visitor
 * can fill one and sign in afterwards - which is the ordinary journey, and why
 * the namespace is its own rather than PANEL's. Auth::logout() purges PANEL and
 * leaves this alone, deliberately: signing out is not a decision about a basket.
 */
class Cart
{
    /** @var string Session Namespace - Its Own, Not PANEL's */
    public const SCOPE = 'CART';

    /** @var string Session Key Holding The Lines */
    public const KEY = 'items';

    /** @var int Most Lines One Cart May Hold */
    public const MAX_LINES = 20;

    /** @var int Most Of Any One Line */
    public const MAX_QUANTITY = 99;

    ####################################################################################
    /*=================================== READING ====================================*/
    ####################################################################################

    /**
     * The Raw Stored Lines, Keyed By Line Key
     *
     * Identifiers and quantities only - see the class docblock. Anything else
     * found in the session is discarded rather than trusted, because a stored
     * shape from an older release is exactly the sort of thing that survives an
     * upgrade and then gets read as if it were current.
     * @return array<string,array{product:int,cycle:int,quantity:int,domain:?string}>
     */
    public static function items(): array
    {
        if (!SessionManager::isConfigured()) {
            return [];
        }

        $stored = Session::get(self::KEY, [], self::SCOPE);

        if (!is_array($stored)) {
            return [];
        }

        $items = [];

        foreach ($stored as $key => $item) {
            if (!is_array($item)) {
                continue;
            }

            $product = (int) ($item['product'] ?? 0);
            $cycle   = (int) ($item['cycle'] ?? 0);

            if ($product <= 0 || $cycle <= 0) {
                continue;
            }

            $items[(string) $key] = [
                'product'  =>  $product,
                'cycle'    =>  $cycle,
                'quantity' =>  self::clampQuantity((int) ($item['quantity'] ?? 1)),
                'domain'   =>  self::cleanDomain($item['domain'] ?? null),
            ];
        }

        return $items;
    }

    /**
     * How Many Lines Are In The Cart
     * @return int
     */
    public static function count(): int
    {
        return count(self::items());
    }

    /**
     * Whether The Cart Holds Nothing
     * @return bool
     */
    public static function isEmpty(): bool
    {
        return self::items() === [];
    }

    /**
     * The Cart, Priced Against The Database
     *
     * Every line is resolved fresh: the product row, the price row for this
     * currency and cycle, and the cycle's name. A line that cannot be resolved
     * comes back with `ok` false and a `problem` saying which, and with its
     * money at zero so a broken line cannot contribute to a total.
     *
     * The caller decides what to do about a problem line. The cart screen shows
     * it; checkout refuses to proceed. Neither drops it quietly - a line that
     * vanishes between the page and the invoice is how a customer ends up
     * paying for something other than what they chose.
     *
     * @param ?int $currencyId Currency To Price In. Null means the operator's default
     * @return array<int,array<string,mixed>>
     */
    public static function lines(?int $currencyId = null): array
    {
        $currencyId = $currencyId ?: (int) (Currency::default()['currency_id'] ?? 0);

        $cycles = self::cycles();
        $lines  = [];

        foreach (self::items() as $key => $item) {
            $lines[] = self::line($key, $item, $currencyId, $cycles);
        }

        return $lines;
    }

    /**
     * What The Cart Comes To
     *
     * Recurring charges plus setup fees, over the lines that resolved. A line
     * with a problem contributes nothing, which keeps the number honest while
     * checkout is still refusing to accept the cart at all.
     * @param array $lines Lines From lines()
     * @return array{recurring:string,setup:string,total:string}
     */
    public static function total(array $lines): array
    {
        $recurring = '0';
        $setup     = '0';

        foreach ($lines as $line) {
            if (($line['ok'] ?? false) !== true) {
                continue;
            }

            $recurring = Money::add($recurring, (string) $line['subtotal']);
            $setup     = Money::add($setup, (string) $line['setup_total']);
        }

        return [
            'recurring' =>  Money::round($recurring),
            'setup'     =>  Money::round($setup),
            'total'     =>  Money::round(Money::add($recurring, $setup)),
        ];
    }

    /**
     * Whether Every Line Resolved
     * @param array $lines Lines From lines()
     * @return bool
     */
    public static function isOrderable(array $lines): bool
    {
        if ($lines === []) {
            return false;
        }

        foreach ($lines as $line) {
            if (($line['ok'] ?? false) !== true) {
                return false;
            }
        }

        return true;
    }

    ####################################################################################
    /*=================================== WRITING ====================================*/
    ####################################################################################

    /**
     * Put Something In The Cart
     *
     * Adding the same product on the same cycle for the same domain increases
     * the quantity rather than making a second line, which is what somebody who
     * pressed the button twice meant.
     *
     * Nothing here checks that the product is for sale. The caller does, because
     * it is the caller that has a screen to say so on - and lines() checks again
     * at render time anyway, which is the check that actually protects an order.
     *
     * @param int $productId Product ID
     * @param int $cycleId Billing Cycle ID
     * @param int $quantity How Many
     * @param ?string $domain Domain, For Products Ordered Against One
     * @return string The line key
     */
    public static function add(int $productId, int $cycleId, int $quantity = 1, ?string $domain = null): string
    {
        $domain = self::cleanDomain($domain);
        $key    = self::key($productId, $cycleId, $domain);
        $items  = self::items();

        $existing = (int) ($items[$key]['quantity'] ?? 0);

        $items[$key] = [
            'product'  =>  $productId,
            'cycle'    =>  $cycleId,
            'quantity' =>  self::clampQuantity($existing + self::clampQuantity($quantity)),
            'domain'   =>  $domain,
        ];

        self::put($items);

        return $key;
    }

    /**
     * Change How Many Of One Line
     *
     * A quantity of zero removes the line, which is what a customer typing 0
     * into the box meant.
     * @param string $key Line Key
     * @param int $quantity How Many
     * @return bool Whether the line existed
     */
    public static function setQuantity(string $key, int $quantity): bool
    {
        $items = self::items();

        if (!array_key_exists($key, $items)) {
            return false;
        }

        if ($quantity <= 0) {
            unset($items[$key]);
            self::put($items);

            return true;
        }

        $items[$key]['quantity'] = self::clampQuantity($quantity);
        self::put($items);

        return true;
    }

    /**
     * Take One Line Out
     * @param string $key Line Key
     * @return bool Whether the line existed
     */
    public static function remove(string $key): bool
    {
        $items = self::items();

        if (!array_key_exists($key, $items)) {
            return false;
        }

        unset($items[$key]);
        self::put($items);

        return true;
    }

    /**
     * Empty The Cart
     *
     * Called on a completed checkout, and by the customer pressing the button.
     * Purges the namespace rather than writing an empty array, so nothing of the
     * cart is left in the session at all.
     * @return void
     */
    public static function clear(): void
    {
        if (SessionManager::isConfigured()) {
            Session::purge(self::SCOPE);
        }
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Resolve One Stored Line Against The Database
     *
     * @param string $key Line Key
     * @param array $item Stored Line
     * @param int $currencyId Currency To Price In
     * @param array<int,string> $cycles Cycle Names Keyed By Id
     * @return array<string,mixed>
     */
    private static function line(string $key, array $item, int $currencyId, array $cycles): array
    {
        $quantity = (int) $item['quantity'];

        $line = [
            'key'         =>  $key,
            'product_id'  =>  $item['product'],
            'cycle_id'    =>  $item['cycle'],
            'quantity'    =>  $quantity,
            'domain'      =>  $item['domain'],
            'name'        =>  '',
            'cycle'       =>  $cycles[$item['cycle']] ?? '',
            'price'       =>  '0',
            'setup_fee'   =>  '0',
            'subtotal'    =>  '0',
            'setup_total' =>  '0',
            'ok'          =>  false,
            'problem'     =>  null,
        ];

        $product = Product::find($item['product']);

        // Named BEFORE it is judged. A withdrawn product still has a row, and
        // the customer looking at the warning needs to know which line it is
        // about - "one of your three items is unavailable" is not something
        // anybody can act on. Only a product that has been deleted outright
        // has no name to give, and that line says so instead.
        if (is_array($product)) {
            $line['name'] = (string) $product['product_name'];
            $line['slug'] = (string) ($product['product_slug'] ?? '');
        }

        // Same test the public catalogue makes. A product that is no longer
        // `active` is not orderable, and saying so is better than an invoice
        // for something the operator has withdrawn.
        if (!is_array($product)
            || (int) ($product['status_relid'] ?? 0) !== (int) (Product::statusId('active') ?? 0)) {
            $line['problem'] = 'unavailable';

            return $line;
        }

        $price = Product::price($item['product'], $currencyId, $item['cycle']);

        // price() already filters on is_active, so this covers a withdrawn
        // price and a currency the operator does not publish this product in.
        if (!is_array($price)) {
            $line['problem'] = 'no_price';

            return $line;
        }

        $line['price']     = Money::round((string) ($price['price'] ?? '0'));
        $line['setup_fee'] = Money::round((string) ($price['setup_fee'] ?? '0'));

        $line['subtotal']    = Money::round(Money::mul((string) $quantity, $line['price']));
        $line['setup_total'] = Money::round(Money::mul((string) $quantity, $line['setup_fee']));

        $line['ok'] = true;

        return $line;
    }

    /**
     * Billing Cycle Names, Keyed By Id
     *
     * One query for the whole cart rather than one per line, for the same
     * reason the public catalogue does it: a price with no cycle beside it is
     * meaningless, and a cart of six lines would otherwise ask six times for
     * two distinct names.
     * @return array<int,string>
     */
    private static function cycles(): array
    {
        $cycles = [];

        foreach ((new BillingCycleModel())->get() as $row) {
            $cycles[(int) $row['billing_cycle_id']] = (string) $row['billing_cycle_name'];
        }

        return $cycles;
    }

    /**
     * Write The Lines Back
     * @param array $items Lines
     * @return void
     */
    private static function put(array $items): void
    {
        if (!SessionManager::isConfigured()) {
            return;
        }

        // A cap, not a queue: the oldest lines are kept and the excess dropped,
        // so a script hammering /cart/add cannot grow a session row without
        // limit. Twenty lines is far past what an honest order looks like.
        if (count($items) > self::MAX_LINES) {
            $items = array_slice($items, 0, self::MAX_LINES, true);
        }

        Session::set(self::KEY, $items, self::SCOPE);
    }

    /**
     * The Key One Line Is Stored Under
     *
     * Product, cycle and domain together - so the same plan on two different
     * domains is two lines, which is how somebody orders hosting twice, while
     * the same plan added twice is one line with a quantity of two.
     * @param int $productId Product ID
     * @param int $cycleId Billing Cycle ID
     * @param ?string $domain Domain
     * @return string
     */
    private static function key(int $productId, int $cycleId, ?string $domain): string
    {
        return $productId . '-' . $cycleId . '-' . ($domain === null ? '' : md5($domain));
    }

    /**
     * Keep a Quantity Inside Its Bounds
     * @param int $quantity Submitted Quantity
     * @return int
     */
    private static function clampQuantity(int $quantity): int
    {
        if ($quantity < 1) {
            return 1;
        }

        return min($quantity, self::MAX_QUANTITY);
    }

    /**
     * Normalise a Submitted Domain, Or Nothing
     *
     * Stored lowercase and stripped of a scheme and any path, because the same
     * domain typed three ways must land on one cart line rather than three. Not
     * validated beyond shape: whether a domain can be registered is the
     * registrar's answer, and Phase 22.4 is where that question gets asked.
     * @param mixed $domain Submitted Domain
     * @return ?string
     */
    private static function cleanDomain(mixed $domain): ?string
    {
        if (!is_string($domain)) {
            return null;
        }

        $domain = strtolower(trim($domain));

        if ($domain === '') {
            return null;
        }

        $domain = (string) preg_replace('#^[a-z]+://#', '', $domain);
        $domain = explode('/', $domain)[0];
        $domain = trim($domain);

        // Hostname shape only. Anything else is dropped rather than stored,
        // so a line key can never be built out of arbitrary submitted text.
        if (!preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)+$/', $domain)) {
            return null;
        }

        return mb_substr($domain, 0, 190);
    }
}
