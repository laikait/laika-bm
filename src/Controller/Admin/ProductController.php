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

namespace LBM\Controller\Admin;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Laika\Model\Model;
use Laika\Service\Request;
use LBM\Model\BillingCycleModel;
use LBM\Service\Activity;
use LBM\Service\Currency;
use LBM\Service\Product;

/**
 * Products, their groups and their prices.
 *
 * Pricing is edited on the product screen rather than on a form of its own,
 * because a price only means something next to the product it belongs to - and
 * one product legitimately has a different price per currency and per billing
 * cycle, which is a grid, not a field.
 */
class ProductController extends AdminController
{
    protected function nav(): string
    {
        return 'products';
    }

    ####################################################################################
    /*=================================== PRODUCTS ===================================*/
    ####################################################################################

    /**
     * The Product List
     * @return string
     */
    public function index(): string
    {
        $page = Product::browse(
            $this->conditions(['status' => 'status_relid', 'group' => 'group_relid']),
            $this->search()
        );

        return $this->screen('products', 'Products', [
            'pager'    =>  $page,
            'statuses' =>  Product::statuses(),
            'groups'   =>  $this->groupChoices(),
        ]);
    }

    /**
     * Add a Product
     * @return ?string
     */
    public function create(): ?string
    {
        if (Request::isPost()) {
            $input = Request::inputs();

            if ($this->validate($input)) {
                $id = Product::store($input);
                $product = Product::find($id);

                $this->log('product.created', 'Added product ' . $product['product_name']);

                return $this->done('staff.product', local('product_added'), true, ['product' => $product['uid']]);
            }
        }

        return $this->form(null, local('add_product'));
    }

    /**
     * One Product, With Its Prices
     * @param string $product Product Uid
     * @return string
     */
    public function show(string $product): string
    {
        $row = $this->record(Product::find($product), 'product');

        return $this->screen('product', $row['product_name'], [
            'product'    =>  $row,
            'group'      =>  Product::group((int) $row['group_relid']),
            'pricing'    =>  $this->pricingGrid((int) $row['pid']),
            'currencies' =>  $this->currencyChoices(),
            'cycles'     =>  $this->cycleChoices(),
        ]);
    }

    /**
     * Edit a Product
     * @param string $product Product Uid
     * @return ?string
     */
    public function edit(string $product): ?string
    {
        $row = $this->record(Product::find($product), 'product');

        if (Request::isPost()) {
            $input = Request::inputs();

            // Prices arrive from the grid on the product screen, keyed
            // currency => cycle => amount. They are saved through setPrice(),
            // which upserts - the table has a unique index across the three
            // columns, so a second insert would be a duplicate-key error.
            if (isset($input['price']) && is_array($input['price'])) {
                $this->savePrices((int) $row['pid'], $input['price']);

                $this->log('product.priced', 'Updated pricing for ' . $row['product_name']);

                return $this->done('staff.product', local('pricing_updated'), true, ['product' => $row['uid']]);
            }

            if ($this->validate($input, (int) $row['pid'])) {
                $changes = Activity::changes($row, $input);

                Product::modify((int) $row['pid'], $input);

                $this->log('product.updated', 'Updated product ' . $row['product_name'], $changes);

                return $this->done('staff.product', local('product_updated'), true, ['product' => $row['uid']]);
            }
        }

        return $this->form($row, local('edit_named', $row['product_name']));
    }

    /**
     * Delete a Product
     * @param string $product Product Uid
     * @return ?string
     */
    public function delete(string $product): ?string
    {
        $row = $this->record(Product::find($product), 'product');
        $name = (string) $row['product_name'];

        return $this->attempt(
            function () use ($row, $name): void {
                Product::remove((int) $row['pid']);

                $this->log('product.deleted', "Deleted product {$name}.");
            },
            'staff.products',
            local('deleted_named', $name)
        );
    }

    ####################################################################################
    /*==================================== GROUPS ====================================*/
    ####################################################################################

    /**
     * Product Groups
     * @return string
     */
    public function groups(): string
    {
        return $this->screen('product-groups', local('product_groups'), [
            'groups' =>  Product::groups(),
            'counts' =>  $this->groupCounts(),
        ]);
    }

    /**
     * Create Or Update a Group
     *
     * One POST for both, because the screen is a single form: a row per group
     * plus a blank one at the bottom.
     * @return ?string
     */
    public function groupSave(): ?string
    {
        $input = Request::inputs();
        $name = trim((string) ($input['group_name'] ?? ''));

        if ($name === '') {
            return $this->done('staff.product.groups', local('group_needs_name'), false);
        }

        $key = trim((string) ($input['group'] ?? ''));

        Product::saveGroup($input, $key !== '' ? $key : null);

        $this->log('product.group.saved', ($key !== '' ? 'Updated' : 'Added') . " product group {$name}.");

        return $this->done(
            'staff.product.groups',
            local($key !== '' ? 'group_updated' : 'group_added')
        );
    }

