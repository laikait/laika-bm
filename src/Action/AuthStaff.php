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

namespace LBM\Action;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use RuntimeException;
use Laika\Model\Model;
use Laika\Service\Visitor;
use LBM\Model\StaffModel;
use LBM\Model\LoginLogModel;
use LBM\Pipeline\Auth;
use LBM\Support\LoginThrottle;
use LBM\Support\PasswordValidator;

/**
 * Signing staff in and out of the admin area.
 *
 * The pipeline owns the token lifecycle; this owns the decision. attempt()
 * verifies the credential, checks the account is allowed in, and only then asks
 * Auth::login() for a token.
 *
 * Every failure returns the same message. Saying "no such user" tells anybody
 * who asks which usernames exist, which is the first half of a password attack;
 * saying "wrong password" tells them they have the other half right. Both are
 * "Those details are not correct." The reason is recorded internally so an
 * operator reading the log can still tell what happened.
 */
class AuthStaff extends Action
{
    /** @var string What Every Failed Sign-In Says */
    public const FAILURE = 'Those details are not correct.';

    /** @var string The Account Exists But Is Not Allowed In */
    public const BLOCKED = 'This account is not active. Please contact an administrator.';

    /** @var string Too Many Failures - sprintf() With Minutes (Phase 52) */
    public const THROTTLED = AuthClient::THROTTLED;

    /** @var string What a Reset Request Always Says (Phase 52) */
    public const RESET_SENT = AuthClient::RESET_SENT;

    /** @var string[] The Only Account Type an Admin Reset Link May Belong To */
    private const RESET_TYPES = [PasswordValidator::STAFF];

    public function model(): Model
    {
        return new StaffModel();
    }

    ####################################################################################
    /*================================= EXTERNAL API =================================*/
    ####################################################################################

    /**
     * Try To Sign a Staff Member In
     *
     * Phase 52: throttled, the same way as AuthClient::attempt() - a locked
     * account or address is refused before any password is checked, and only a
     * wrong password counts as a failure.
     * @param string $identifier Username Or Email
     * @param string $password Plain Password
     * @return array{ok:bool,staff:?array,error:?string,retry_after?:int}
     */
    public function attempt(string $identifier, string $password): array
    {
        $throttle = new LoginThrottle();
        $wait = $throttle->lockedFor(ADMIN, $identifier);

        if ($wait > 0) {
            return $this->failure(sprintf(self::THROTTLED, (int) ceil($wait / 60))) + ['retry_after' => $wait];
        }

        $result = $this->check($identifier, $password);

        if ($result['ok']) {
            $throttle->succeeded(ADMIN, $identifier);
        } elseif ($result['error'] === self::FAILURE) {
            $throttle->failed(ADMIN, $identifier);
        }

        return $result;
    }

    ####################################################################################
    /*=============================== PASSWORD RESET =================================*/
    ####################################################################################

    /**
     * Start a Staff Password Reset - Phase 52
     *
     * Until now a staff member who forgot their password needed another
     * administrator, and the only administrator had no way back in at all short
     * of editing the database. Mirrors AuthClient::forgot(): the same answer
     * whether or not the address exists, and a throttle on how often it mails.
     *
     * An account that may not sign in gets no link. A reset would only lead to
     * a sign-in that is refused, and it would tell whoever asked that the
     * suspended account exists.
     * @param string $email Email Address
     * @return string The message to show
     */
    public function forgot(string $email): string
    {
        $email = strtolower(trim($email));

        if (!(new LoginThrottle())->allowReset(ADMIN, $email)) {
            return self::RESET_SENT;
        }

        $staff = new Staff();
        $row = $staff->findByLogin($email);

        // Email only: findByLogin() matches usernames too, and a username typed
        // here must not send mail to the address behind it.
        if ($row === null || strtolower((string) $row['email']) !== $email || !$staff->canSignIn($row)) {
            return self::RESET_SENT;
        }

        $token = (new PasswordReset())->issue((int) $row['sid'], PasswordValidator::STAFF);
        $host = rtrim((string) option('app_host', ''), '/');

        try {
            (new Mail())->queueTemplate('password-reset', (string) $row['email'], [
                'first_name' =>  $row['first_name'] ?? '',
                'last_name'  =>  $row['last_name'] ?? '',
                'reset_url'  =>  "{$host}/" . ADMIN . "/reset-password/{$token}",
                'expires_in' =>  (string) (int) (PasswordReset::TTL / 60) . ' minutes',
            ]);
        } catch (RuntimeException $e) {
            // Swallowed but recorded, for the reason AuthClient::notify() gives.
            (new Activity())->record(
                'mail.template.missing',
                'Could not send the [password-reset] email to ' . $row['email'] . ': ' . $e->getMessage()
            );
        }

        return self::RESET_SENT;
    }

    /**
     * The Live Staff Reset Row For a Token, Or Null
     * @param string $token Plain Token
     * @return ?array
     */
    public function findReset(string $token): ?array
    {
        return (new PasswordReset())->lookup($token, self::RESET_TYPES);
    }

