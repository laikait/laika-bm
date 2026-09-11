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

namespace LBM\Controller\Front;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Throwable;
use RuntimeException;
use Laika\Service\Request;
use LBM\Service\Addon;
use LBM\Service\ConfigOption;
use LBM\Service\Domain;
use LBM\Service\Currency;
use LBM\Service\Gateway;
use LBM\Service\Invoice;
use LBM\Service\Order;
use LBM\Service\Tax;
use LBM\Service\Product;
use LBM\Service\Promo;
use LBM\Service\ProductType;
use LBM\Service\Tld;
use LBM\Support\Cart;

/**
 * Cart and checkout - how somebody buys something without an operator typing it
 * in for them.
 *
 * Before this phase the whole catalogue led to /panel/register: an operator had
 * to hand-create every order. The journey now runs the whole way through -
 * catalogue, cart, sign in, order, invoice, pay - and the last step is the
 * gateway picker Phase 22.1 built.
 *
 * TWO THINGS DECIDE THE SHAPE OF THIS FILE.
 *
 * 1. THE CART IS NOT THE ORDER. It is session state belonging to a browser, and
 *    everything it says about money is recomputed from the database on every
 *    read - see LBM\Support\Cart. Checkout does not take the cart's word for
 *    anything either: it calls lines() again and prices the order from that.
 *    The browser chooses WHAT is ordered; the database decides what it costs.
 *
 * 2. CHECKOUT NEEDS AN ACCOUNT, AND THAT IS NOT A DETOUR. An order has a client
 *    on it, so there is no anonymous version of this. A visitor pressing
 *    checkout is sent to sign in or register, and the cart survives because it
 *    is in the session - AuthController brings them back here afterwards rather
 *    than to the dashboard.
 *
 * Nothing here trusts a posted price, a posted total or a posted client. The
 * product and cycle come from the form because they are choices; every figure
 * is read.
 */
class CartController extends FrontController
{
    /**
     * Which Top-Nav Item Is Current
     * @return string
     */
    protected function nav(): string
    {
        return 'cart';
    }

    ####################################################################################
    /*===================================== CART =====================================*/
    ####################################################################################

    /**
     * The Cart
     *
     * Priced fresh on every render. A line the operator has withdrawn since it
     * went in shows as a problem rather than disappearing - a cart that quietly
     * loses a line is how somebody pays for two things having chosen three.
     * @return string
     */
    public function index(): string
    {
        $currency = $this->currency();

        // Tax depends on where the customer is, so an anonymous cart cannot
        // show a rate. The screen says the tax is worked out at checkout rather
        // than quoting a number that would be wrong for anybody abroad.
        $client = current_client();
        $lines  = Cart::lines((int) ($currency['currency_id'] ?? 0), $client);

        return $this->screen('cart', local('cart'), [
            'lines'      =>  $lines,
            'totals'     =>  Cart::total($lines),
            'currency'   =>  $currency,
            'orderable'  =>  Cart::isOrderable($lines),
            'signed_in'  =>  is_client(),
            'taxed'      =>  $client !== null && Tax::configured(),
            'gateways'   =>  Gateway::payable(),

            // The code, and why it is not applying if it is not. Asked here
            // rather than remembered from when it was typed, because a code
            // can run out or expire while a cart sits open - and a customer
            // shown a discount that has quietly stopped applying is one who
            // finds out at the invoice.
            'promo'      =>  Cart::promoState((int) ($currency['currency_id'] ?? 0)),
        ]);
    }

