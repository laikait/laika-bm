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

use Laika\Model\Model;
use LBM\Model\BillingCycleModel;
use LBM\Service\Product;
use LBM\Service\ProductType;
use LBM\Service\Addon;
use LBM\Service\ConfigOption;
use LBM\Service\Currency;

/**
 * The public product catalogue - what is for sale and what it costs.
 *
 * Informational only. Cart and checkout are deliberately out of scope for this
 * phase, so the call to action on every product is /panel/register, which
 * already exists and already creates the account an order would need anyway.
 * Nothing here writes.
 *
 * Prices are shown in the operator's default currency. A visitor has no account
 * and therefore no currency preference, and guessing one from an IP address
 * would be wrong often enough to be worse than not guessing - a price in the
 * wrong currency is a quote the operator has to walk back.
 */
class ServiceController extends FrontController
{
    /**
     * Which Top-Nav Item Is Current
     * @return string
     */
    protected function nav(): string
    {
        return 'services';
    }

    /**
     * Every Product Group
     * @return string
     */
    public function index(): string
    {
        return $this->screen('services', local('services'), [
            'meta_description' =>  local('services_meta', app_name()),
            'groups'           =>  Product::groups(true),
        ]);
    }

    /**
     * One Group And The Products In It
     * @param string $group Group Slug
     * @return string
     */
    public function group(string $group): string
    {
        $group = $this->groupBySlug($group);

        if (!$this->found($group)) {
            return $this->notFound();
        }

        $currency = Currency::default();

        $products = $this->sellable()
            ->where(['group_relid' => (int) $group['group_id']])
            ->order('product_name', 'ASC')
            ->get();

        return $this->screen('service-group', (string) $group['group_name'], [
            'meta_description' =>  local('service_group_meta', (string) $group['group_name']),
            'group'            =>  $group,
            'products'         =>  $this->withPrices($products, $currency),
            'currency'         =>  $currency,
            'cycles'           =>  $this->cycles(),
        ]);
    }