    /**
     * Delete a Group
     * @param string $group Group Uid
     * @return ?string
     */
    public function groupDelete(string $group): ?string
    {
        $row = $this->record(Product::group($group), 'product group');
        $name = (string) $row['group_name'];

        return $this->attempt(
            function () use ($row, $name): void {
                Product::removeGroup((int) $row['group_id']);

                $this->log('product.group.deleted', "Deleted product group {$name}.");
            },
            'staff.product.groups',
            local('deleted_named', $name)
        );
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Render The Product Form
     * @param ?array $product Product, Or Null When Adding
     * @param string $title Page Title
     * @return string
     */
    private function form(?array $product, string $title): string
    {
        return $this->screen('product-form', $title, [
            'product'  =>  $product,
            'statuses' =>  $this->statusChoices(Product::statuses()),
            'groups'   =>  $this->groupChoices(),
            'types'    =>  $this->typeChoices(),
            'models'   =>  $this->pricingModelChoices(),
        ]);
    }

    /**
     * Validate a Product Submission
     * @param array $input Submitted Data
     * @param ?int $ignore Product ID To Exclude, When Editing
     * @return bool
     */
    private function validate(array $input, ?int $ignore = null): bool
    {
        return $this->require([
            'product_name' =>  local('product_name_required'),
            'group_relid'  =>  local('choose_group_for_product'),
            'type_relid'   =>  local('choose_product_type'),
        ], $input);
    }

    /**
     * Save The Price Grid
     * @param int $productId Product ID
     * @param array $prices currency => cycle => ['price' => …, 'setup_fee' => …]
     * @return void
     */
    private function savePrices(int $productId, array $prices): void
    {
        foreach ($prices as $currencyId => $cycles) {
            if (!is_array($cycles)) {
                continue;
            }

            foreach ($cycles as $cycleId => $values) {
                $price = trim((string) ($values['price'] ?? ''));

                // A blank cell is "this product is not sold on this cycle in
                // this currency", which is a legitimate and common state - not
                // a price of zero.
                if ($price === '') {
                    continue;
                }

                Product::setPrice(
                    $productId,
                    (int) $currencyId,
                    (int) $cycleId,
                    $price,
                    (string) ($values['setup_fee'] ?? '0'),
                    !empty($values['is_active'])
                );
            }
        }
    }

    /**
     * The Existing Prices, Keyed currency => cycle
     * @param int $productId Product ID
     * @return array
     */
    private function pricingGrid(int $productId): array
    {
        $grid = [];

        foreach (Product::pricing($productId) as $row) {
            $grid[(int) $row['currency_relid']][(int) $row['billing_cycle_relid']] = $row;
        }

        return $grid;
    }

    /**
     * How Many Products Sit In Each Group
     * @return array<int,int>
     */
    private function groupCounts(): array
    {
        $counts = [];

        foreach (Product::groups() as $group) {
            $counts[(int) $group['group_id']] = Product::count(['group_relid' => (int) $group['group_id']]);
        }

        return $counts;
    }

    /**
     * Group Choices
     * @return array<int,string>
     */
    private function groupChoices(): array
    {
        $choices = [];

        foreach (Product::groups() as $group) {
            $choices[(int) $group['group_id']] = (string) $group['group_name'];
        }

        return $choices;
    }

    /**
     * Currency Choices
     * @return array<int,string>
     */
    private function currencyChoices(): array
    {
        $choices = [];

        foreach (Currency::listing(true) as $row) {
            $choices[(int) $row['currency_id']] = (string) $row['currency_code'];
        }

        return $choices;
    }

    /**
     * Billing Cycle Choices
     * @return array<int,string>
     */
    private function cycleChoices(): array
    {
        $model = new BillingCycleModel();
        $choices = [];

        foreach ($model->order($model->id, 'ASC')->get() as $row) {
            $choices[(int) $row['billing_cycle_id']] =
                ucwords(str_replace('_', ' ', (string) $row['billing_cycle_name']));
        }

        return $choices;
    }

    /**
     * Product Type Choices
     *
     * Read from the table rather than hardcoded: product_types is seeded data
     * an operator can extend, and a fixed list here would quietly ignore
     * anything they added.
     * @return array<int,string>
     */
    private function typeChoices(): array
    {
        $model = (new Model())->table('product_types');
        $choices = [];

        foreach ($model->get() as $row) {
            $id = (int) ($row['product_type_id'] ?? $row['type_id'] ?? 0);

            if ($id === 0) {
                continue;
            }

            $name = (string) ($row['product_type_name'] ?? $row['type_name'] ?? $row['name'] ?? '');
            $choices[$id] = ucwords(str_replace('_', ' ', $name));
        }

        return $choices;
    }

    /**
     * How a Product Can Be Charged For
     *
     * The list comes from the action, so the form can only offer values the
     * action will actually accept - it silently rewrites anything else to
     * 'recurring', and a dropdown that quietly changed the answer would be
     * worse than one that never offered it.
     * @return array<string,string>
     */
    private function pricingModelChoices(): array
    {
        $labels = [
            'recurring' =>  'Recurring',
            'one_time'  =>  'One-off',
            'usage'     =>  local('usage_based'),
            'free'      =>  'Free',
        ];

        $choices = [];

        foreach (Product::pricingModels() as $model) {
            $choices[$model] = $labels[$model] ?? ucwords(str_replace('_', ' ', $model));
        }

        return $choices;
    }
}