    /**
     * Put a Product In The Cart
     *
     * The product arrives as a SLUG and the cycle as an id, and both are looked
     * up before anything is stored: a slug that is not a live product, or a
     * cycle the operator does not publish this product on, is refused here
     * rather than becoming a cart line that can never be priced.
     * @return ?string
     */
    public function add(): ?string
    {
        $input = Request::inputs();

        $slug  = trim((string) ($input['product'] ?? ''));
        $cycle = (int) ($input['cycle'] ?? 0);

        $product = $slug === '' ? null : Product::findBySlug($slug);

        // Same answer for "no such product" and "that product is not for sale",
        // matching the catalogue: a visitor who can tell them apart can find
        // hidden plans by trying slugs.
        if (!is_array($product) || !$this->sellable($product)) {
            return $this->done('front.cart', local('cart_product_gone'), false);
        }

        $currency = $this->currency();

        // The price is not stored, but it must EXIST - otherwise the line goes
        // in and the cart shows a problem the visitor cannot act on, having
        // just been told the thing was added.
        if (Product::price((int) $product['pid'], (int) ($currency['currency_id'] ?? 0), $cycle) === null) {
            return $this->done('front.cart', local('cart_no_price'), false);
        }

        // A DOMAIN, IF THIS KIND OF PRODUCT NEEDS ONE. Same door-and-render
        // pair as everything else here: Cart::line() checks again and is what
        // protects the order, and this is what tells the visitor which field
        // to fill in while the form is still in front of them.
        //
        // cleanDomain() is what decides whether what they typed is a domain at
        // all - it is called through Cart::add() anyway, so asking it here is
        // the only way to tell "they left it blank" from "they typed a
        // sentence", both of which arrive as null further in.
        if (ProductType::productNeedsDomain((int) $product['pid'])
            && Cart::cleanDomainInput($input['domain'] ?? null) === null) {
            return $this->done('front.cart', local('cart_needs_domain'), false);
        }

        // Checked HERE as well as at render time, for the same reason the price
        // is: an id that is not one of this product's extras would go in, the
        // cart would refuse the whole line, and the visitor would have just been
        // told it was added. Refusing at the door names the problem while they
        // are still looking at the form.
        $chosen = $this->chosenAddons($input, (int) $product['pid']);

        if ($chosen === null) {
            return $this->done('front.cart', local('cart_addon_gone'), false);
        }

        // And the configuration, at the door, for the same reason again -
        // with one more on top of it: a REQUIRED option left blank is not a
        // tampered form, it is somebody who missed a field, and telling them
        // so while the form is still in front of them is the whole point.
        $config = $this->chosenConfig($input, $product, $cycle);

        if ($config === null) {
            return $this->done('front.cart', local('cart_config_gone'), false);
        }

        Cart::add(
            (int) $product['pid'],
            $cycle,
            (int) ($input['quantity'] ?? 1),
            isset($input['domain']) ? (string) $input['domain'] : null,
            $chosen,
            $config
        );

        return $this->done('front.cart', local('cart_added'));
    }

    /**
     * Put a Domain Registration In The Cart
     *
     * Every one of these checks is repeated by lines() at render time, and
     * that is deliberate rather than wasteful - the render check is what
     * protects an order, and this one is what lets the visitor be told which
     * of the four things went wrong while they are still looking at the box
     * they typed the name into.
     *
     * The name is matched against the price list rather than trusted: the
     * TLD is what decides the price, the term range and the registrar, and
     * a TLD the operator does not sell has no answer to any of the three.
     * @return ?string
     */
    public function domain(): ?string
    {
        $input = Request::inputs();

        $name  = trim((string) ($input['domain'] ?? ''));
        $years = (int) ($input['years'] ?? 1);

        $tld = $name === '' ? null : Tld::match($name);

        if (!is_array($tld)) {
            return $this->done('front.cart', local('cart_tld_gone'), false);
        }

        $currency = $this->currency();

        // The term and the currency are told APART, because they are different
        // things for the visitor to do next: a term is a dropdown they can
        // change, and a currency is not. `cart_no_price` is not reused here -
        // it reads "that billing cycle is not offered for this service", which
        // is a sentence about a product and nonsense in front of a domain.
        if (Tld::priceFor($tld, (int) ($currency['currency_id'] ?? 0), $years, 'register') === null) {
            $offered = in_array($years, Tld::termsFor($tld), true);

            return $this->done(
                'front.cart',
                local($offered ? 'cart_domain_currency' : 'cart_domain_term'),
                false
            );
        }

        $normalised = (string) Tld::normaliseDomain($name);

        // Checked before it goes in, and again before it is ordered. `domains`
        // is UNIQUE on the name, so a taken one cannot be recorded at all - and
        // finding that out after the payment is the failure this phase exists
        // to avoid.
        if (Domain::byName($normalised) !== null) {
            return $this->done('front.cart', local('cart_domain_taken'), false);
        }

        if (Cart::addDomain($name, (int) $tld['tld_id'], $years) === null) {
            return $this->done('front.cart', local('cart_tld_gone'), false);
        }

        return $this->done('front.cart', local('cart_added'));
    }

