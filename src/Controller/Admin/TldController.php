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
use LBM\Model\DomainRegistrarModel;
use LBM\Service\Activity;
use LBM\Service\Currency;
use LBM\Service\Tld;
use LBM\Support\RegistersDomains;

/**
 * The domain price list - which endings the shop sells and what they cost.
 *
 * Behind `domain` rather than a permission group of its own, and unlike addons
 * that needed no argument: the group already exists in Permission::GROUPS and
 * is already granted on every install, because the domain screens have been
 * there since Phase 8. Anybody trusted to edit a customer domain is trusted to
 * price the endings those domains are on.
 *
 * ---------------------------------------------------------------------------
 * THE SCREEN SAYS WHAT WILL ACTUALLY HAPPEN
 * ---------------------------------------------------------------------------
 * Two things about an ending are invisible from its row and change what an
 * order does, so both are computed and shown:
 *
 *   - Whether the registrar it points at has a MODULE installed and switched
 *     on. Without one, every domain sold on that ending is recorded and left
 *     `pending` for somebody to register by hand. That is supported, and an
 *     operator who does not know it is happening will find out from a customer.
 *   - Which currency it is priced in. A TLD carries exactly one, so an ending
 *     priced in a currency a customer is not checking out in cannot be sold to
 *     them at all - see Action\Tld on why that is a refusal and not a
 *     conversion.
 */
class TldController extends AdminController
{
    use RegistersDomains;

    protected function nav(): string
    {
        return 'tlds';
    }

    /**
     * The Price List
     * @return string
     */
    public function index(): string
    {
        $endings = Tld::listing();

        return $this->screen('tlds', local('domain_pricing'), [
            'endings'    =>  $endings,
            'registrars' =>  $this->registrarChoices(),
            'modules'    =>  $this->moduleState(),
            'terms'      =>  $this->termsFor($endings),
            'currencies' =>  $this->currencyCodes(),
            'default'    =>  Currency::default(),
        ]);
    }

    /**
     * Add An Ending
     * @return ?string
     */
    public function create(): ?string
    {
        if (Request::isPost()) {
            $input = Request::inputs();

            return $this->attempt(
                function () use ($input): void {
                    $id = Tld::store($input);
                    $row = Tld::find($id);

                    $this->log('tld.created', 'Added domain ending ' . (string) $row['tld']);
                },
                'staff.tlds',
                local('tld_added')
            );
        }

        return $this->form(null, local('add_an_ending'));
    }

    /**
     * Edit An Ending
     * @param string $tld TLD Uid
     * @return ?string
     */
    public function edit(string $tld): ?string
    {
        $row = $this->record(Tld::find($tld), 'tld');

        if (Request::isPost()) {
            $input = Request::inputs();

            return $this->attempt(
                function () use ($row, $input): void {
                    $changes = Activity::changes($row, $input);

                    Tld::modify((int) $row['tld_id'], $input);

                    $this->log('tld.updated', 'Updated domain ending ' . (string) $row['tld'], $changes);
                },
                'staff.tlds',
                local('tld_updated')
            );
        }

        return $this->form($row, local('edit_named', (string) $row['tld']));
    }

    /**
     * Take An Ending Off The Price List
     * @param string $tld TLD Uid
     * @return ?string
     */
    public function delete(string $tld): ?string
    {
        $row = $this->record(Tld::find($tld), 'tld');
        $name = (string) $row['tld'];

        return $this->attempt(
            function () use ($row, $name): void {
                Tld::remove((int) $row['tld_id']);

                $this->log('tld.deleted', "Deleted domain ending {$name}.");
            },
            'staff.tlds',
            local('deleted_named', $name)
        );
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * The Add / Edit Form
     * @param ?array $tld TLD Row, Or Null To Create
     * @param string $title Page Title
     * @return string
     */
    private function form(?array $tld, string $title): string
    {
        return $this->screen('tld-form', $title, [
            'ending'     =>  $tld,
            'editing'    =>  $tld !== null,
            'registrars' =>  $this->registrarChoices(),
            'currencies' =>  $this->currencyChoices(),
            'modules'    =>  $this->moduleState(),

            // The form offers exactly the terms a `domains` row can record, and
            // says so on the screen. An operator who types a max of 10 and is
            // silently given 3 has been overruled without being told.
            'longest'    =>  max(Tld::terms()),
        ]);
    }

    /**
     * Registrar Names, Keyed By Id
     * @return array<int,string>
     */
    private function registrarChoices(): array
    {
        $model = new DomainRegistrarModel();
        $choices = [];

        foreach ($model->order('name', 'ASC')->get() as $row) {
            $choices[(int) $row['dr_id']] = (string) $row['name'];
        }

        return $choices;
    }

    /**
     * Whether Each Registrar Has a Working Module Behind It
     *
     * Answered by BUILDING the driver rather than by looking for a row, because
     * that is the question the order path will ask. A registrar with a
     * module_name naming something that is not installed, is installed but
     * switched off, or does not implement the contract, all look identical in
     * the database and all mean the same thing here: nothing will be registered
     * automatically.
     * @return array<int,bool>
     */
    private function moduleState(): array
    {
        $state = [];

        foreach ((new DomainRegistrarModel())->get() as $row) {
            $state[(int) $row['dr_id']] = $this->registrarDriver($row) !== null;
        }

        return $state;
    }

    /**
     * Currency Choices For The Form
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
     * Currency Codes, Keyed By Id
     * @return array<int,string>
     */
    private function currencyCodes(): array
    {
        $codes = [];

        foreach (Currency::listing() as $row) {
            $codes[(int) $row['currency_id']] = (string) $row['currency_code'];
        }

        return $codes;
    }

    /**
     * The Orderable Terms For Each Ending On The List
     * @param array $endings TLD Rows
     * @return array<int,int[]>
     */
    private function termsFor(array $endings): array
    {
        $terms = [];

        foreach ($endings as $row) {
            $terms[(int) $row['tld_id']] = Tld::termsFor($row);
        }

        return $terms;
    }
}
