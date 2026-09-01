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
use LBM\Service\Server;

/**
 * Provisioning servers.
 *
 * Credentials are encrypted at rest and never reach a template. The edit form
 * shows a blank password field and the action leaves the stored value alone
 * unless a new one is typed - so a screen cannot leak a root login, and
 * correcting a hostname cannot accidentally wipe one.
 */
class ServerController extends AdminController
{
    protected function nav(): string
    {
        return 'servers';
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

        return $this->screen('servers', 'Servers', [
            'pager'    =>  $page,
            'statuses' =>  Server::statuses(),
            'groups'   =>  $this->groupChoices(),
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
            'server'   =>  $server,
            'statuses' =>  $this->statusChoices(Server::statuses()),
            'groups'   =>  $this->groupChoices(),
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