    /**
     * Put a Domain Transfer In The Cart
     *
     * The MIRROR of domain() above, and every check in it is the same check
     * read the other way round:
     *
     *   - the TLD has to be sold, and priced for TRANSFER in this currency;
     *   - the name must not already be one this install holds, because a
     *     transfer brings in a name from somewhere else and `domains` is
     *     UNIQUE - a customer transferring a name they already have here would
     *     pay and get nothing;
     *   - and there is no term at all, because a transfer adds one year and
     *     the registrar contract has nowhere to put a longer one.
     *
     * What is NOT checked here is whether the name is registered elsewhere.
     * That is a lookup over somebody else's network, it is bounded and done on
     * the search screen, and a registry that cannot be reached must not stop an
     * order the registrar would have accepted.
     * @return ?string
     */
    public function transfer(): ?string
    {
        $input = Request::inputs();

        $name = trim((string) ($input['domain'] ?? ''));
        $tld  = $name === '' ? null : Tld::match($name);

        if (!is_array($tld)) {
            return $this->done('front.cart', local('cart_tld_gone'), false);
        }

        $currency = $this->currency();

        // One message, not two. Unlike a registration there is no term to get
        // wrong, so the only thing left is the currency - and a visitor cannot
        // change that.
        if (Tld::transferPrice($tld, (int) ($currency['currency_id'] ?? 0)) === null) {
            return $this->done('front.cart', local('cart_domain_currency'), false);
        }

        $normalised = (string) Tld::normaliseDomain($name);

        if (Domain::byName($normalised) !== null) {
            return $this->done('front.cart', local('cart_transfer_here'), false);
        }

        if (Cart::addDomain($name, (int) $tld['tld_id'], 1, 'transfer') === null) {
            return $this->done('front.cart', local('cart_tld_gone'), false);
        }

        return $this->done('front.cart', local('cart_added'));
    }

    /**
     * Put a Promotional Code On The Cart, Or Take It Off
     *
     * The code goes in whether or not it is good, and the refusal is shown
     * from the cart screen - because lines() asks the same question again at
     * render time and a code that was fine a minute ago may not be now. Two
     * places deciding whether a code applies is two places that can disagree.
     *
     * A code is REFUSED BY NAME so the customer knows what to do next: a
     * mistyped one is retyped, an expired one is not, and one that is real but
     * priced in another currency is not their problem at all.
     * @return ?string
     */
    public function promo(): ?string
    {
        $input = Request::inputs();

        if (!empty($input['remove'])) {
            Cart::clearPromo();

            return $this->done('front.cart', local('promo_removed'));
        }

        $code = trim((string) ($input['code'] ?? ''));

        if ($code === '') {
            Cart::clearPromo();

            return $this->done('front.cart', local('promo_removed'));
        }

        $currency = $this->currency();
        $refusal = Promo::refusal(
            Promo::byCode($code),
            (int) ($currency['currency_id'] ?? 0)
        );

        if ($refusal !== null) {
            // NOT stored. A code the shop has just refused sitting in the
            // session would show its refusal on every page load afterwards,
            // which reads as the cart being broken rather than the code being
            // wrong.
            Cart::clearPromo();

            return $this->done('front.cart', local('promo_' . $refusal), false);
        }

        Cart::setPromo($code);

        return $this->done('front.cart', local('promo_applied'));
    }

