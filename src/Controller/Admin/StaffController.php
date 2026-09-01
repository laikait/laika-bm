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
use LBM\Service\AuthStaff;
use LBM\Service\Password;
use LBM\Service\Permission;
use LBM\Service\Staff;

/**
 * Staff accounts and the roles that grant them access.
 *
 * The role editor is a group x action matrix. Every box is written explicitly on
 * save - an unchecked box sends nothing at all, so merging what arrived would
 * make a revoked permission survive the save, which is the one bug in a
 * permission system that actually matters.
 */
class StaffController extends AdminController
{
    protected function nav(): string
    {
        return 'staffs';
    }

    ####################################################################################
    /*==================================== STAFF =====================================*/
    ####################################################################################

    /**
     * The Staff List
     * @return string
     */
    public function index(): string
    {
        return $this->screen('staffs', 'Staff', [
            'pager'    =>  Staff::browseWithRoles(
                $this->conditions(['status' => 'status_relid', 'role' => 'role_relid']),
                $this->search()
            ),
            'statuses' =>  Staff::statuses(),
            'roles'    =>  $this->roleChoices(),
        ]);
    }

    /**
     * Add a Staff Member
     * @return ?string
     */
    public function create(): ?string
    {
        if (Request::isPost()) {
            $input = Request::inputs();
            $password = (string) ($input['password'] ?? '');

            $this->validate($input);

            if ($password === '') {
                Request::addError('password', local('password_required'));
            } else {
                foreach (Password::validate($password, $input['password_confirm'] ?? null) as $error) {
                    Request::addError('password', $error);
                    break;
                }
            }

            if (Request::errors() === []) {
                $id = Staff::store($input, $password);
                $row = Staff::find($id);

                $this->log('staff.created', 'Added staff member ' . $row['first_name'] . ' ' . $row['last_name']);

                return $this->done('staff.staff', local('staff_member_added'), true, ['staff' => $row['uid']]);
            }
        }

        return $this->form(null, local('add_staff_member'));
    }

    /**
     * One Staff Member
     * @param string $staff Staff Uid
     * @return string
     */
    public function show(string $staff): string
    {
        $row = $this->record(Staff::find($staff), 'staff member');

        return $this->screen('staff', $row['first_name'] . ' ' . $row['last_name'], [
            'member'  =>  $row,
            'role'    =>  Staff::role((int) $row['role_relid']),
            'history' =>  AuthStaff::history((int) $row['sid'], 10),
            'trail'   =>  Activity::forStaff((int) $row['sid'], 10)['rows'],
        ]);
    }

    /**
     * Edit a Staff Member
     * @param string $staff Staff Uid
     * @return ?string
     */
    public function edit(string $staff): ?string
    {
        $row = $this->record(Staff::find($staff), 'staff member');
        $id = (int) $row['sid'];

        if (Request::isPost()) {
            $input = Request::inputs();
            $password = (string) ($input['password'] ?? '');

            $this->validate($input, $id);

            if ($password !== '') {
                foreach (Password::validate($password, $input['password_confirm'] ?? null) as $error) {
                    Request::addError('password', $error);
                    break;
                }
            }

            if (Request::errors() === []) {
                $changes = Activity::changes($row, $input);

                Staff::modify($id, $input);

                if ($password !== '') {
                    Staff::setPassword($id, $password);
                }

                $this->log(
                    'staff.updated',
                    'Updated staff member ' . $row['first_name'] . ' ' . $row['last_name'],
                    $changes
                );

                return $this->done('staff.staff', local('staff_member_updated'), true, ['staff' => $row['uid']]);
            }
        }

        return $this->form($row, local('edit_named', $row['first_name'] . ' ' . $row['last_name']));
    }

    /**
     * Delete a Staff Member
     * @param string $staff Staff Uid
     * @return ?string
     */
    public function delete(string $staff): ?string
    {
        $row = $this->record(Staff::find($staff), 'staff member');
        $name = $row['first_name'] . ' ' . $row['last_name'];

        // Deleting yourself would end your own session mid-request and leave
        // you looking at a login form with no explanation.
        if ((int) $row['sid'] === $this->staffId()) {
            return $this->done('staff.staffs', local('cannot_delete_own_account'), false);
        }

        return $this->attempt(
            function () use ($row, $name): void {
                Staff::remove((int) $row['sid']);

                $this->log('staff.deleted', "Deleted staff member {$name}.");
            },
            'staff.staffs',
            local('deleted_named', $name)
        );
    }

