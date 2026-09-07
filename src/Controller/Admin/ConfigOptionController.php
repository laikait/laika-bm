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
use LBM\Service\ConfigOption;
use LBM\Service\Currency;
use LBM\Service\Product;

/**
 * Configurable options - how one product is sold in more than one size.
 *
 * Behind `product`, and for AddonController's reason: this IS catalogue, and
 * 20.5's rule says a permission group of its own would ship a screen invisible
 * on every install that already exists, because Permission::GROUPS is granted
 * only when a role is created.
 *
 * THREE LEVELS ON TWO SCREENS. A group is a field, a field has choices, and each
 * choice has a price in every currency on every cycle - so a form deep enough to
 * hold all of it in one go would be unusable, and one screen per level would be
 * four clicks to change a number. The list and the form create the field; the
 * read screen holds the choices and their prices, the way an addon's prices sit
 * on its read screen rather than behind another click.
 *
 * The pricing grid is a product's grid - currency down, cycle across - because
 * an operator comparing a plan's price with the cost of upgrading its RAM should
 * not have to translate between two layouts.
 */
class ConfigOptionController extends AdminController
{
    protected function nav(): string
    {
        return 'config_options';
    }

    /**
     * The Configurable Option List
     * @return string
     */
    public function index(): string
    {
        return $this->screen('config-options', local('config_options'), [
            'options'  =>  ConfigOption::listing(),
            'products' =>  ConfigOption::productCounts(),
        ]);
    }

    /**
     * Create a Configurable Option
     * @return ?string
     */
    public function create(): ?string
    {
        if (Request::isPost()) {
            $input = Request::inputs();

            return $this->attempt(
                function () use ($input): void {
                    $id = ConfigOption::store($input);
                    $row = ConfigOption::find($id);

                    $this->log(
                        'config.option.created',
                        'Added configurable option ' . (string) $row['config_group_name']
                    );
                },
                'staff.config.options',
                local('config_option_added')
            );
        }

        return $this->form(null, local('add_a_config_option'));
    }

    /**
     * One Option, With Its Choices, Their Prices And The Products Offering It
     * @param string $option Group Uid
     * @return string
     */
    public function show(string $option): string
    {
        $row = $this->record(ConfigOption::find($option), 'config option');
        $detail = ConfigOption::detailed((int) $row['pcg_id']);

        return $this->screen('config-option', (string) $row['config_group_name'], [
            'option'     =>  $row,
            'detail'     =>  $detail,
            'subs'       =>  $detail['subs'],
            'pricing'    =>  $this->pricingGrid($detail['subs']),
            'currencies' =>  $this->currencyChoices(),
            'cycles'     =>  $this->cycleChoices(),
            'products'   =>  $this->productsOffering((int) $row['pcg_id']),
        ]);
    }

    /**
     * Edit An Option, Or Save The Prices On One Of Its Choices
     * @param string $option Group Uid
     * @return ?string
     */
    public function edit(string $option): ?string
    {
        $row = $this->record(ConfigOption::find($option), 'config option');

        if (Request::isPost()) {
            $input = Request::inputs();

            // The grid posts `price[currency][cycle]` for ONE choice, named by
            // `sub`. The form posts a name. One route for both, the same
            // arrangement the product and addon screens use.
            if (isset($input['price']) && is_array($input['price'])) {
                $this->savePrices((int) ($input['sub'] ?? 0), (int) $row['pcg_id'], $input['price']);

                $this->log(
                    'config.option.priced',
                    'Updated pricing for ' . (string) $row['config_group_name']
                );

                return $this->done(
                    'staff.config.option',
                    local('pricing_updated'),
                    true,
                    ['option' => $row['uid']]
                );
            }

            return $this->attempt(
                function () use ($row, $input): void {
                    $changes = Activity::changes($row, $input);

                    ConfigOption::modify((int) $row['pcg_id'], $input);

                    $this->log(
                        'config.option.updated',
                        'Updated configurable option ' . (string) $row['config_group_name'],
                        $changes
                    );
                },
                'staff.config.option',
                local('config_option_updated'),
                ['option' => $row['uid']]
            );
        }

        return $this->form($row, local('edit_named', (string) $row['config_group_name']));
    }

    /**
     * Delete a Configurable Option
     * @param string $option Group Uid
     * @return ?string
     */
    public function delete(string $option): ?string
    {
        $row = $this->record(ConfigOption::find($option), 'config option');
        $name = (string) $row['config_group_name'];

        return $this->attempt(
            function () use ($row, $name): void {
                ConfigOption::remove((int) $row['pcg_id']);

                $this->log('config.option.deleted', "Deleted configurable option {$name}.");
            },
            'staff.config.options',
            local('deleted_named', $name)
        );
    }

    /**
     * Add a Choice To An Option
     * @param string $option Group Uid
     * @return ?string
     */
    public function addChoice(string $option): ?string
    {
        $row = $this->record(ConfigOption::find($option), 'config option');
        $input = Request::inputs();

        return $this->attempt(
            function () use ($row, $input): void {
                ConfigOption::addSub((int) $row['pcg_id'], $input);

                $this->log(
                    'config.choice.added',
                    'Added a choice to ' . (string) $row['config_group_name']
                );
            },
            'staff.config.option',
            local('config_choice_added'),
            ['option' => $row['uid']]
        );
    }