    /**
     * Change a Line's Quantity, Or Drop It
     * @return ?string
     */
    public function update(): ?string
    {
        $input = Request::inputs();

        $key      = (string) ($input['key'] ?? '');
        $quantity = (int) ($input['quantity'] ?? 0);

        if (!Cart::setQuantity($key, $quantity)) {
            return $this->done('front.cart', local('cart_line_gone'), false);
        }

        return $this->done('front.cart', $quantity <= 0 ? local('cart_removed') : local('cart_updated'));
    }

    /**
     * Take a Line Out
     * @return ?string
     */
    public function remove(): ?string
    {
        $key = (string) (Request::inputs()['key'] ?? '');

        if (!Cart::remove($key)) {
            return $this->done('front.cart', local('cart_line_gone'), false);
        }

        return $this->done('front.cart', local('cart_removed'));
    }

    /**
     * Empty The Cart
     * @return ?string
     */
    public function clear(): ?string
    {
        Cart::clear();

        return $this->done('front.cart', local('cart_emptied'));
    }

    ####################################################################################
    /*=================================== CHECKOUT ===================================*/
    ####################################################################################

    /**
     * Turn The Cart Into An Order And An Invoice
     *
     * The whole method is a sequence of refusals followed by one write, and the
     * refusals are the point. Each one names what was wrong: "it failed" is a
     * message a broken CSRF token also produces, and Phase 20.2 already learned
     * what that costs a harness.
     *
     * The order is left PENDING. Raising the invoice is not accepting the
     * order - the money has not arrived, and an order that goes active on the
     * strength of a customer pressing a button is an order provisioned for
     * free. Staff accept it, or Phase 22.4 does when the invoice settles.
     * @return ?string
     */
    public function checkout(): ?string
    {
        $client = current_client();

        // Not a redirect to the login form directly: `front.cart` is where they
        // came from and where the cart is, and AuthController sends them back
        // here once they are in. One message, one place to return to.
        if ($client === null) {
            return $this->done('client.login', local('cart_sign_in_first'), false);
        }

        $currency   = $this->currency();
        $currencyId = (int) ($currency['currency_id'] ?? 0);

        if ($currencyId <= 0) {
            return $this->done('front.cart', local('cart_no_currency'), false);
        }

        // Read again. The cart the visitor was shown was priced when the page
        // was rendered, which may have been an hour ago, and this is the read
        // the order is actually built from.
        $lines = Cart::lines($currencyId, $client);

        if ($lines === []) {
            return $this->done('front.cart', local('cart_empty'), false);
        }

        if (!Cart::isOrderable($lines)) {
            return $this->done('front.cart', local('cart_has_problems'), false);
        }

        try {
            $invoice = $this->place($client, $currencyId, $lines);
        } catch (Throwable $e) {
            return $this->done('front.cart', $e->getMessage(), false);
        }

        Cart::clear();

        // Straight to the invoice, which is where the gateway picker lives.
        return $this->done('client.invoice', local('order_placed'), true, ['invoice' => $invoice]);
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Place The Order And Raise Its Invoice
     *
     * @param array $client Signed-In Client
     * @param int $currencyId Currency ID
     * @param array $lines Priced Cart Lines
     * @return string The invoice uid
     * @throws RuntimeException
     */
    private function place(array $client, int $currencyId, array $lines): string
    {
        // The CODE is claimed before the order exists, and the ORDER records
        // which one. claim() is a compare-and-set, so a limited code cannot go
        // over its limit when two people check out in the same second - see
        // Action\Promo. A code that has just run out simply does not apply,
        // and the order is placed at full price rather than refused: the
        // customer wanted the things in the cart.
        $state = Cart::promoState($currencyId);
        $promoId = null;

        if (is_array($state['promo']) && Promo::claim((int) $state['promo']['promo_id'])) {
            $promoId = (int) $state['promo']['promo_id'];
        }

        $orderId = Order::store([
            'client_relid'   =>  (int) $client['cid'],
            'currency_relid' =>  $currencyId,
            'promo_relid'    =>  $promoId,
        ], $this->orderItems($lines));

        // accept() raises the invoice. The second argument keeps the order
        // pending rather than moving it to active - see the method's docblock,
        // and the note above checkout().
        $invoiceId = Order::accept($orderId, false);

        $invoice = Invoice::find($invoiceId);

        if (!is_array($invoice)) {
            throw new RuntimeException(local('order_no_invoice'));
        }

        // The code is NOT cleared here. checkout() calls Cart::clear() the
        // moment this returns, and that purges the whole CART session scope -
        // the lines and the code together. A clearPromo() call here as well was
        // provably dead: sabotaging it away changed nothing at all, which is the
        // whole of the evidence. What must stay true is that a spent code does
        // not follow the customer to their next order, and promowalk asserts
        // exactly that rather than asserting this line exists.

        return (string) $invoice['uid'];
    }

    /**
     * Cart Lines As Order Lines
     *
     * A setup fee becomes its OWN line, on the `one_time` cycle, rather than
     * being folded into the recurring price. Two reasons, and the first is the
     * one that matters:
     *
     *   - The catalogue already SHOWS visitors a setup fee (service.twig prints
     *     it under the price), and until this phase nothing in the product ever
     *     charged one. A checkout that dropped it would be a visible
     *     under-charge on every order an operator configured a fee for.
     *   - Folding it in would put a one-off charge inside a line described as
     *     "Product (monthly)", so the invoice would say the customer is billed
     *     that much every month. Its own line reads as what it is, and keeps
     *     Order::recalculate()'s total equal to the invoice's.
     *
     * @param array $lines Priced Cart Lines
     * @return array<int,array<string,mixed>>
     */
    private function orderItems(array $lines): array
    {
        $items = [];

        foreach ($lines as $line) {
            // A domain is one line and only one: the whole term is charged
            // once, up front, because that is what registering for two years
            // costs. There is no setup fee to split off and no addon to hang
            // on it, which is why this returns to the top rather than falling
            // through into the product shape below.
            if ((string) ($line['type'] ?? 'product') === 'domain') {
                $items[] = [
                    'type'          =>  'domain',
                    'product_relid' =>  null,
                    'billing_cycle' =>  $line['cycle'],
                    'domain'        =>  $line['domain'],
                    'quantity'      =>  1,
                    'amount'        =>  $line['price'],

                    // What is being done to the name, and the only thing that
                    // tells Registration which kind of `domains` row to write.
                    // Without it a paid transfer becomes an attempt to register
                    // a name somebody else already owns.
                    'domain_action' =>  $line['action'],
                ];

                continue;
            }

            // `unit_price`, NOT `price`. The catalogue figure is the plan on
            // its own; this one has the chosen sizes in it, and it is what
            // Provision copies to client_services.amount and every renewal is
            // billed from. A configurable option is part of the plan's price
            // rather than a line of its own - see Action\ConfigOption. With
            // nothing configured the two are the same number.
            $items[] = [
                'type'          =>  'product',
                'product_relid' =>  $line['product_id'],
                'billing_cycle' =>  $line['cycle'],
                'domain'        =>  $line['domain'],
                'quantity'      =>  $line['quantity'],
                'amount'        =>  $line['unit_price'],

                // Not a column on order_items. Order::insertItem() drops it
                // from the column set and writes it to its own table, inside
                // the same transaction as the line.
                'config'        =>  $line['config'],
            ];

            // An extra is its own order line, carrying BOTH the addon it is and
            // the product it hangs off. The second one is what lets Provision
            // put it on the right service when an order holds two plans: order
            // lines have no parent link, and (product, domain) is the grouping
            // already in place.
            foreach ($line['addons'] as $addon) {
                $items[] = [
                    'type'          =>  'addon',
                    'product_relid' =>  $line['product_id'],
                    'addon_relid'   =>  $addon['id'],
                    'billing_cycle' =>  $addon['pricing_model'] === 'one_time'
                        ? 'one_time'
                        : $line['cycle'],
                    'domain'        =>  $line['domain'],
                    'quantity'      =>  $line['quantity'],
                    'amount'        =>  $addon['price'],
                ];
            }

            // `unit_setup` for `setup_fee`, same reasoning - a choice can carry
            // a one-off charge of its own, and product_config_option_pricing
            // has had a setup_fee column since Phase 0 for it.
            //
            // NO CONFIG ON THIS LINE. It is the same purchase said twice, and
            // Provision would otherwise copy the answers onto the service
            // twice over.
            if ((float) $line['unit_setup'] <= 0) {
                continue;
            }

            $items[] = [
                'type'          =>  'product',
                'product_relid' =>  $line['product_id'],
                'billing_cycle' =>  'one_time',
                'domain'        =>  $line['domain'],
                'quantity'      =>  $line['quantity'],
                'amount'        =>  $line['unit_setup'],
            ];
        }

        return $items;
    }

    /**
     * The Extras Posted With An Add, Checked Against What The Product Offers
     *
     * Null when any of them is not on offer, so the caller refuses the whole add
     * rather than deciding which of somebody's choices to honour.
     * @param array $input Submitted Data
     * @param int $productId Product ID
     * @return ?int[]
     */
    private function chosenAddons(array $input, int $productId): ?array
    {
        $posted = $input['addons'] ?? [];

        if (!is_array($posted) || $posted === []) {
            return [];
        }

        $offered = Addon::mappedIds($productId);
        $active = [];

        foreach (Addon::forProduct($productId) as $row) {
            $active[] = (int) $row['addon_id'];
        }

        $chosen = [];

        foreach ($posted as $id) {
            $id = (int) $id;

            if ($id <= 0) {
                continue;
            }

            if (!in_array($id, $offered, true) || !in_array($id, $active, true)) {
                return null;
            }

            $chosen[] = $id;
        }

        return $chosen;
    }

    /**
     * The Configuration Posted With An Add, Checked Against What Is Offered
     *
     * Null when any answer cannot be honoured, so the caller refuses the
     * whole add rather than deciding which of somebody's choices to keep -
     * 22.2's finding, and the same shape as chosenAddons() beside it.
     *
     * The RESOLVED answers are not what comes back. Cart stores identifiers
     * and nothing else, and resolve() runs again at render time and again at
     * checkout; this call exists so a visitor hears about a missed required
     * field while the form is still in front of them, rather than being told
     * the thing was added and then shown a cart that refuses it.
     * @param array $input Submitted Data
     * @param array $product Product Row
     * @param int $cycleId Billing Cycle ID
     * @return ?array
     */
    private function chosenConfig(array $input, array $product, int $cycleId): ?array
    {
        $posted = $input['config'] ?? [];

        if (!is_array($posted)) {
            $posted = [];
        }

        $currency = $this->currency();

        $resolved = ConfigOption::resolve(
            $posted,
            (int) $product['pid'],
            (int) ($currency['currency_id'] ?? 0),
            $cycleId
        );

        return $resolved === null ? null : $posted;
    }

    /**
     * The Currency To Price In
     *
     * A signed-in client is billed in the currency on their account; a visitor
     * sees the operator's default, because a stranger has no preference and
     * guessing one from an IP address is a quote that has to be walked back.
     *
     * Falls back to the default when the client's currency has been switched
     * off since it was chosen, rather than pricing an empty cart in a currency
     * with no rates behind it.
     * @return ?array
     */
    private function currency(): ?array
    {
        $client = current_client();
        $wanted = (int) ($client['currency_relid'] ?? 0);

        if ($wanted > 0) {
            $currency = Currency::find($wanted);

            if (is_array($currency) && ($currency['is_active'] ?? 'yes') === 'yes') {
                return $currency;
            }
        }

        return Currency::default();
    }

    /**
     * Whether a Product Row May Be Ordered
     *
     * The catalogue's test, repeated rather than shared: `hidden` products are
     * reachable by direct link on purpose, and this is the one place that has
     * to decide whether that extends to buying them. It does not - a hidden
     * product is off the shelf.
     * @param array $product Product Row
     * @return bool
     */
    private function sellable(array $product): bool
    {
        return (int) ($product['status_relid'] ?? 0) === (int) (Product::statusId('active') ?? 0);
    }
}
