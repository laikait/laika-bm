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
use LBM\Service\Activity;
use LBM\Service\Currency;
use LBM\Service\Product;
use LBM\Service\Promo;

/**
 * Promotional codes.
 *
 * Behind `product`, and for the addon and configurable-option screens' reason:
 * a code is set up beside the catalogue by whoever prices it, and 20.5's rule
 * says a permission group of its own would ship a screen invisible on every
 * install that already exists.
 *
 * TWO SCREENS, not three. Unlike an addon or a configurable option there is
 * nothing hanging off a code - no choices, no per-currency grid - so a read
 * screen would be the form with the inputs turned off.
 *
 * `used_count` is on the list and on no form. It is a count of what has
 * happened, and a box that lets somebody set it back to zero is a way to give a
 * limited campaign away twice.
 */
class PromoController extends AdminController
{
    protected function nav(): string
    {
        return 'settings';
    }

    /**
     * The Code List
     * @return string
     */
    public function index(): string
    {
        return $this->screen('promos', local('promo_codes'), [
            'promos' =>  Promo::listing(),
            'now'    =>  date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Create a Code
     * @return ?string
     */
    public function create(): ?string
    {
        if (Request::isPost()) {
            $input = Request::inputs();

            return $this->attempt(
                function () use ($input): void {
                    $id = Promo::store($input);
                    $row = Promo::find($id);

                    $this->log('promo.created', 'Added promotional code ' . (string) $row['promo_code']);
                },
                'staff.promos',
                local('promo_added')
            );
        }

        return $this->form(null, local('add_a_promo'));
    }

    /**
     * Edit a Code
     * @param string $promo Promo Uid
     * @return ?string
     */
    public function edit(string $promo): ?string
    {
        $row = $this->record(Promo::find($promo), 'promo code');

        if (Request::isPost()) {
            $input = Request::inputs();

            return $this->attempt(
                function () use ($row, $input): void {
                    $changes = Activity::changes($row, $input);

                    Promo::modify((int) $row['promo_id'], $input);

                    $this->log(
                        'promo.updated',
                        'Updated promotional code ' . (string) $row['promo_code'],
                        $changes
                    );
                },
                'staff.promos',
                local('promo_updated')
            );
        }

        return $this->form($row, local('edit_named', (string) $row['promo_code']));
    }

    /**
     * Delete a Code
     * @param string $promo Promo Uid
     * @return ?string
     */
    public function delete(string $promo): ?string
    {
        $row = $this->record(Promo::find($promo), 'promo code');
        $name = (string) $row['promo_code'];

        return $this->attempt(
            function () use ($row, $name): void {
                Promo::remove((int) $row['promo_id']);

                $this->log('promo.deleted', "Deleted promotional code {$name}.");
            },
            'staff.promos',
            local('deleted_named', $name)
        );
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * The Add / Edit Form
     * @param ?array $promo Promo Row, Or Null To Create
     * @param string $title Page Title
     * @return string
     */
    private function form(?array $promo, string $title): string
    {
        return $this->screen('promo-form', $title, [
            'promo'      =>  $promo,
            'editing'    =>  $promo !== null,
            'types'      =>  $this->typeChoices(),
            'scopes'     =>  $this->scopeChoices(),
            'currencies' =>  $this->currencyChoices(),

            // Every product, not only the ones on sale: a code already
            // restricted to one that has since been hidden has to show it
            // ticked, or saving the form silently widens the campaign.
            'products'   =>  Product::all(),
            'chosen'     =>  $this->chosen($promo),
        ]);
    }

    /**
     * The Product Ids a Code Is Restricted To
     *
     * `product_ids` is a json column and the model decodes it on read, so this
     * is normally an array already - but a row written before the cast existed,
     * or by hand, can be a string, and a template iterating a string is a fatal
     * rather than an empty list.
     * @param ?array $promo Promo Row
     * @return int[]
     */
    private function chosen(?array $promo): array
    {
        $ids = $promo['product_ids'] ?? null;

        if (is_string($ids)) {
            $ids = json_decode($ids, true);
        }

        if (!is_array($ids)) {
            return [];
        }

        return array_values(array_map('intval', $ids));
    }

    /**
     * How a Code May Be Written
     * @return array<string,string>
     */
    private function typeChoices(): array
    {
        $choices = [];

        foreach (Promo::types() as $type) {
            $choices[$type] = ucfirst($type);
        }

        return $choices;
    }

    /**
     * What a Code May Be Spent On
     * @return array<string,string>
     */
    private function scopeChoices(): array
    {
        $labels = [
            'all'      =>  local('promo_scope_all'),
            'products' =>  local('promo_scope_products'),
            'domains'  =>  local('promo_scope_domains'),
        ];

        $choices = [];

        foreach (Promo::scopes() as $scope) {
            $choices[$scope] = $labels[$scope] ?? ucfirst($scope);
        }

        return $choices;
    }

    /**
     * Currency Choices
     *
     * With a blank first entry, because a percentage has no currency and the
     * form has to be able to say so.
     * @return array<string,string>
     */
    private function currencyChoices(): array
    {
        $choices = ['' => local('promo_no_currency')];

        foreach (Currency::listing(true) as $row) {
            $choices[(string) $row['currency_id']] = (string) $row['currency_code'];
        }

        return $choices;
    }
}