    /**
     * Rename Or Withdraw One Choice
     * @param string $option Group Uid
     * @return ?string
     */
    public function editChoice(string $option): ?string
    {
        $row = $this->record(ConfigOption::find($option), 'config option');
        $input = Request::inputs();
        $subId = (int) ($input['sub'] ?? 0);

        return $this->attempt(
            function () use ($row, $input, $subId): void {
                $this->ownedSub($row, $subId);

                ConfigOption::modifySub($subId, $input);

                $this->log(
                    'config.choice.updated',
                    'Updated a choice on ' . (string) $row['config_group_name']
                );
            },
            'staff.config.option',
            local('config_choice_updated'),
            ['option' => $row['uid']]
        );
    }

    /**
     * Delete One Choice
     * @param string $option Group Uid
     * @return ?string
     */
    public function deleteChoice(string $option): ?string
    {
        $row = $this->record(ConfigOption::find($option), 'config option');
        $subId = (int) (Request::inputs()['sub'] ?? 0);

        return $this->attempt(
            function () use ($row, $subId): void {
                $this->ownedSub($row, $subId);

                ConfigOption::removeSub($subId);

                $this->log(
                    'config.choice.deleted',
                    'Deleted a choice from ' . (string) $row['config_group_name']
                );
            },
            'staff.config.option',
            local('config_choice_deleted'),
            ['option' => $row['uid']]
        );
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * The Add / Edit Form
     * @param ?array $option Group Row, Or Null To Create
     * @param string $title Page Title
     * @return string
     */
    private function form(?array $option, string $title): string
    {
        $groupId = (int) ($option['pcg_id'] ?? 0);
        $field = $groupId > 0 ? ConfigOption::optionFor($groupId) : null;

        return $this->screen('config-option-form', $title, [
            'option'  =>  $option,
            'field'   =>  $field,
            'editing' =>  $option !== null,
            'types'   =>  $this->typeChoices(),
        ]);
    }

    /**
     * Refuse a Choice That Belongs To Another Option
     *
     * The sub id comes off a form and the group comes off the URL, so nothing
     * else makes the two agree. Without this, a posted `sub` from any option in
     * the install would be renamed, repriced or deleted from a screen the
     * operator is only looking at one of - and the activity log would name the
     * wrong one.
     * @param array $group Group Row
     * @param int $subId Sub ID
     * @return void
     * @throws \RuntimeException
     */
    private function ownedSub(array $group, int $subId): void
    {
        $field = ConfigOption::optionFor((int) $group['pcg_id']);
        $sub = $subId > 0 ? ConfigOption::sub($subId) : null;

        if ($field === null
            || !is_array($sub)
            || (int) $sub['pco_relid'] !== (int) $field['pco_id']) {
            throw new \RuntimeException('That choice is not part of this option.');
        }
    }

    /**
     * Save One Choice's Pricing Grid
     *
     * A blank cell WITHDRAWS the price rather than storing zero, the rule every
     * grid in this panel follows: no row means not sold on that cycle, zero
     * means included at no extra charge.
     * @param int $subId Sub ID
     * @param int $groupId Group ID, To Check The Choice Belongs To It
     * @param array $prices Submitted Grid
     * @return void
     */
    private function savePrices(int $subId, int $groupId, array $prices): void
    {
        $field = ConfigOption::optionFor($groupId);
        $sub = $subId > 0 ? ConfigOption::sub($subId) : null;

        // Same ownership question as ownedSub(), asked before a single row is
        // written rather than after the first one.
        if ($field === null || !is_array($sub) || (int) $sub['pco_relid'] !== (int) $field['pco_id']) {
            return;
        }

        foreach ($prices as $currencyId => $cycles) {
            if (!is_array($cycles)) {
                continue;
            }

            foreach ($cycles as $cycleId => $cell) {
                $price = is_array($cell) ? ($cell['price'] ?? null) : $cell;
                $setup = is_array($cell) ? ($cell['setup_fee'] ?? null) : null;

                ConfigOption::setPrice(
                    $subId,
                    (int) $currencyId,
                    (int) $cycleId,
                    is_scalar($price) ? (string) $price : null,
                    is_scalar($setup) ? (string) $setup : null
                );
            }
        }
    }

    /**
     * Every Choice's Prices, Keyed Sub Then Currency Then Cycle
     * @param array $subs Choices
     * @return array<int,array<int,array<int,array>>>
     */
    private function pricingGrid(array $subs): array
    {
        $grid = [];

        foreach ($subs as $sub) {
            $subId = (int) $sub['pcos_id'];
            $grid[$subId] = [];

            foreach (ConfigOption::pricing($subId) as $row) {
                $grid[$subId][(int) $row['currency_relid']][(int) $row['billing_cycle_relid']] = $row;
            }
        }

        return $grid;
    }

    /**
     * The Products Offering One Option
     * @param int $groupId Group ID
     * @return array
     */
    private function productsOffering(int $groupId): array
    {
        $offering = [];

        foreach (Product::all() as $product) {
            if (in_array($groupId, ConfigOption::mappedIds((int) $product['pid']), true)) {
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
     * What Kind Of Field An Option Can Be
     * @return array<string,string>
     */
    private function typeChoices(): array
    {
        $choices = [];

        foreach (ConfigOption::types() as $type) {
            $choices[$type] = ucwords($type);
        }

        return $choices;
    }
}