    ####################################################################################
    /*==================================== ROLES =====================================*/
    ####################################################################################

    /**
     * The Role List
     * @return string
     */
    public function roles(): string
    {
        $roles = Staff::roles();

        foreach ($roles as $i => $role) {
            $roles[$i]['holders'] = Staff::count(['role_relid' => (int) $role['role_id']]);
        }

        return $this->screen('roles', 'Roles', [
            'roles'   =>  $roles,
            'groups'  =>  Staff::permissionGroups(),
            'actions' =>  Staff::permissionActions(),
        ]);
    }

    /**
     * Add a Role
     * @return ?string
     */
    public function roleCreate(): ?string
    {
        if (Request::isPost()) {
            $input = Request::inputs();
            $name = trim((string) ($input['role_name'] ?? ''));

            if ($name === '') {
                Request::addError('role_name', local('role_needs_name'));
            }

            if (Request::errors() === []) {
                $id = Staff::storeRole($name, $input);
                $role = Staff::role($id);

                $this->log('role.created', "Added role {$name}.");

                return $this->done('staff.role.edit', local('role_added'), true, ['role' => $role['uid']]);
            }
        }

        return $this->roleForm(null, local('add_role'));
    }

    /**
     * Edit a Role's Permission Matrix
     * @param string $role Role Uid
     * @return ?string
     */
    public function roleEdit(string $role): ?string
    {
        $row = $this->record(Staff::role($role), 'role');

        if (Request::isPost()) {
            $input = Request::inputs();
            $name = trim((string) ($input['role_name'] ?? $row['role_name']));

            Staff::modifyRole((int) $row['role_id'], $name, $input);

            $this->log('role.updated', "Updated permissions for role {$name}.");

            return $this->done('staff.role.edit', local('role_updated'), true, ['role' => $row['uid']]);
        }

        return $this->roleForm($row, local('edit_named', $row['role_name']));
    }

    /**
     * Delete a Role
     * @param string $role Role Uid
     * @return ?string
     */
    public function roleDelete(string $role): ?string
    {
        $row = $this->record(Staff::role($role), 'role');
        $name = (string) $row['role_name'];

        return $this->attempt(
            function () use ($row, $name): void {
                Staff::removeRole((int) $row['role_id']);

                $this->log('role.deleted', "Deleted role {$name}.");
            },
            'staff.roles',
            local('deleted_named', $name)
        );
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Render The Staff Form
     * @param ?array $member Staff Member, Or Null When Adding
     * @param string $title Page Title
     * @return string
     */
    private function form(?array $member, string $title): string
    {
        return $this->screen('staff-form', $title, [
            'member'   =>  $member,
            'statuses' =>  $this->statusChoices(Staff::statuses()),
            'roles'    =>  $this->roleChoices(),
        ]);
    }

    /**
     * Render The Role Form
     * @param ?array $role Role, Or Null When Adding
     * @param string $title Page Title
     * @return string
     */
    private function roleForm(?array $role, string $title): string
    {
        return $this->screen('role-form', $title, [
            'role'    =>  $role,
            'groups'  =>  Staff::permissionGroups(),
            'actions' =>  Staff::permissionActions(),
            'granted' =>  $role === null ? [] : Permission::forRole((int) $role['role_id']),
        ]);
    }

    /**
     * Validate a Staff Submission
     * @param array $input Submitted Data
     * @param ?int $ignore Staff ID To Exclude, When Editing
     * @return void
     */
    private function validate(array $input, ?int $ignore = null): void
    {
        $this->require([
            'first_name' =>  local('first_name_required'),
            'last_name'  =>  local('last_name_required'),
            'email'      =>  local('email_required'),
            'role_relid' =>  local('choose_a_role_msg'),
        ], $input);

        $this->requireEmail('email', $input);

        $email = trim((string) ($input['email'] ?? ''));

        if ($email !== '' && Staff::emailTaken($email, $ignore)) {
            Request::addError('email', local('staff_email_taken'));
        }

        $username = trim((string) ($input['username'] ?? ''));

        if ($username !== '' && Staff::usernameTaken($username, $ignore)) {
            Request::addError('username', local('username_taken'));
        }
    }

    /**
     * Role Choices
     * @return array<int,string>
     */
    private function roleChoices(): array
    {
        $choices = [];

        foreach (Staff::roles() as $role) {
            $choices[(int) $role['role_id']] = (string) $role['role_name'];
        }

        return $choices;
    }
}
