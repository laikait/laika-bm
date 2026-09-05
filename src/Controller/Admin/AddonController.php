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

use Laika\Service\Request;
use LBM\Model\BillingCycleModel;
use LBM\Service\Activity;
use LBM\Service\Addon;
use LBM\Service\Currency;
use LBM\Service\Product;

/**
 * Addons - the extras sold alongside a product.
 *
 * Behind `product` rather than a permission group of its own. An addon IS
 * catalogue: anybody trusted to set a plan's price is trusted to set the price
 * of the backup service beside it, and 20.5's rule says a new group would ship
 * a screen invisible on every install that already exists, because
 * Permission::GROUPS is only granted when a role is created.
 *
 * The pricing grid is the same shape as a product's - currency down, cycle
 * across - because the two are priced against each other and an operator
 * comparing them should not have to translate between two layouts.
 */
class AddonController extends AdminController
{
    protected function nav(): string
    {
        return 'addons';
    }

    /**
     * The Addon List
     * @return string
     */
    public function index(): string
    {
        return $this->screen('addons', local('addons'), [
            'addons'   =>  Addon::listing(),
            'products' =>  $this->productCounts(),
        ]);
    }

    /**
     * Create An Addon
     * @return ?string
     */
    public function create(): ?string
    {
        if (Request::isPost()) {
            $input = Request::inputs();

            return $this->attempt(
                function () use ($input): void {
                    $id = Addon::store($input);
                    $row = Addon::find($id);

                    $this->log('addon.created', 'Added addon ' . (string) $row['addon_name']);
                },
                'staff.addons',
                local('addon_added')
            );
        }

        return $this->form(null, local('add_an_addon'));
    }

    /**
     * One Addon, With Its Prices And The Products Offering It
     * @param string $addon Addon Uid
     * @return string
     */
    public function show(string $addon): string
    {
        $row = $this->record(Addon::find($addon), 'addon');

        return $this->screen('addon', (string) $row['addon_name'], [
            'addon'      =>  $row,
            'pricing'    =>  $this->pricingGrid((int) $row['addon_id']),
            'currencies' =>  $this->currencyChoices(),
            'cycles'     =>  $this->cycleChoices(),
            'products'   =>  $this->productsOffering((int) $row['addon_id']),
        ]);
    }

    /**
     * Edit An Addon, Or Save Its Prices
     * @param string $addon Addon Uid
     * @return ?string
     */
    public function edit(string $addon): ?string
    {
        $row = $this->record(Addon::find($addon), 'addon');

        if (Request::isPost()) {
            $input = Request::inputs();

            // The grid posts `price[currency][cycle]`, the form posts a name.
            // One route for both, the same arrangement the product screen uses,
            // so the pricing table can live on the read screen rather than
            // behind another click.
            if (isset($input['price']) && is_array($input['price'])) {
                $this->savePrices((int) $row['addon_id'], $input['price']);

                $this->log('addon.priced', 'Updated pricing for ' . (string) $row['addon_name']);

                return $this->done('staff.addon', local('pricing_updated'), true, ['addon' => $row['uid']]);
            }

            return $this->attempt(
                function () use ($row, $input): void {
                    $changes = Activity::changes($row, $input);

                    Addon::modify((int) $row['addon_id'], $input);

                    $this->log('addon.updated', 'Updated addon ' . (string) $row['addon_name'], $changes);
                },
                'staff.addon',
                local('addon_updated'),
                ['addon' => $row['uid']]
            );
        }

        return $this->form($row, local('edit_named', (string) $row['addon_name']));
    }

    /**
     * Delete An Addon
     * @param string $addon Addon Uid
     * @return ?string
     */
    public function delete(string $addon): ?string
    {
        $row = $this->record(Addon::find($addon), 'addon');
        $name = (string) $row['addon_name'];

        return $this->attempt(
            function () use ($row, $name): void {
                Addon::remove((int) $row['addon_id']);

                $this->log('addon.deleted', "Deleted addon {$name}.");
            },
            'staff.addons',
            local('deleted_named', $name)
        );
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * The Add / Edit Form
     * @param ?array $addon Addon Row, Or Null To Create
     * @param string $title Page Title
     * @return string
     */
    private function form(?array $addon, string $title): string
    {
        return $this->screen('addon-form', $title, [
            'addon'   =>  $addon,
            'editing' =>  $addon !== null,
            'models'  =>  $this->modelChoices(),
        ]);
    }

    /**
     * Save One Row Of The Pricing Grid
     *
     * A blank cell WITHDRAWS the price rather than storing zero. Those are
     * different offers - no row means the addon is not sold on that cycle at
     * all, zero means it is included - and a grid that could only ever add
     * prices would leave an operator no way to stop selling something.
     * @param int $addonId Addon ID
     * @param array $prices Submitted Grid
     * @return void
     */
    private function savePrices(int $addonId, array $prices): void
    {
        foreach ($prices as $currencyId => $cycles) {
            if (!is_array($cycles)) {
                continue;
            }

            foreach ($cycles as $cycleId => $amount) {
                Addon::setPrice(
                    $addonId,
                    (int) $currencyId,
                    (int) $cycleId,
                    is_scalar($amount) ? (string) $amount : null
                );
            }
        }
    }

    /**
     * One Addon's Prices, Keyed Currency Then Cycle
     * @param int $addonId Addon ID
     * @return array<int,array<int,array>>
     */
    private function pricingGrid(int $addonId): array
    {
        $grid = [];

        foreach (Addon::pricing($addonId) as $row) {
            $grid[(int) $row['currency_relid']][(int) $row['billing_cycle_relid']] = $row;
        }

        return $grid;
    }

    /**
     * How Many Products Offer Each Addon
     * @return array<int,int>
     */
    private function productCounts(): array
    {
        $counts = [];

        foreach (Product::all() as $product) {
            foreach (Addon::mappedIds((int) $product['pid']) as $addonId) {
                $counts[$addonId] = ($counts[$addonId] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * The Products Offering One Addon
     * @param int $addonId Addon ID
     * @return array
     */
    private function productsOffering(int $addonId): array
    {
        $offering = [];

        foreach (Product::all() as $product) {
            if (in_array($addonId, Addon::mappedIds((int) $product['pid']), true)) {
                $offering[] = $product;
            }
        }

        return $offering;
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
     * How An Addon May Be Billed
     * @return array<string,string>
     */
    private function modelChoices(): array
    {
        $choices = [];

        foreach (Addon::pricingModels() as $model) {
            $choices[$model] = ucwords(str_replace('_', ' ', $model));
        }

        return $choices;
    }
}