    /**
     * Finish a Staff Password Reset
     *
     * Every device the account was signed in on is signed out: somebody who
     * resets because the account was taken over expects the intruder gone.
     * @param string $token Plain Token
     * @param string $password New Password
     * @param ?string $confirm Confirmation
     * @return array{ok:bool,errors:string[]}
     */
    public function reset(string $token, string $password, ?string $confirm = null): array
    {
        $row = $this->findReset($token);

        if ($row === null) {
            return ['ok' => false, 'errors' => ['That reset link has expired or has already been used.']];
        }

        $errors = (new PasswordValidator())->validate($password, $confirm);

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $staffId = (int) $row['rel_id'];

        (new PasswordReset())->consume($row, $password);
        Auth::revokeAll(ADMIN, $staffId);

        (new Activity())->record(
            'staff.password.reset',
            'Reset their password with an emailed link.',
            Activity::STAFF,
            $staffId
        );

        return ['ok' => true, 'errors' => []];
    }

    ####################################################################################
    /*=================================== ACCOUNT ====================================*/
    ####################################################################################

    /**
     * Sign The Current Staff Member Out
     * @return void
     */
    public function logout(): void
    {
        $staff = Auth::user(ADMIN);

        if ($staff !== null) {
            (new Activity())->record(
                'staff.logout',
                'Signed out of the admin area.',
                Activity::STAFF,
                (int) ($staff['sid'] ?? 0) ?: null
            );
        }

        Auth::logout(ADMIN);
    }

    /**
     * Sign a Staff Member Out Of Every Device
     *
     * Revokes every auth_tokens row for the account, not only this session's -
     * which is what somebody clicking it after losing a laptop actually wants.
     * @param int $staffId Staff ID
     * @return void
     */
    public function logoutEverywhere(int $staffId): void
    {
        Auth::logoutEverywhere(ADMIN, $staffId);

        (new Activity())->record(
            'staff.sessions.revoke',
            'Signed out of every device.',
            Activity::STAFF,
            $staffId
        );
    }

    /**
     * Change The Signed-In Staff Member's Own Password
     *
     * The current password is required even though they are already signed in:
     * it is what stops an unattended browser from becoming a permanent takeover.
     * @param int $staffId Staff ID
     * @param string $current Current Password
     * @param string $new New Password
     * @param ?string $confirm Confirmation
     * @return array{ok:bool,errors:string[]}
     */
    public function changePassword(int $staffId, string $current, string $new, ?string $confirm = null): array
    {
        $passwords = new PasswordValidator();

        if (!$passwords->verify($current, $passwords->current($staffId, PasswordValidator::STAFF))) {
            return ['ok' => false, 'errors' => ['Your current password is not correct.']];
        }

        $errors = $passwords->validate($new, $confirm);

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $passwords->put($staffId, PasswordValidator::STAFF, $new);

        (new Activity())->record(
            'staff.password.change',
            'Changed their password.',
            Activity::STAFF,
            $staffId
        );

        return ['ok' => true, 'errors' => []];
    }

    /**
     * The Sign-In History For One Staff Member
     * @param int $staffId Staff ID
     * @param int $limit Row Limit
     * @return array
     */
    public function history(int $staffId, int $limit = 10): array
    {
        $model = new LoginLogModel();

        return $model->where(['rel_type' => PasswordValidator::STAFF, 'rel_id' => $staffId])
            ->order($model->id, self::DESC)
            ->limit($limit)
            ->get();
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Check The Credentials And Sign In On a Match
     * @param string $identifier Username Or Email
     * @param string $password Plain Password
     * @return array{ok:bool,staff:?array,error:?string}
     */
    private function check(string $identifier, string $password): array
    {
        $staff = new Staff();
        $row = $staff->findByLogin($identifier);

        $hash = $row === null
            ? null
            : (new PasswordValidator())->current((int) $row['sid'], PasswordValidator::STAFF);

        // verify() hashes the input even when there is no stored hash, so a
        // username that does not exist takes the same time as one that does and
        // the response cannot be used to enumerate accounts.
        if (!(new PasswordValidator())->verify($password, $hash)) {
            return $this->failure(self::FAILURE);
        }

        if (!$staff->canSignIn($row)) {
            return $this->failure(self::BLOCKED, $row);
        }

        $id = (int) $row['sid'];

        Auth::login(ADMIN, $id);

        $staff->touchLogin($id, $this->ip());
        $this->log($id);

        (new Activity())->record(
            'staff.login',
            'Signed in to the admin area.',
            Activity::STAFF,
            $id
        );

        return ['ok' => true, 'staff' => $row, 'error' => null];
    }

    /**
     * Record a Successful Sign-In In login_logs
     *
     * Delegated to the model so staff, clients and contacts all write the same
     * shape of row - including the uid, which the column requires and which is
     * easy to leave out when the write is copied into a third place.
     * @param int $staffId Staff ID
     * @return void
     */
    private function log(int $staffId): void
    {
        (new LoginLogModel())->createLog($staffId, PasswordValidator::STAFF);
    }

    /**
     * Build a Failure Result
     * @param string $message Message
     * @param ?array $staff Staff Row, When One Was Found
     * @return array{ok:bool,staff:?array,error:string}
     */
    private function failure(string $message, ?array $staff = null): array
    {
        return ['ok' => false, 'staff' => $staff, 'error' => $message];
    }

    /**
     * The Visitor's IP, Or a Placeholder
     *
     * The column is NOT NULL, and a CLI or queue context has no IP at all.
     * @return string
     */
    private function ip(): string
    {
        $ip = Visitor::ip();

        return is_string($ip) && $ip !== '' ? $ip : 'unknown';
    }
}
