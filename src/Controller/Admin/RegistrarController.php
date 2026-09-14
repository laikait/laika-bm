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
use LBM\Service\Lookup;
use LBM\Service\Registrar;

/**
 * The registrars domains are registered through, and the lookup modules a
 * search asks - Phase 35, Phase 36, and laid out like the Modules page since
 * Phase 46.
 *
 * Behind `domain`, as the TLD screens are: the group already exists and is
 * already granted on every install, so no 20.5 argument is needed.
 *
 * ---------------------------------------------------------------------------
 * A REGISTRAR IS ITS MODULE, SWITCHED ON
 * ---------------------------------------------------------------------------
 * There is no "Add a registrar". Enabling a registrar module creates its row
 * the first time (Action\Registrar::attach()), and its credentials, mode and
 * default are saved on its Configure page; ModuleController does both, because
 * the switch and the page are shared by every kind. Each row here still says
 * which registrar the module serves and what points at it.
 *
 * Registering by hand is still supported - "By hand (no module)" on a TLD - and
 * a registrar whose module has left the disk is listed on its own, because it
 * looks exactly like a working one in the database and every domain on its TLDs
 * is then left for somebody by hand.
 *
 * ---------------------------------------------------------------------------
 * AND WHICH LOOKUP MODULE IS ASKED FIRST - PHASE 36
 * ---------------------------------------------------------------------------
 * A card beside the lookup modules, because the two answer one question
 * between them: a public search asks the chosen lookup module, then the
 * registrar a TLD points at. Choosing is `domain.update`.
 *
 * No secret reaches this screen: credentials are never read here at all.
 */
class RegistrarController extends AdminController
{
    protected function nav(): string
    {
        return 'settings';
    }

    /**
     * Registrar Modules, Lookup Modules, And The Lookup Choice
     * @return string
     */
    public function index(): string
    {
        $registrars = $this->moduleList('registrars');

        foreach ($registrars as $uid => $module) {
            $row = Registrar::forModule((string) $module['directory']);

            if ($row !== null) {
                $registrars[$uid]['note'] = $this->noteFor($row);
            }
        }

        $manual = Registrar::manualRow();

        return $this->screen('registrars', local('registrars'), [
            'registrars' =>  $registrars,
            'lookups'    =>  $this->moduleList('lookup'),
            'orphans'    =>  $this->orphans(),
            'manual'     =>  $manual === null ? null : ['row' => $manual, 'usage' => Registrar::usage($manual)],
            'lookup'     =>  $this->lookupCard(),
        ]);
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
     * What a Registrar Module's Row Says About Its Registrar
     * @param array $row Registrar Row
     * @return string
     */
    private function noteFor(array $row): string
    {
        $usage = Registrar::usage($row);
        $parts = [local('registrar_note', (string) $row['name'])];

        if (($row['is_default'] ?? 'no') === 'yes') {
            $parts[] = local('default_registrar_badge');
        }

        if (($row['test_mode'] ?? 'no') === 'yes') {
            $parts[] = local('mode_test');
        }

        if (($row['is_active'] ?? 'yes') !== 'yes') {
            $parts[] = local('inactive');
        }

        $parts[] = local('registrar_usage', $usage['tlds'], $usage['domains']);

        return implode(' · ', $parts);
    }

    /**
     * Registrars Whose Module Is No Longer On Disk
     *
     * Nothing is sent to them, and nothing here can switch them on: their
     * module has to come back. Listed so an operator can see which TLDs are
     * quietly waiting on somebody by hand.
     * @return array<int,array{name:string,module:string,usage:array{tlds:int,domains:int}}>
     */
    private function orphans(): array
    {
        $orphans = [];

        foreach (Registrar::listing() as $row) {
            $module = trim((string) $row['module_name']);

            if ($module === '' || Registrar::installedModule($module) !== null) {
                continue;
            }

            $orphans[] = [
                'name'   =>  (string) $row['name'],
                'module' =>  $module,
                'usage'  =>  Registrar::usage($row),
            ];
        }

        return $orphans;
    }

    /**
     * What The Lookup Card Shows
     *
     * The chosen module is ALWAYS among the choices, installed or not - Phase
     * 35's select trap: a <select> whose value is not among its options posts
     * another one, and saving would quietly change the choice.
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
}
