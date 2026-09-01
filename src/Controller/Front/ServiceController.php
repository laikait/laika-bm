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
            'group'            =>  $product['group_relid']
                ? Product::group((int) $product['group_relid'])
                : null,
            'pricing'          =>  Product::pricing((int) $product['pid']),
            'currency'         =>  Currency::default(),
            'cycles'           =>  $this->cycles(),
        ]);
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

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
