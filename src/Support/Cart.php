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
use LBM\Service\Domain;
use LBM\Service\Addon;
use LBM\Service\ConfigOption;
use LBM\Service\Product;
use LBM\Service\ProductType;
use LBM\Service\Promo;
use LBM\Service\Tax;
use LBM\Service\Tld;

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

    /**
     * @var string Session Key Holding The Promotional Code
     *
     * The CODE, and nothing else. Not the discount, not the promo id, not
     * whether it was valid when it was typed - this class stores identifiers
     * and the database answers everything else at the moment it is asked. A
     * code that expires while a cart is open stops applying, and says so.
     */
    public const PROMO = 'promo';

    /** @var int Longest a Code May Be - the column is varchar(100) */
    public const MAX_CODE = 100;

    /** @var int Most Lines One Cart May Hold */
    public const MAX_LINES = 20;

    /** @var int Most Of Any One Line */
    public const MAX_QUANTITY = 99;

    /**
     * @var int Most Addons On One Line
     *
     * MAX_LINES' reasoning. The ids come off a form, they end up in a session
     * row, and nothing else bounds how many a script can post.
     */
    public const MAX_ADDONS = 20;

    /**
     * @var int Most Configurable Answers On One Line
     *
     * MAX_ADDONS' reasoning again. resolve() checks every answer against
     * what the product actually offers, but that happens at render time -
     * this is what stops a posted array of ten thousand reaching the session
     * row in the first place.
     */
    public const MAX_CONFIG = 20;

    /**
     * @var int Longest Domain Term The Cart Will Hold
     *
     * `Tld::TERMS` is the real gate and priceFor() enforces it against the
     * operator own range. This is only here so a posted `years` of 4000 cannot
     * reach a session row at all - the same reasoning as MAX_QUANTITY.
     */
    public const MAX_YEARS = 3;

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
     * @return array<string,array{product:int,cycle:int,quantity:int,domain:?string,addons:int[]}>
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

            // A cart written before Phase 27.1 has no `type` at all, and every
            // line in it is a product. Defaulting rather than discarding is
            // what keeps an open cart working across an upgrade.
            if ((string) ($item['type'] ?? 'product') === 'domain') {
                $domain = self::cleanDomain($item['domain'] ?? null);
                $tld    = (int) ($item['tld'] ?? 0);

                if ($domain === null || $tld <= 0) {
                    continue;
                }

                // The product keys are carried at neutral values rather than
                // left out. Every reader of a stored line predates domains, and
                // a missing key is a warning the error handler turns fatal.
                // Anything that is not the literal `transfer` is a
                // registration, which is what every domain line written
                // before Phase 27.3 was. A line whose intent cannot be read
                // must not become a transfer by default: a transfer needs an
                // auth code the customer has to go and fetch.
                $action = (string) ($item['action'] ?? 'register') === 'transfer'
                    ? 'transfer'
                    : 'register';

                $items[(string) $key] = [
                    'type'     =>  'domain',
                    'action'   =>  $action,
                    'domain'   =>  $domain,
                    'tld'      =>  $tld,

                    // A transfer adds exactly one year at the registry and
                    // the contract has no term to pass, so a stored term of
                    // three on a transfer line is a figure nothing can honour.
                    'years'    =>  $action === 'transfer'
                        ? 1
                        : self::clampYears((int) ($item['years'] ?? 1)),
                    'product'  =>  0,
                    'cycle'    =>  0,
                    'quantity' =>  1,
                    'addons'   =>  [],
                    'config'   =>  [],
                ];

                continue;
            }

            $product = (int) ($item['product'] ?? 0);
            $cycle   = (int) ($item['cycle'] ?? 0);

            if ($product <= 0 || $cycle <= 0) {
                continue;
            }

            $items[(string) $key] = [
                'type'     =>  'product',
                'action'   =>  'register',
                'product'  =>  $product,
                'cycle'    =>  $cycle,
                'quantity' =>  self::clampQuantity((int) ($item['quantity'] ?? 1)),
                'domain'   =>  self::cleanDomain($item['domain'] ?? null),

                // Ids only, and put back through the same normalisation an
                // incoming form goes through - a cart written by an older
                // release has no `addons` at all, and one written by hand could
                // have anything in it.
                'addons'   =>  self::cleanAddons($item['addons'] ?? []),

                // Same treatment, same reason. A cart written before this
                // release has no `config` at all, and one written by hand
                // could have anything in it.
                'config'   =>  self::cleanConfig($item['config'] ?? []),
                'tld'      =>  0,
                'years'    =>  0,
            ];
        }

        return $items;
    }

    /**
     * The Promotional Code On This Cart, If Any
     * @return string Empty when there is none
     */
    public static function promoCode(): string
    {
        if (!SessionManager::isConfigured()) {
            return '';
        }

        $code = Session::get(self::PROMO, '', self::SCOPE);

        return is_string($code) ? mb_substr(trim($code), 0, self::MAX_CODE) : '';
    }

    /**
     * Put a Code On The Cart
     *
     * Nothing here checks that it is real. The caller does, because it is the
     * caller that has a screen to say so on - and lines() asks again at render
     * time, which is the check that protects an order.
     * @param string $code Submitted Code
     * @return void
     */
    public static function setPromo(string $code): void
    {
        if (!SessionManager::isConfigured()) {
            return;
        }

        Session::set(self::PROMO, mb_substr(trim($code), 0, self::MAX_CODE), self::SCOPE);
    }

    /**
     * Take The Code Off Again
     * @return void
     */
    public static function clearPromo(): void
    {
        if (SessionManager::isConfigured()) {
            Session::set(self::PROMO, '', self::SCOPE);
        }
    }

    /**
     * The Code, The Row Behind It, And Why It Cannot Be Used
     *
     * Asked fresh every time the cart is rendered, so a code that ran out or
     * expired between the customer typing it and paying stops applying - and
     * the screen can say which of five things went wrong rather than quietly
     * charging them more than the page said a moment ago.
     * @param int $currencyId Currency To Price In
     * @return array{code:string,promo:?array,refusal:?string}
     */
    public static function promoState(int $currencyId): array
    {
        $code = self::promoCode();

        if ($code === '') {
            return ['code' => '', 'promo' => null, 'refusal' => null];
        }

        $promo = Promo::byCode($code);
        $refusal = Promo::refusal($promo, $currencyId);

        return [
            // The code as the OPERATOR wrote it once it has matched, and only
            // what the customer typed while it has not. A cart echoing back
            // "welcome20" against a poster reading "WELCOME20" is somebody
            // wondering whether it took.
            'code'    =>  $refusal === null && is_array($promo)
                ? (string) $promo['promo_code']
                : $code,
            'promo'   =>  $refusal === null ? $promo : null,
            'refusal' =>  $refusal,
        ];
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
     * Tax needs a client, because the rate depends on where they are. Anonymous
     * carts price at no tax and say so on the screen rather than showing a
     * figure nobody can stand behind - a visitor with no country has no rate,
     * and inventing the operator's own would be wrong for every customer abroad.
     *
     * @param ?int $currencyId Currency To Price In. Null means the operator's default
     * @param ?array $client Signed-In Client, When There Is One
     * @return array<int,array<string,mixed>>
     */
    public static function lines(?int $currencyId = null, ?array $client = null): array
    {
        $currencyId = $currencyId ?: (int) (Currency::default()['currency_id'] ?? 0);

        $cycles = self::cycles();
        $lines  = [];

        foreach (self::items() as $key => $item) {
            $lines[] = self::line($key, $item, $currencyId, $cycles, $client);
        }

        return self::discounted($lines, $currencyId);
    }

    /**
     * Apply The Promotional Code, Then Tax What Is Actually Being Charged
     *
     * A fixed code is shared across every line it covers, so the discount
     * cannot be decided one line at a time - which is why this happens here
     * rather than inside line(), and why the tax waits for it.
     *
     * Only lines that RESOLVED are eligible. A line with a problem contributes
     * nothing to the total, so discounting it would quietly spend part of a
     * limited code on something the customer cannot buy.
     * @param array $lines Lines From line()
     * @param int $currencyId Currency To Price In
     * @return array
     */
    private static function discounted(array $lines, int $currencyId): array
    {
        $state = self::promoState($currencyId);
        $discounts = [];

        if (is_array($state['promo'])) {
            $eligible = [];

            foreach ($lines as $i => $line) {
                if (($line['ok'] ?? false) !== true) {
                    continue;
                }

                $eligible[$i] = [
                    'type'    =>  (string) $line['type'],
                    'product' =>  (int) ($line['product_id'] ?? 0),

                    // The setup fee is in this, because it is money the
                    // customer is being asked for today and a code that took
                    // nothing off it would be a discount the arithmetic on the
                    // page did not support.
                    'amount'  =>  Money::add($line['subtotal'], $line['setup_total']),
                ];
            }

            $discounts = Promo::discountFor($state['promo'], $eligible);
        }

        foreach ($lines as $i => $line) {
            if (($line['ok'] ?? false) !== true) {
                continue;
            }

            $lines[$i]['discount'] = Money::round((string) ($discounts[$i] ?? '0'));

            $gross = Money::sub(
                Money::add($line['subtotal'], $line['setup_total']),
                $lines[$i]['discount']
            );

            $lines[$i]['tax'] = self::taxOn($gross, (string) $line['tax_rate']);
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
     * @return array{recurring:string,setup:string,discount:string,total:string}
     */
    public static function total(array $lines): array
    {
        $recurring = '0';
        $setup     = '0';
        $tax       = '0';
        $discount  = '0';

        foreach ($lines as $line) {
            if (($line['ok'] ?? false) !== true) {
                continue;
            }

            $recurring = Money::add($recurring, (string) $line['subtotal']);
            $setup     = Money::add($setup, (string) $line['setup_total']);
            $tax       = Money::add($tax, (string) ($line['tax'] ?? '0'));
            $discount  = Money::add($discount, (string) ($line['discount'] ?? '0'));
        }

        // The discount comes off BEFORE tax, which is already true of the tax
        // figure above - discounted() worked each line's tax out on what is
        // actually being charged for it. Taking it off again here would be the
        // same money twice.
        $quoted    = Money::sub(Money::add($recurring, $setup), $discount);
        $inclusive = Tax::inclusive();

        // `total` is what the customer pays, and it means that whichever way
        // prices are quoted: under inclusive pricing the tax is already inside
        // the line figures, so adding it on would charge it twice.
        //
        // `inclusive` is here because the SENTENCE has to change, not just the
        // arithmetic. "Tax 16.67" above "Due today 100.00" does not add up on
        // the page, and a customer who cannot make the numbers agree does not
        // finish checking out.
        return [
            'recurring' =>  Money::round($recurring),
            'setup'     =>  Money::round($setup),
            'discount'  =>  Money::round($discount),
            'tax'       =>  Money::round($tax),
            'inclusive' =>  $inclusive,
            'total'     =>  Money::round($inclusive ? $quoted : Money::add($quoted, $tax)),
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
     * The same plan with different extras on it is a DIFFERENT line, which is
     * why the addons are part of the key. Merging them would fold one set of
     * choices into another, and the only sign would be an invoice that does not
     * match what the customer picked.
     *
     * @param int $productId Product ID
     * @param int $cycleId Billing Cycle ID
     * @param int $quantity How Many
     * @param ?string $domain Domain, For Products Ordered Against One
     * @param int[] $addons Addon Ids Chosen With It
     * @param array $config Configurable Answers, Keyed By Option ID
     * @return string The line key
     */
    public static function add(
        int $productId,
        int $cycleId,
        int $quantity = 1,
        ?string $domain = null,
        array $addons = [],
        array $config = []
    ): string {
        $domain = self::cleanDomain($domain);
        $addons = self::cleanAddons($addons);
        $config = self::cleanConfig($config);
        $key    = self::key($productId, $cycleId, $domain, $addons, $config);
        $items  = self::items();

        $existing = (int) ($items[$key]['quantity'] ?? 0);

        $items[$key] = [
            'type'     =>  'product',
            'product'  =>  $productId,
            'cycle'    =>  $cycleId,
            'quantity' =>  self::clampQuantity($existing + self::clampQuantity($quantity)),
            'domain'   =>  $domain,
            'addons'   =>  $addons,
            'config'   =>  $config,
        ];

        self::put($items);

        return $key;
    }

    /**
     * Put a Domain Registration In The Cart
     *
     * KEYED ON THE NAME ALONE, and not on the term with it. A domain can be
     * registered exactly once, so the same name at one year and at two years is
     * not two lines a customer could want - it is somebody changing their mind
     * about the term, and the second choice replaces the first. That is the
     * opposite of a product line, where the same plan on two cycles is two
     * genuine purchases, and the difference is worth the separate key.
     *
     * Nothing here checks that the ending is sold, that the term is offered or
     * that the name is still free. lines() checks all three at render time,
     * which is the check that actually protects an order - and the caller
     * checks too, because it is the caller that has a screen to say so on.
     *
     * @param string $domain The Name, Including Its Ending
     * @param int $tldId The TLD Row It Was Priced Against
     * @param int $years Term
     * @return ?string The line key, or null when the name is not a domain
     */
    public static function addDomain(
        string $domain,
        int $tldId,
        int $years = 1,
        string $action = 'register'
    ): ?string {
        $domain = self::cleanDomain($domain);

        if ($domain === null || $tldId <= 0) {
            return null;
        }

        $action = $action === 'transfer' ? 'transfer' : 'register';

        // STILL KEYED ON THE NAME ALONE, and the action is deliberately not
        // part of it. A name can be registered or transferred, never both, so
        // somebody who searched to register and then chose to transfer has
        // changed their mind rather than added a second thing - and two lines
        // for one name would fail at checkout on a UNIQUE column with nothing
        // on the page explaining why.
        $key   = self::domainKey($domain);
        $items = self::items();

        $items[$key] = [
            'type'   =>  'domain',
            'action' =>  $action,
            'domain' =>  $domain,
            'tld'    =>  $tldId,
            // No transfer special-case here. items() normalises every line
            // on the way back OUT and forces a transfer to one year there,
            // which is the read every caller goes through - so a clamp on the
            // way IN as well was provably dead: sabotaging it changed nothing.
            'years'  =>  self::clampYears($years),
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
     * @param ?array $client Signed-In Client, When There Is One
     * @return array<string,mixed>
     */
    private static function line(
        string $key,
        array $item,
        int $currencyId,
        array $cycles,
        ?array $client = null
    ): array {
        if ((string) ($item['type'] ?? 'product') === 'domain') {
            return self::domainLine($key, $item, $currencyId, $client);
        }

        $quantity = (int) $item['quantity'];

        $line = [
            'key'         =>  $key,
            'type'        =>  'product',
            'product_id'  =>  $item['product'],
            'cycle_id'    =>  $item['cycle'],
            'action'      =>  'register',
            'quantity'    =>  $quantity,
            'domain'      =>  $item['domain'],
            'name'        =>  '',
            'cycle'       =>  $cycles[$item['cycle']] ?? '',
            'discount'     =>  '0',
            'price'        =>  '0',
            'setup_fee'    =>  '0',
            'config'       =>  [],
            'config_price' =>  '0',
            'config_setup' =>  '0',
            'unit_price'   =>  '0',
            'unit_setup'   =>  '0',
            'subtotal'     =>  '0',
            'setup_total'  =>  '0',
            'tax_rate'    =>  '0',
            'tax'         =>  '0',
            'addons'      =>  [],
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

        /*
         * THE DOMAIN, IF THIS KIND OF PRODUCT NEEDS ONE.
         *
         * Checked HERE as well as at the door, and this is the copy that
         * protects an order: a cart sits open for hours, and an operator can
         * switch `requires_domain` on for a type - or move a product to a
         * type that has it on - while one does.
         *
         * A problem, not a silent drop. 22.2 found a checkout that quietly
         * dropped an unorderable line, which invoices the customer for part
         * of what they chose and refuses them the rest.
         */
        if (ProductType::productNeedsDomain($item['product']) && ($item['domain'] ?? null) === null) {
            $line['problem'] = 'needs_domain';

            return $line;
        }

        $line['price']     = Money::round((string) ($price['price'] ?? '0'));
        $line['setup_fee'] = Money::round((string) ($price['setup_fee'] ?? '0'));

        // The configuration is priced HERE, out of the database, like every
        // other figure on this line. A choice the operator has withdrawn, or
        // never priced on this cycle, makes the LINE a problem - it does not
        // quietly disappear and it does not become free. Same rule as the
        // addons below, and 22.2's reason for it.
        $config = ConfigOption::resolve(
            $item['config'],
            (int) $product['pid'],
            $currencyId,
            $item['cycle']
        );

        if ($config === null) {
            $line['problem'] = 'config_unavailable';

            return $line;
        }

        $line['config']       = $config;
        $line['config_price'] = ConfigOption::priceOf($config);
        $line['config_setup'] = ConfigOption::setupOf($config);

        // WHAT ONE OF THIS LINE COSTS, CONFIGURED. The order line carries
        // this rather than the catalogue price, and that is what makes the
        // chosen sizes reach client_services.amount and renew with it. A
        // configurable option is part of the plan's price, not a line of its
        // own - see Action\ConfigOption for why that differs from an addon.
        $line['unit_price'] = Money::round(Money::add($line['price'], $line['config_price']));
        $line['unit_setup'] = Money::round(Money::add($line['setup_fee'], $line['config_setup']));

        $line['subtotal']    = Money::round(Money::mul((string) $quantity, $line['unit_price']));
        $line['setup_total'] = Money::round(Money::mul((string) $quantity, $line['unit_setup']));

        // An extra the product no longer offers, or one the operator has never
        // priced on this cycle, makes the LINE a problem - it does not quietly
        // disappear. 22.2 learned that the hard way: a checkout that drops an
        // unorderable part of a line shows the customer a refusal and invoices
        // them anyway for the rest of what they chose.
        $addons = self::addonLines($item['addons'], $product, $currencyId, $item['cycle'], $quantity, $cycles);

        if ($addons === null) {
            $line['problem'] = 'addon_unavailable';

            return $line;
        }

        $line['addons'] = $addons;

        foreach ($addons as $addon) {
            // A one-off extra belongs in the same bucket as a setup fee: due
            // today, and not again. Folding it into the recurring figure would
            // quote a monthly price that is wrong from the second month on.
            if ($addon['pricing_model'] === 'one_time') {
                $line['setup_total'] = Money::add($line['setup_total'], $addon['total']);
                continue;
            }

            $line['subtotal'] = Money::add($line['subtotal'], $addon['total']);
        }

        $line['subtotal']    = Money::round($line['subtotal']);
        $line['setup_total'] = Money::round($line['setup_total']);

        // The same question the invoice will ask, asked with the same two rows.
        // The cart and the invoice have to reach the same rate or the customer
        // agrees to one number and is billed another - which is the whole
        // reason this is computed here rather than left to checkout.
        // The RATE is settled here; the AMOUNT is not, because a promotional
        // code has not been applied yet and a discount comes off before tax.
        // discounted() finishes the sum - see Invoice's docblock, where
        // itemTotal() is quantity * unit_price - discount and the tax is
        // worked out on that.
        $line['tax_rate'] = $client === null ? '0' : Tax::rateFor($client, $product);

        $line['ok'] = true;

        return $line;
    }

    /**
     * Resolve One Stored Domain Line Against The Database
     *
     * Three things can have changed since the name went in the cart, and they
     * are three different messages: the ending stopped being sold, the term or
     * the currency stopped being priced, and - the one that matters - somebody
     * else registered the name.
     *
     * THAT LAST CHECK IS WHY THIS READS `domains` AT ALL. `domains.domain` is
     * UNIQUE across the install, so a name taken between filling the cart and
     * paying cannot be recorded at checkout. Catching it here, at render time
     * and again at checkout, is what turns "your payment went through and you
     * cannot have the name" into "this one has gone, choose another".
     *
     * A DOMAIN IS A RECURRING LINE, not a setup fee. The whole term is charged
     * once up front, which looks one-off - but it renews, and putting it in the
     * one-off bucket would quote a renewal of zero.
     *
     * @param string $key Line Key
     * @param array $item Stored Line
     * @param int $currencyId Currency To Price In
     * @param ?array $client Signed-In Client, When There Is One
     * @return array<string,mixed>
     */
    private static function domainLine(string $key, array $item, int $currencyId, ?array $client): array
    {
        $name   = (string) $item['domain'];
        $years  = (int) $item['years'];
        $action = (string) ($item['action'] ?? 'register');

        $line = [
            'key'         =>  $key,
            'type'        =>  'domain',
            'action'      =>  $action,
            'product_id'  =>  0,
            'cycle_id'    =>  0,
            'quantity'    =>  1,
            'domain'      =>  $name,
            'name'        =>  $name,
            'years'       =>  $years,
            'tld_id'      =>  (int) $item['tld'],
            'tld'         =>  '',
            'cycle'       =>  '',
            'discount'     =>  '0',
            'price'        =>  '0',
            'setup_fee'    =>  '0',
            'config'       =>  [],
            'config_price' =>  '0',
            'config_setup' =>  '0',
            'unit_price'   =>  '0',
            'unit_setup'   =>  '0',
            'subtotal'     =>  '0',
            'setup_total'  =>  '0',
            'tax_rate'    =>  '0',
            'tax'         =>  '0',
            'addons'      =>  [],
            'ok'          =>  false,
            'problem'     =>  null,
        ];

        $tld = Tld::find((int) $item['tld']);

        if (!is_array($tld)) {
            $line['problem'] = 'unavailable';

            return $line;
        }

        $line['tld'] = (string) $tld['tld'];

        // The row is still there, but does it still claim this name? An
        // operator who edits `.co` into `.com` leaves every cart line that was
        // priced against it pointing at an ending its own name does not end
        // with - and match() is the one place that question is answered.
        $matched = Tld::match($name);

        if (!is_array($matched) || (int) $matched['tld_id'] !== (int) $tld['tld_id']) {
            $line['problem'] = 'unavailable';

            return $line;
        }

        // A transfer is priced from its own column and never by term - see
        // Tld::transferPrice(). Both refusals mean the same thing to the
        // customer and share the message.
        $price = $action === 'transfer'
            ? Tld::transferPrice($tld, $currencyId)
            : Tld::priceFor($tld, $currencyId, $years, 'register');

        // Not sold on this term, or not priced in the currency this customer is
        // checking out in. Tld::priceFor() makes the argument for why the
        // second one is a refusal rather than a conversion.
        if ($price === null) {
            $line['problem'] = 'no_price';

            return $line;
        }

        if (Domain::byName($name) !== null) {
            $line['problem'] = 'domain_taken';

            return $line;
        }

        $line['cycle']      = (string) (Tld::cycleForYears($years) ?? '');
        $line['price']      = $price;
        $line['unit_price'] = $price;
        $line['subtotal']   = $price;

        // The rate only - discounted() works out the amount once any promo has
        // come off. See line() above.
        $line['tax_rate'] = $client === null ? '0' : Tax::rateFor($client, null);

        $line['ok'] = true;

        return $line;
    }

    /**
     * Price The Extras Chosen With One Line
     *
     * Null - not an empty list - when any of them cannot be sold, so the caller
     * can mark the whole line rather than deciding for the customer which of
     * their choices to keep.
     *
     * The rate is deliberately NOT asked per addon. An extra takes the tax rate
     * of the plan it hangs off, so one cart line carries one rate; addons have
     * no rate column of their own to say otherwise, and a backup service on a
     * zero-rated plan being taxed differently from the plan would be a surprise
     * nobody asked for.
     * @param int[] $ids Chosen Addon Ids
     * @param array $product Product Row
     * @param int $currencyId Currency ID
     * @param int $cycleId The Line's Billing Cycle
     * @param int $quantity How Many Of The Line
     * @param array<int,string> $cycles Cycle Names Keyed By Id
     * @return ?array<int,array<string,mixed>>
     */
    private static function addonLines(
        array $ids,
        array $product,
        int $currencyId,
        int $cycleId,
        int $quantity,
        array $cycles
    ): ?array {
        if ($ids === []) {
            return [];
        }

        $offered = [];

        foreach (Addon::forProduct((int) $product['pid']) as $row) {
            $offered[(int) $row['addon_id']] = $row;
        }

        $oneTime = (int) (array_search('one_time', $cycles, true) ?: 0);
        $lines = [];

        foreach ($ids as $id) {
            $addon = $offered[$id] ?? null;

            // Not mapped to this product, or withdrawn since it was chosen.
            if ($addon === null) {
                return null;
            }

            $model = (string) ($addon['pricing_model'] ?? 'recurring');
            $on = $model === 'one_time' ? $oneTime : $cycleId;

            $price = $on > 0 ? Addon::price($id, $currencyId, $on) : null;

            // No row means not offered on this cycle. Zero would mean free, and
            // those are different answers - Product::price() has the same rule.
            if (!is_array($price)) {
                return null;
            }

            $each = Money::round((string) ($price['addon_price'] ?? '0'));

            $lines[] = [
                'id'            =>  $id,
                'name'          =>  (string) ($addon['addon_name'] ?? ''),
                'pricing_model' =>  $model,
                'price'         =>  $each,
                'total'         =>  Money::round(Money::mul((string) $quantity, $each)),
            ];
        }

        return $lines;
    }

    /**
     * The Tax On One Line's Money
     *
     * Inclusive and exclusive are not a display choice, they are opposite
     * arithmetic on the same rate: inclusive tax is the part already inside the
     * figure, exclusive tax goes on top of it. Doing one where the other was
     * meant is a silent over- or under-charge on every line in the shop.
     * @param string $gross The Line's Money, As Quoted
     * @param string $rate Percentage
     * @return string Decimal string
     */
    private static function taxOn(string $gross, string $rate): string
    {
        if (Money::isZero($rate) || Money::isZero($gross)) {
            return '0';
        }

        return Money::round(
            Tax::inclusive()
                ? Money::sub($gross, Tax::netOf($gross, $rate))
                : Tax::amountOn($gross, $rate)
        );
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
     * Product, cycle, domain and addons together - so the same plan on two
     * different domains is two lines, which is how somebody orders hosting
     * twice, while the same plan added twice is one line with a quantity of two.
     * @param int $productId Product ID
     * @param int $cycleId Billing Cycle ID
     * @param ?string $domain Domain
     * @param int[] $addons Addon Ids, Already Normalised
     * @param array $config Configurable Answers, Already Normalised
     * @return string
     */
    private static function key(
        int $productId,
        int $cycleId,
        ?string $domain,
        array $addons = [],
        array $config = []
    ): string {
        return $productId . '-' . $cycleId
            . '-' . ($domain === null ? '' : md5($domain))
            . '-' . ($addons === [] ? '' : md5(implode(',', $addons)))
            . '-' . ($config === [] ? '' : md5(json_encode($config)));
    }

    /**
     * Normalise Submitted Addon Ids
     *
     * Sorted and de-duplicated, because the KEY is built from them: the same two
     * extras picked in the other order have to land on the same line, and an id
     * posted twice must not make a third.
     * @param mixed $addons Submitted Addon Ids
     * @return int[]
     */
    private static function cleanAddons(mixed $addons): array
    {
        if (!is_array($addons)) {
            return [];
        }

        $ids = [];

        foreach ($addons as $id) {
            $id = (int) $id;

            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        $ids = array_values($ids);
        sort($ids);

        return array_slice($ids, 0, self::MAX_ADDONS);
    }

    /**
     * Normalise Submitted Configurable Answers
     *
     * CANONICAL, because the line key is built from a json_encode of this.
     * The same answers given in the other order have to land on the same
     * line, or somebody who opens a dropdown and puts it back where it was
     * ends up with two lines for one plan. Hence the ksort and the sort.
     *
     * Scalars are kept as STRINGS and multi-answers as sorted int lists.
     * Nothing here decides whether an answer is offered, priced, or even
     * numeric - ConfigOption::resolve() asks the database all of that at
     * render time. This only bounds what may reach a session row at all.
     * @param mixed $config Submitted Answers
     * @return array<int,string|int[]>
     */
    private static function cleanConfig(mixed $config): array
    {
        if (!is_array($config)) {
            return [];
        }

        $clean = [];

        foreach ($config as $optionId => $answer) {
            $optionId = (int) $optionId;

            if ($optionId <= 0) {
                continue;
            }

            if (is_array($answer)) {
                $ids = [];

                foreach ($answer as $id) {
                    $id = (int) $id;

                    if ($id > 0) {
                        $ids[$id] = $id;
                    }
                }

                $ids = array_values($ids);
                sort($ids);

                if ($ids !== []) {
                    $clean[$optionId] = array_slice($ids, 0, self::MAX_CONFIG);
                }

                continue;
            }

            $value = trim((string) $answer);

            // An empty answer is stored as nothing at all rather than as an
            // empty string. A required option left blank has to be REFUSED by
            // resolve(), and it can only tell the two apart if a blank never
            // reaches it looking like an answer.
            if ($value !== '') {
                $clean[$optionId] = mb_substr($value, 0, ConfigOption::maxText());
            }
        }

        ksort($clean);

        return array_slice($clean, 0, self::MAX_CONFIG, true);
    }

    /**
     * Keep a Domain Term Inside What a Domain Row Can Record
     * @param int $years Submitted Term
     * @return int
     */
    private static function clampYears(int $years): int
    {
        if ($years < 1) {
            return 1;
        }

        return min($years, self::MAX_YEARS);
    }

    /**
     * The Key One Domain Line Is Stored Under
     *
     * The name and nothing else - see addDomain(). Prefixed so a domain line
     * can never collide with a product line, whose keys are digits and dashes.
     * @param string $domain Normalised Domain
     * @return string
     */
    private static function domainKey(string $domain): string
    {
        return 'd-' . md5($domain);
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
    /**
     * The Public Name For The Same Rules
     *
     * `CartController::add()` has to tell "they left the domain blank" from
     * "they typed something that is not a domain", and both arrive as null
     * once cleanDomain() has had them. A second copy of the rules in the
     * controller would be two answers to one question, and the first person to
     * change one of them would not know about the other.
     * @param mixed $domain Submitted Value
     * @return ?string Null when it is not a domain name
     */
    public static function cleanDomainInput(mixed $domain): ?string
    {
        return self::cleanDomain($domain);
    }

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
