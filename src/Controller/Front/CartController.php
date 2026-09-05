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
use LBM\Service\Currency;
use LBM\Service\Gateway;
use LBM\Service\Invoice;
use LBM\Service\Order;
use LBM\Service\Tax;
use LBM\Service\Product;
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

        Cart::add(
            (int) $product['pid'],
            $cycle,
            (int) ($input['quantity'] ?? 1),
            isset($input['domain']) ? (string) $input['domain'] : null
        );

        return $this->done('front.cart', local('cart_added'));
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
        $orderId = Order::store([
            'client_relid'   =>  (int) $client['cid'],
            'currency_relid' =>  $currencyId,
        ], $this->orderItems($lines));

        // accept() raises the invoice. The second argument keeps the order
        // pending rather than moving it to active - see the method's docblock,
        // and the note above checkout().
        $invoiceId = Order::accept($orderId, false);

        $invoice = Invoice::find($invoiceId);

        if (!is_array($invoice)) {
            throw new RuntimeException(local('order_no_invoice'));
        }

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
            $items[] = [
                'type'          =>  'product',
                'product_relid' =>  $line['product_id'],
                'billing_cycle' =>  $line['cycle'],
                'domain'        =>  $line['domain'],
                'quantity'      =>  $line['quantity'],
                'amount'        =>  $line['price'],
            ];

            if ((float) $line['setup_fee'] <= 0) {
                continue;
            }

            $items[] = [
                'type'          =>  'product',
                'product_relid' =>  $line['product_id'],
                'billing_cycle' =>  'one_time',
                'domain'        =>  $line['domain'],
                'quantity'      =>  $line['quantity'],
                'amount'        =>  $line['setup_fee'],
            ];
        }

        return $items;
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
