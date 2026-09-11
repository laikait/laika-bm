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
use LBM\Service\Lookup;
use LBM\Service\Registrar;

/**
 * The registrars a TLD is registered through - Phase 35.
 *
 * Behind `domain`, as the TLD screens are: the group already exists and is
 * already granted on every install, so no 20.5 argument is needed. Whoever may
 * price a TLD may say who registers it.
 *
 * ---------------------------------------------------------------------------
 * NO SECRET REACHES A TEMPLATE, OR THE ACTIVITY LOG
 * ---------------------------------------------------------------------------
 * The row handed to a view has its `credentials` column taken off first, so a
 * template cannot print one even by mistake, encrypted or not. What a view gets
 * instead is the NAMES, which is enough to say "api_key: saved".
 *
 * Activity::changes() diffs the columns a form posted against the row, so it
 * is given the five plain columns and nothing else. A credential's old and new
 * values are not something an audit trail should hold in either form.
 *
 * ---------------------------------------------------------------------------
 * AND WHICH LOOKUP MODULE IS ASKED FIRST - PHASE 36
 * ---------------------------------------------------------------------------
 * A card above the list, because the two answer one question between them: a
 * public search asks the chosen lookup module, then the registrar a TLD points
 * at. Choosing is `domain.update`, the same as editing a registrar.
 */
class RegistrarController extends AdminController
{
    protected function nav(): string
    {
        return 'settings';
    }

    /**
     * The Registrar List
     * @return string
     */
    public function index(): string
    {
        $entries = [];

        foreach (Registrar::listing() as $row) {
            $entries[] = [
                'row'         =>  $this->withoutSecrets($row),
                'state'       =>  Registrar::state($row),
                'credentials' =>  Registrar::credentialNames($row),
                'usage'       =>  Registrar::usage($row),
            ];
        }

        return $this->screen('registrars', local('registrars'), [
            'registrars' =>  $entries,
            'modules'    =>  Registrar::modules(),
            'lookup'     =>  $this->lookupCard(),
        ]);
    }

    /**
     * Add a Registrar
     * @return ?string
     */
    public function create(): ?string
    {
        if (Request::isPost()) {
            $input = Request::inputs();

            return $this->attempt(
                function () use ($input): void {
                    $id = Registrar::store($input);
                    $row = Registrar::find($id);

                    $this->log('registrar.created', 'Added registrar ' . (string) ($row['name'] ?? ''));
                },
                'staff.registrars',
                local('registrar_added')
            );
        }

        return $this->form(null, local('add_registrar'));
    }

    /**
     * Edit a Registrar
     * @param string $registrar Registrar Uid
     * @return ?string
     */
    public function edit(string $registrar): ?string
    {
        $row = $this->record(Registrar::find($registrar), 'registrar');

        if (Request::isPost()) {
            $input = Request::inputs();

            return $this->attempt(
                function () use ($row, $input): void {
                    $changes = Activity::changes(
                        $this->withoutSecrets($row),
                        array_intersect_key($input, array_flip(Registrar::fields()))
                    );

                    Registrar::modify((int) $row['dr_id'], $input);

                    $this->log('registrar.updated', 'Updated registrar ' . (string) $row['name'], $changes);
                },
                'staff.registrars',
                local('registrar_updated')
            );
        }

        return $this->form($row, local('edit_named', (string) $row['name']));
    }

    /**
     * Delete a Registrar
     * @param string $registrar Registrar Uid
     * @return ?string
     */
    public function delete(string $registrar): ?string
    {
        $row = $this->record(Registrar::find($registrar), 'registrar');
        $name = (string) $row['name'];

        return $this->attempt(
            function () use ($row, $name): void {
                Registrar::remove((int) $row['dr_id']);

                $this->log('registrar.deleted', "Deleted registrar {$name}.");
            },
            'staff.registrars',
            local('deleted_named', $name)
        );
    }

