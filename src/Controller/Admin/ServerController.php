<?php
/**
 * Laika Bill Manager
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: Proprietary - see LICENSE
 * This file is part of Laika Bill Manager.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace LBM\Controller\Admin;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Laika\Service\Request;
use LBM\Service\Module;
use LBM\Service\Server;

/**
 * Provisioning servers.
 *
 * Credentials are encrypted at rest and never reach a template. The edit form
 * shows a blank password field and the action leaves the stored value alone
 * unless a new one is typed - so a screen cannot leak a root login, and
 * correcting a hostname cannot accidentally wipe one.
 *
 * Phase 46: the control-panel MODULES are listed above the machines, laid out
 * like every other kind's screen - Enable and Disable, and no Configure, because
 * a server module's fields belong to each product. "Add server" stays: a
 * machine is not a module. And a machine's control panel is chosen from the
 * modules on disk rather than typed.
 */
class ServerController extends AdminController
{
    protected function nav(): string
    {
        return 'settings';
    }

    /**
     * The Server List
     * @return string
     */
    public function index(): string
    {
        $page = Server::browse(
            $this->conditions(['status' => 'status_relid', 'group' => 'group_relid']),
            $this->search()
        );

        // How full each server is, worked out here so the template only has a
        // number to draw rather than a calculation to get right.
        foreach ($page['rows'] as $i => $row) {
            $page['rows'][$i]['usage'] = Server::usage($row);
        }

        return $this->screen('servers', local('servers'), [
            'pager'    =>  $page,
            'statuses' =>  Server::statuses(),
            'groups'   =>  $this->groupChoices(),
            'modules'  =>  $this->moduleList('servers'),
            'path'     =>  'modules/servers',
        ]);
    }

    /**
     * Add a Server
     * @return ?string
     */
    public function create(): ?string
    {
        if (Request::isPost()) {
            $input = Request::inputs();

            if ($this->validate($input)) {
                $id = Server::store($input);
                $row = Server::find($id);

                $this->log('server.created', 'Added server ' . $row['name']);

                return $this->done('staff.servers', local('server_added'));
            }
        }

        return $this->form(null, local('add_server'));
    }

    /**
     * Edit a Server
     * @param string $server Server Uid
     * @return ?string
     */
    public function edit(string $server): ?string
    {
        $row = $this->record(Server::find($server), 'server');

        if (Request::isPost()) {
            $input = Request::inputs();

            if ($this->validate($input)) {
                Server::modify((int) $row['server_id'], $input);

                $this->log('server.updated', 'Updated server ' . $row['name']);

                return $this->done('staff.servers', local('server_updated'));
            }
        }

        return $this->form($row, local('edit_named', $row['name']));
    }

    /**
     * Check a Server Answers
     * @param string $server Server Uid
     * @return ?string
     */
    public function test(string $server): ?string
    {
        $row = $this->record(Server::find($server), 'server');

        $result = Server::test((int) $row['server_id']);

        $this->log(
            'server.tested',
            'Tested ' . $row['name'] . ': ' . $result['message']
        );

        return $this->done('staff.servers', $result['message'], $result['ok']);
    }

    /**
     * Delete a Server
     * @param string $server Server Uid
     * @return ?string
     */
    public function delete(string $server): ?string
    {
        $row = $this->record(Server::find($server), 'server');
        $name = (string) $row['name'];

        return $this->attempt(
            function () use ($row, $name): void {
                Server::remove((int) $row['server_id']);

                $this->log('server.deleted', "Deleted server {$name}.");
            },
            'staff.servers',
            local('deleted_named', $name)
        );
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Render The Server Form
     * @param ?array $server Server, Or Null When Adding
     * @param string $title Page Title
     * @return string
     */
    private function form(?array $server, string $title): string
    {
        return $this->screen('server-form', $title, [
            'server'      =>  $server,
            'statuses'    =>  $this->statusChoices(Server::statuses()),
            'groups'      =>  $this->groupChoices(),
            'panels'      =>  $this->panelChoices($server),
            'panel_value' =>  $this->panelValue($server),
        ]);
    }

    /**
     * Validate a Server Submission
     * @param array $input Submitted Data
     * @return bool
     */
    private function validate(array $input): bool
    {
        return $this->require([
            'name'        =>  local('name_required'),
            'hostname'    =>  local('hostname_required'),
            'ip_address'  =>  local('ip_required'),
            'module_name' =>  local('which_control_panel'),
        ], $input);
    }

    /**
     * The Control Panels a Machine Can Run - Phase 46
     *
     * The server modules on disk rather than a free-text box: a typo in a
     * module name is a machine nothing is ever provisioned through, and the
     * only symptom is every service on it waiting for somebody by hand.
     *
     * THE ONE A MACHINE ALREADY HAS IS ALWAYS AMONG THEM, installed or not -
     * Phase 35's select trap. A <select> whose value is not among its options
     * posts another one, so saving a hostname fix would quietly move the
     * machine to a different panel.
     * @param ?array $server Server Row, Or Null When Adding
     * @return array<string,string>
     */
    private function panelChoices(?array $server): array
    {
        $choices = [];

        foreach (Module::ofType('servers') as $module) {
            $choices[(string) $module['directory']] = !empty($module['enabled'])
                ? (string) $module['name']
                : local('module_named_off', (string) $module['name']);
        }

        $current = trim((string) ($server['module_name'] ?? ''));

        if ($current !== '' && $this->panelOnDisk($current) === null) {
            $choices[$current] = local('module_named_missing', $current);
        }

        return $choices;
    }

    /**
     * Which Panel Is Selected
     *
     * The on-disk spelling when the stored one matches in another case - the
     * loader matches case-insensitively, so `cpanel` and `Cpanel` are the same
     * module, and the dropdown has to find it rather than fall back to its
     * first option.
     * @param ?array $server Server Row, Or Null
     * @return string
     */
    private function panelValue(?array $server): string
    {
        $current = trim((string) ($server['module_name'] ?? ''));

        return $current === '' ? '' : ($this->panelOnDisk($current) ?? $current);
    }

    /**
     * The On-Disk Spelling Of a Server Module, If It Is Installed
     * @param string $module Module Directory, In Any Case
     * @return ?string
     */
    private function panelOnDisk(string $module): ?string
    {
        foreach (Module::ofType('servers') as $found) {
            if (strcasecmp((string) $found['directory'], $module) === 0) {
                return (string) $found['directory'];
            }
        }

        return null;
    }

    /**
     * Server Group Choices
     * @return array<int,string>
     */
    private function groupChoices(): array
    {
        $choices = [];

        foreach (Server::groups() as $group) {
            $choices[(int) $group['group_id']] = (string) $group['group_name'];
        }

        return $choices;
    }
}