    /**
     * One Product
     * @param string $product Product Slug
     * @return string
     */
    public function show(string $product): string
    {
        $product = Product::findBySlug($product);

        // findBySlug() does not care whether the product is for sale, because
        // the admin panel uses it too. The public site does care - and an
        // unlisted product answers exactly as a missing one, so a hidden plan
        // cannot be confirmed by trying its slug.
        if (!$this->found($product)
            || (int) ($product['status_relid'] ?? 0) !== $this->activeStatusId()) {
            return $this->notFound();
        }

        return $this->screen('service', (string) $product['product_name'], [
            'meta_description' =>  $this->summary($product),
            'product'          =>  $product,

            // Whether this kind of thing is ordered AGAINST a domain. The
            // field is the whole of Phase 33 on this page - everything behind
            // it, from Cart::add()'s $domain parameter to
            // client_services.domain, has been in place and unreachable since
            // Phase 0 because nothing ever posted one.
            'needs_domain'     =>  ProductType::productNeedsDomain((int) $product['pid']),

            'group'            =>  $product['group_relid']
                ? Product::group((int) $product['group_relid'])
                : null,
            'pricing'          =>  Product::pricing((int) $product['pid']),
            'currency'         =>  Currency::default(),
            'cycles'           =>  $this->cycles(),

            // Active only. An extra the operator has switched off is not an
            // offer, and showing it greyed out is a control that does nothing.
            'addons'           =>  $this->addonsFor((int) $product['pid']),

            // The sizes this plan comes in. Same rule as the addons above -
            // active choices only - and the same pricing shape, for the same
            // reason: the cycle is a radio on this form and the page cannot
            // re-price itself when the visitor moves it.
            'configs'          =>  $this->configFor((int) $product['pid']),
        ]);
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * The Extras On Offer With One Product, Priced
     *
     * Each one carries what it costs on EVERY cycle it is sold on, because the
     * cycle is a radio button on the same form and the page has no way to
     * re-price itself when the visitor moves it. Showing one cycle's figure
     * would be right until they picked the other one, and wrong silently.
     *
     * An addon with no price at all is dropped: it cannot be ordered - the cart
     * would refuse the whole line - so offering it is offering a dead end.
     * @param int $productId Product ID
     * @return array<int,array<string,mixed>>
     */
    private function addonsFor(int $productId): array
    {
        $currencyId = (int) (Currency::default()['currency_id'] ?? 0);

        if ($currencyId <= 0) {
            return [];
        }

        $cycles = $this->cycles();
        $out = [];

        foreach (Addon::forProduct($productId) as $addon) {
            $addonId = (int) $addon['addon_id'];
            $prices = [];

            foreach ($cycles as $cycleId => $cycleName) {
                $price = Addon::price($addonId, $currencyId, (int) $cycleId);

                if (is_array($price)) {
                    $prices[(string) $cycleName] = (string) $price['addon_price'];
                }
            }

            if ($prices === []) {
                continue;
            }

            $out[] = [
                'id'            =>  $addonId,
                'name'          =>  (string) $addon['addon_name'],
                'description'   =>  (string) ($addon['description'] ?? ''),
                'pricing_model' =>  (string) ($addon['pricing_model'] ?? 'recurring'),
                'prices'        =>  $prices,
            ];
        }

        return $out;
    }

    /**
     * The Configurable Options On One Product, Priced
     *
     * Every choice carries what it costs on EVERY cycle the operator sells
     * it on, for addonsFor()'s reason - one cycle's figure would be right
     * until the visitor moved the radio, and wrong silently afterwards.
     *
     * ONLY THE CYCLES THIS PRODUCT IS SOLD ON. A choice priced annually on a
     * plan the operator only sells monthly can never be ordered - the cycle
     * it needs is not on this form - so showing it is offering a dead end
     * that refuses the whole line when it is picked.
     *
     * A CHOICE WITH NO PRICE LEFT IS DROPPED, and a non-text field left with
     * no choices goes with it: a dropdown with nothing in it is a question
     * nobody can answer, and a REQUIRED one in that state makes the plan
     * unbuyable. Better found here than at the cart.
     * @param int $productId Product ID
     * @return array<int,array<string,mixed>>
     */
    private function configFor(int $productId): array
    {
        $currencyId = (int) (Currency::default()['currency_id'] ?? 0);

        if ($currencyId <= 0) {
            return [];
        }

        // The cycles the PLAN itself is sold on in this currency, which is
        // exactly the set of radio buttons on the form beside these fields.
        $sold = [];

        foreach (Product::pricing($productId) as $row) {
            if ((int) ($row['currency_relid'] ?? 0) === $currencyId) {
                $sold[(int) $row['billing_cycle_relid']] = true;
            }
        }

        $cycles = array_filter(
            $this->cycles(),
            static fn(int $id): bool => isset($sold[$id]),
            ARRAY_FILTER_USE_KEY
        );

        $out = [];

        foreach (ConfigOption::forProduct($productId) as $group) {
            $choices = [];

            foreach ($group['subs'] as $sub) {
                $subId = (int) $sub['pcos_id'];
                $prices = [];

                foreach ($cycles as $cycleId => $cycleName) {
                    $price = ConfigOption::price($subId, $currencyId, (int) $cycleId);

                    if (is_array($price)) {
                        $prices[(string) $cycleName] = (string) $price['price'];
                    }
                }

                if ($prices === []) {
                    continue;
                }

                $choices[] = [
                    'id'     =>  $subId,
                    'name'   =>  (string) $sub['option_name'],
                    'prices' =>  $prices,
                ];
            }

            if ($group['type'] !== 'text' && $choices === []) {
                continue;
            }

            $out[] = [
                'id'          =>  (int) $group['pco_id'],
                'name'        =>  (string) $group['name'],
                'description' =>  (string) $group['description'],
                'type'        =>  (string) $group['type'],
                'required'    =>  (bool) $group['required'],

                // The real clamp is in resolve(). This is the same number so
                // the browser refuses what the server would have thrown away -
                // read from the constant rather than typed into the template,
                // where it would drift the first time the bound moved.
                'max'         =>  ConfigOption::maxQuantity(),
                'choices'     =>  $choices,
            ];
        }

        return $out;
    }

    /**
     * A Product Query Restricted To What Is For Sale
     *
     * `products` carries no is_active flag - the base class's live() would look
     * for a column that is not there. Sale state is a status row instead:
     * product_statuses seeds `active`, `hidden` and `retired`, and only the
     * first belongs on the public site. `hidden` exists precisely so an
     * operator can keep a product orderable by direct link while taking it off
     * the catalogue, so this must match on `active` alone rather than on
     * "anything but retired".
     * @return Model
     */
    private function sellable(): Model
    {
        return Product::model()->where(['status_relid' => $this->activeStatusId()]);
    }

    /**
     * The Id Of The `active` Product Status
     *
     * Memoised: the group listing asks once and the price loop would otherwise
     * ask per product. Zero when the status table has not been seeded, which
     * matches nothing and so shows an empty catalogue rather than everything.
     * @return int
     */
    private function activeStatusId(): int
    {
        static $id = null;

        return $id ??= (int) (Product::statusId('active') ?? 0);
    }

    /**
     * Billing Cycle Names, Keyed By Id
     *
     * A price row carries a cycle id, and a price with no cycle beside it is
     * meaningless - "$5" could be a month or a year. Fetched once and handed to
     * the view as a map rather than resolved per row, which on a group of
     * fifteen products with four cycles each would be sixty queries for six
     * distinct names.
     * @return array<int,string>
     */
    private function cycles(): array
    {
        static $cycles = null;

        if ($cycles !== null) {
            return $cycles;
        }

        $cycles = [];

        foreach ((new BillingCycleModel())->get() as $row) {
            $cycles[(int) $row['billing_cycle_id']] = (string) $row['billing_cycle_name'];
        }

        return $cycles;
    }

    /**
     * One Group, By Slug
     *
     * Product has no findGroupBySlug(), and adding one to the action for a
     * single caller would widen an interface the admin panel shares. The groups
     * list is short - an operator has a handful of them - so matching in PHP
     * costs nothing and keeps the action as it is.
     * @param string $slug Group Slug
     * @return ?array
     */
    private function groupBySlug(string $slug): ?array
    {
        foreach (Product::groups(true) as $group) {
            if (($group['group_slug'] ?? null) === $slug) {
                return $group;
            }
        }

        return null;
    }

    /**
     * Attach The Cheapest Recurring Price To Each Product
     *
     * A group listing shows "from X" rather than a full price table, so each
     * product needs one number. The lowest active price in the default currency
     * is the honest one to show: quoting anything higher on the listing and
     * lower on the detail page reads as a bait.
     * @param array $products Products
     * @param ?array $currency Default Currency
     * @return array
     */
    private function withPrices(array $products, ?array $currency): array
    {
        $currencyId = (int) ($currency['currency_id'] ?? 0);

        foreach ($products as &$product) {
            $product['from_price'] = null;
            $product['from_cycle'] = null;

            foreach (Product::pricing((int) $product['pid']) as $price) {
                if ((int) ($price['currency_relid'] ?? 0) !== $currencyId) {
                    continue;
                }

                if (($price['is_active'] ?? 'yes') !== 'yes') {
                    continue;
                }

                $amount = (float) ($price['price'] ?? 0);

                if ($product['from_price'] === null || $amount < (float) $product['from_price']) {
                    $product['from_price'] = $price['price'];
                    $product['from_cycle'] = $price['billing_cycle_relid'] ?? null;
                }
            }
        }

        return $products;
    }

    /**
     * A Product's Description, Trimmed For a Meta Tag
     * @param array $product Product Row
     * @return string
     */
    private function summary(array $product): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) ($product['description'] ?? ''))) ?? '');

        return $text !== '' ? mb_substr($text, 0, 160) : (string) $product['product_name'];
    }
}