    /**
     * Choose Which Lookup Module a Search Asks First - Phase 36
     * @return ?string
     */
    public function lookup(): ?string
    {
        $posted = (string) Request::input('lookup_module', '');
        $before = Lookup::chosen() ?? 'none';

        return $this->attempt(
            function () use ($posted, $before): void {
                $after = Lookup::choose($posted);

                if ($after !== $before) {
                    $this->log('lookup.chosen', "Domain lookup changed from {$before} to {$after}.");
                }
            },
            'staff.registrars',
            local('lookup_saved')
        );
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * The Add / Edit Form
     * @param ?array $registrar Registrar Row, Or Null To Create
     * @param string $title Page Title
     * @return string
     */
    private function form(?array $registrar, string $title): string
    {
        return $this->screen('registrar-form', $title, [
            'registrar'    =>  $registrar === null ? null : $this->withoutSecrets($registrar),
            'editing'      =>  $registrar !== null,
            'modules'      =>  $this->moduleChoices($registrar),
            'module_value' =>  $this->moduleValue($registrar),
            'credentials'  =>  $registrar === null ? [] : Registrar::credentialNames($registrar),
            'state'        =>  $registrar === null ? null : Registrar::state($registrar),
        ]);
    }

    /**
     * The Module Dropdown
     *
     * THE STORED MODULE IS ALWAYS AMONG THE CHOICES, installed or not. A
     * <select> whose value is not one of its options submits whichever option
     * the browser lands on - so a registrar whose module had been deleted from
     * disk would quietly become one registered by hand the next time somebody
     * saved it to correct a typo in its name. The action accepts the unchanged
     * value for the same reason.
     * @param ?array $registrar Registrar Row, Or Null
     * @return array<string,string>
     */
    private function moduleChoices(?array $registrar): array
    {
        $choices = ['' => local('registrar_manual')];

        foreach (Registrar::modules() as $directory => $module) {
            $choices[(string) $directory] = $module['enabled']
                ? $module['name']
                : local('module_named_off', $module['name']);
        }

        $current = trim((string) ($registrar['module_name'] ?? ''));

        if ($current !== '' && Registrar::installedModule($current) === null) {
            $choices[$current] = local('module_named_missing', $current);
        }

        return $choices;
    }

    /**
     * Which Module Choice Is Selected
     *
     * The on-disk spelling when the stored name matches one in another case, so
     * the dropdown finds it rather than falling back to its first option.
     * @param ?array $registrar Registrar Row, Or Null
     * @return string
     */
    private function moduleValue(?array $registrar): string
    {
        $current = trim((string) ($registrar['module_name'] ?? ''));

        return $current === '' ? '' : (Registrar::installedModule($current) ?? $current);
    }

    /**
     * What The Lookup Card Shows
     *
     * The chosen module is ALWAYS among the choices, installed or not - the
     * same select trap moduleChoices() describes, one card up.
     * @return array{value:string,name:?string,state:string,choices:array<string,string>}
     */
    private function lookupCard(): array
    {
        $chosen = Lookup::chosen();

        $choices = ['none' => local('lookup_registrars_only')];

        foreach (Lookup::modules() as $directory => $module) {
            $choices[(string) $directory] = $module['enabled']
                ? $module['name']
                : local('module_named_off', $module['name']);
        }

        if ($chosen !== null && Lookup::installedModule($chosen) === null) {
            $choices[$chosen] = local('module_named_missing', $chosen);
        }

        return [
            'value'   =>  $chosen === null ? 'none' : (Lookup::installedModule($chosen) ?? $chosen),
            'name'    =>  Lookup::name(),
            'state'   =>  Lookup::state(),
            'choices' =>  $choices,
        ];
    }

    /**
     * A Row With Its Credentials Taken Off
     * @param array $row Registrar Row
     * @return array
     */
    private function withoutSecrets(array $row): array
    {
        unset($row['credentials']);

        return $row;
    }
}
