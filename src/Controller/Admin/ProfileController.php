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
use LBM\Service\Staff;

/**
 * The signed-in staff member's own account.
 *
 * Registered with no permission pipeline: everybody may manage their own
 * account whatever their role grants them over other people's. That is also
 * why this screen deliberately cannot change a role or a status - those are
 * decisions about somebody, not by them, and letting a limited account edit its
 * own role here would make the whole permission system decorative.
 */
class ProfileController extends AdminController
{
    /** @var string[] What Somebody May Change About Themselves */
    private const FIELDS = ['first_name', 'middle_name', 'last_name', 'username', 'email'];

    protected function nav(): string
    {
        return 'account';
    }

    /**
     * My Account
     * @return string
     */
    public function index(): string
    {
        $me = $this->me();

        return $this->screen('account', local('my_account'), [
            'member'  =>  $me,
            'role'    =>  Staff::role((int) $me['role_relid']),
            'history' =>  AuthStaff::history((int) $me['sid'], 10),
            'trail'   =>  Activity::forStaff((int) $me['sid'], 10)['rows'],
        ]);
    }

    /**
     * Update My Details
     * @return ?string
     */
    public function update(): ?string
    {
        $me = $this->me();
        $id = (int) $me['sid'];

        // Only the fields somebody may change about themselves. Anything else
        // in the submission - role_relid, status_relid - is dropped rather than
        // saved, so a crafted form cannot promote its own account.
        $input = array_intersect_key(Request::inputs(), array_flip(self::FIELDS));

        $this->require([
            'first_name' =>  local('first_name_required'),
            'last_name'  =>  local('last_name_required'),
            'email'      =>  local('email_required'),
        ], $input);

        $this->requireEmail('email', $input);

        $email = trim((string) ($input['email'] ?? ''));

        if ($email !== '' && Staff::emailTaken($email, $id)) {
            Request::addError('email', local('staff_email_taken'));
        }

        $username = trim((string) ($input['username'] ?? ''));

        if ($username !== '' && Staff::usernameTaken($username, $id)) {
            Request::addError('username', local('username_taken'));
        }

        if (Request::errors() !== []) {
            return $this->index();
        }

        $changes = Activity::changes($me, $input);

        Staff::modify($id, $input);

        $this->log('staff.profile.updated', 'Updated their own details.', $changes);

        return $this->done('staff.account', local('your_details_saved'));
    }

    /**
     * Change My Password
     * @return ?string
     */
    public function password(): ?string
    {
        $me = $this->me();
        $input = Request::inputs();

        $result = AuthStaff::changePassword(
            (int) $me['sid'],
            (string) ($input['current_password'] ?? ''),
            (string) ($input['password'] ?? ''),
            $input['password_confirm'] ?? null
        );

        if (!$result['ok']) {
            return $this->done('staff.account', $result['errors'][0], false);
        }

        return $this->done('staff.account', local('your_password_changed'));
    }

    /**
     * Sign Out Of Every Device
     *
     * Revokes every auth_tokens row for this account, including the one this
     * request is using - which is the point. Somebody clicking it has usually
     * just lost a laptop.
     * @return ?string
     */
    public function revokeSessions(): ?string
    {
        $me = $this->me();

        AuthStaff::logoutEverywhere((int) $me['sid']);

        return $this->done('staff.login', local('signed_out_everywhere_msg'));
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * The Signed-In Staff Member, Freshly Read
     *
     * The pipeline's copy came from the token guard and is enough to know who
     * is asking; this reads the row so the form shows what is actually stored.
     * @return array
     */
    private function me(): array
    {
        return $this->record(Staff::find($this->staffId()), 'staff member');
    }
}
