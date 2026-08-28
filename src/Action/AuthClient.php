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

namespace LBM\Action;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use RuntimeException;
use Laika\Model\Model;
use Laika\Service\Visitor;
use LBM\Model\ClientModel;
use LBM\Model\LoginLogModel;
use LBM\Model\PasswordResetModel;
use LBM\Pipeline\Auth;
use LBM\Support\PasswordValidator;
use LBM\Support\Uid;

/**
 * Signing clients and their contacts in and out of the client area.
 *
 * Two guards land in the same area. A client signs in as themselves; a client
 * contact signs in as a sub-login of a client, and everything they can reach is
 * their parent client's. Which of the two is looking is recorded in the session
 * by the pipeline, so a screen can ask with Auth::guardOf(PANEL).
 *
 * A client is tried first and a contact second. The two are separate tables and
 * an address can legitimately appear in both - the person who owns the account
 * and is also listed as its billing contact - and in that case the account
 * itself is the one they mean.
 *
 * Failures say the same thing whatever went wrong, for the reason set out in
 * LBM\Action\AuthStaff.
 */
class AuthClient extends Action
{
    /** @var string What Every Failed Sign-In Says */
    public const FAILURE = 'Those details are not correct.';

    /** @var string The Account Exists But Is Not Allowed In */
    public const BLOCKED = 'This account is not active. Please contact support.';

    /** @var string What a Reset Request Always Says */
    public const RESET_SENT = 'If that address belongs to an account, a reset link is on its way.';

    /** @var int How Long a Reset Link Lasts, In Seconds */
    public const RESET_TTL = 3600;

    public function model(): Model
    {
        return new ClientModel();
    }

    ####################################################################################
    /*=================================== SIGN IN ====================================*/
    ####################################################################################

    /**
     * Try To Sign a Client Or Contact In
     *
     * @param string $identifier Username Or Email
     * @param string $password Plain Password
     * @return array{ok:bool,client:?array,contact:?array,guard:?string,error:?string}
     */
    public function attempt(string $identifier, string $password): array
    {
        $passwords = new PasswordValidator();
        $clients = new Client();

        $client = $clients->findByLogin($identifier);

        if ($client !== null) {
            $hash = $passwords->current((int) $client['cid'], PasswordValidator::CLIENT);

            if ($passwords->verify($password, $hash)) {
                return $this->signInClient($client, $clients);
            }
        }

        $contacts = new ClientContact();
        $contact = $contacts->findByLogin($identifier);

        if ($contact !== null && !empty($contact['username'])) {
            $hash = $passwords->current((int) $contact['cc_id'], PasswordValidator::CONTACT);

            if ($passwords->verify($password, $hash)) {
                return $this->signInContact($contact, $clients);
            }
        }

        // Nothing matched. Hash anyway so a miss costs the same as a wrong
        // password and the timing cannot be used to enumerate accounts.
        if ($client === null && $contact === null) {
            $passwords->verify($password, null);
        }

        return [
            'ok'      =>  false,
            'client'  =>  null,
            'contact' =>  null,
            'guard'   =>  null,
            'error'   =>  self::FAILURE,
        ];
    }

    /**
     * Sign The Current Client Or Contact Out
     * @return void
     */
    public function logout(): void
    {
        $user = Auth::user(PANEL);
        $guard = Auth::guardOf(PANEL);

        if ($user !== null) {
            $id = (int) ($user['cid'] ?? $user['cc_id'] ?? 0);

            (new Activity())->record(
                'client.logout',
                'Signed out of the client area.',
                $guard === Auth::CONTACT ? Activity::CONTACT : Activity::CLIENT,
                $id ?: null
            );
        }

        Auth::logout(PANEL);
    }

    ####################################################################################
    /*================================= REGISTRATION =================================*/
    ####################################################################################

    /**
     * Register a New Client
     *
     * Gated by the allow_registration option: a good many installations sell
     * only through staff and want no public sign-up at all.
     * @param array $input Submitted Data
     * @param string $password Plain Password
     * @return array{ok:bool,client_id:?int,errors:string[]}
     */
    public function register(array $input, string $password): array
    {
        if (!option_bool('allow_registration')) {
            return ['ok' => false, 'client_id' => null, 'errors' => ['Registration is closed.']];
        }

        $clients = new Client();
        $email = strtolower(trim((string) ($input['email'] ?? '')));

        $errors = [];

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'That does not look like an email address.';
        } elseif ($clients->emailTaken($email)) {
            $errors[] = 'An account already uses that email address.';
        }

        if (trim((string) ($input['first_name'] ?? '')) === '') {
            $errors[] = 'A first name is required.';
        }

        if (trim((string) ($input['last_name'] ?? '')) === '') {
            $errors[] = 'A last name is required.';
        }

        $username = trim((string) ($input['username'] ?? ''));

        if ($username !== '' && $clients->usernameTaken($username)) {
            $errors[] = 'That username is taken.';
        }

        $errors = array_merge($errors, (new PasswordValidator())->validate(
            $password,
            $input['password_confirm'] ?? null
        ));

        if ($errors !== []) {
            return ['ok' => false, 'client_id' => null, 'errors' => $errors];
        }

        $id = $clients->store($input, $password);

        (new Activity())->record(
            'client.registered',
            'Registered a new account.',
            Activity::CLIENT,
            $id
        );

        $this->notify('client-welcome', $email, $id, [
            'first_name' =>  $input['first_name'] ?? '',
            'last_name'  =>  $input['last_name'] ?? '',
            'email'      =>  $email,
        ]);

        return ['ok' => true, 'client_id' => $id, 'errors' => []];
    }

    ####################################################################################
    /*=============================== PASSWORD RESET =================================*/
    ####################################################################################

    /**
     * Start a Password Reset
     *
     * Always reports the same thing, whether or not the address exists. A form
     * that says "no account with that address" is an account-enumeration oracle
     * anybody can query at will.
     * @param string $email Email Address
     * @return string The message to show
     */
    public function forgot(string $email): string
    {
        $email = strtolower(trim($email));
        $client = (new Client())->findByLogin($email);

        if ($client === null) {
            return self::RESET_SENT;
        }

        $token = $this->issueReset((int) $client['cid'], PasswordValidator::CLIENT);

        $this->notify('password-reset', (string) $client['email'], (int) $client['cid'], [
            'first_name' =>  $client['first_name'] ?? '',
            'last_name'  =>  $client['last_name'] ?? '',
            'reset_url'  =>  $this->resetUrl($token),
            'expires_in' =>  (string) (int) (self::RESET_TTL / 60) . ' minutes',
        ]);

        return self::RESET_SENT;
    }

    /**
     * Whether a Reset Token Is Still Good
     * @param string $token Plain Token
     * @return ?array The reset row, or null
     */
    public function findReset(string $token): ?array
    {
        $token = trim($token);

        if ($token === '') {
            return null;
        }

        $model = new PasswordResetModel();

        $row = $model->where(['token' => $this->hashToken($token)])
            ->isNull('used_at')
            ->where(['expires_at' => date('Y-m-d H:i:s')], '>')
            ->first();

        return is_array($row) ? $row : null;
    }

    /**
     * Finish a Password Reset
     *
     * Marking the row used and writing the new password happen together: a reset
     * link that still worked after being used would be a second key to the
     * account sitting in somebody's inbox.
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

        $passwords = new PasswordValidator();
        $errors = $passwords->validate($password, $confirm);

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $relId = (int) $row['rel_id'];
        $relType = (string) $row['rel_type'];

        (new PasswordResetModel())->transaction(
            function (PasswordResetModel $m) use ($row, $relId, $relType, $password, $passwords): void {
                $m->where([$m->id => (int) $row['reset_id']])->update(['used_at' => $this->now()]);

                $passwords->put($relId, $relType, $password);
            }
        );

        // Any other outstanding link for the same account is now stale - the
        // password it was issued against no longer exists.
        $this->revokeResets($relId, $relType);

        (new Activity())->record(
            'client.password.reset',
            'Reset their password with an emailed link.',
            $relType === PasswordValidator::CONTACT ? Activity::CONTACT : Activity::CLIENT,
            $relId
        );

        return ['ok' => true, 'errors' => []];
    }

    /**
     * Change The Signed-In Client's Own Password
     * @param int $clientId Client ID
     * @param string $current Current Password
     * @param string $new New Password
     * @param ?string $confirm Confirmation
     * @param string $relType client or contact
     * @return array{ok:bool,errors:string[]}
     */
    public function changePassword(
        int $clientId,
        string $current,
        string $new,
        ?string $confirm = null,
        string $relType = PasswordValidator::CLIENT
    ): array {
        $passwords = new PasswordValidator();

        if (!$passwords->verify($current, $passwords->current($clientId, $relType))) {
            return ['ok' => false, 'errors' => ['Your current password is not correct.']];
        }

        $errors = $passwords->validate($new, $confirm);

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $passwords->put($clientId, $relType, $new);

        (new Activity())->record(
            'client.password.changed',
            'Changed their password.',
            $relType === PasswordValidator::CONTACT ? Activity::CONTACT : Activity::CLIENT,
            $clientId
        );

        return ['ok' => true, 'errors' => []];
    }

    /**
     * Drop Every Outstanding Reset For An Account
     * @param int $relId Client/Contact ID
     * @param string $relType client or contact
     * @return int Affected rows
     */
    public function revokeResets(int $relId, string $relType): int
    {
        return (new PasswordResetModel())
            ->where(['rel_id' => $relId, 'rel_type' => $relType])
            ->isNull('used_at')
            ->update(['used_at' => $this->now()]);
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Complete a Client Sign-In
     * @param array $client Client Row
     * @param Client $clients Client Action
     * @return array{ok:bool,client:?array,contact:?array,guard:?string,error:?string}
     */
    private function signInClient(array $client, Client $clients): array
    {
        if (!$clients->canSignIn($client)) {
            return [
                'ok'      =>  false,
                'client'  =>  $client,
                'contact' =>  null,
                'guard'   =>  null,
                'error'   =>  self::BLOCKED,
            ];
        }

        $id = (int) $client['cid'];

        Auth::login(PANEL, $id, Auth::CLIENT);

        $clients->touchLogin($id, $this->ip());
        $this->log($id, PasswordValidator::CLIENT);

        (new Activity())->record(
            'client.login',
            'Signed in to the client area.',
            Activity::CLIENT,
            $id
        );

        return [
            'ok'      =>  true,
            'client'  =>  $client,
            'contact' =>  null,
            'guard'   =>  Auth::CLIENT,
            'error'   =>  null,
        ];
    }

    /**
     * Complete a Contact Sign-In
     *
     * A contact inherits their parent client's ability to sign in: suspending a
     * client has to lock out its sub-logins too, or the suspension means nothing.
     * @param array $contact Contact Row
     * @param Client $clients Client Action
     * @return array{ok:bool,client:?array,contact:?array,guard:?string,error:?string}
     */
    private function signInContact(array $contact, Client $clients): array
    {
        $client = $clients->find((int) $contact['client_relid']);

        if ($client === null || !$clients->canSignIn($client)) {
            return [
                'ok'      =>  false,
                'client'  =>  $client,
                'contact' =>  $contact,
                'guard'   =>  null,
                'error'   =>  self::BLOCKED,
            ];
        }

        $id = (int) $contact['cc_id'];

        Auth::login(PANEL, $id, Auth::CONTACT);

        $this->log($id, PasswordValidator::CONTACT);

        (new Activity())->record(
            'contact.login',
            'Signed in to the client area as a sub-login.',
            Activity::CONTACT,
            $id
        );

        return [
            'ok'      =>  true,
            'client'  =>  $client,
            'contact' =>  $contact,
            'guard'   =>  Auth::CONTACT,
            'error'   =>  null,
        ];
    }

    /**
     * Issue a Reset Token
     *
     * Returns the plain token; only its hash is stored. Any earlier outstanding
     * link is retired first, so asking twice does not leave two working keys.
     * @param int $relId Client/Contact ID
     * @param string $relType client or contact
     * @return string The plain token
     */
    private function issueReset(int $relId, string $relType): string
    {
        $this->revokeResets($relId, $relType);

        $token = bin2hex(random_bytes(32));
        $model = new PasswordResetModel();

        $model->insert([
            $model->uid  =>  Uid::make(),
            'rel_id'     =>  $relId,
            'rel_type'   =>  $relType,
            'token'      =>  $this->hashToken($token),
            'ip'         =>  $this->ip(),
            'expires_at' =>  date('Y-m-d H:i:s', time() + self::RESET_TTL),
            'created_at' =>  $this->now(),
        ]);

        return $token;
    }

    /**
     * Hash a Reset Token For Storage
     * @param string $token Plain Token
     * @return string
     */
    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Build The Link That Goes In The Reset Email
     * @param string $token Plain Token
     * @return string
     */
    private function resetUrl(string $token): string
    {
        $host = rtrim((string) option('app_host', ''), '/');

        return "{$host}/" . PANEL . "/reset-password/{$token}";
    }

    /**
     * Queue a Notification, Without Letting It Break The Thing It Reports
     *
     * A missing or switched-off template must not stop somebody registering or
     * resetting their password - the account work has already succeeded by the
     * time this runs.
     * @param string $slug Template Slug
     * @param string $to Recipient Address
     * @param ?int $clientId Client ID
     * @param array $variables Placeholder Values
     * @return void
     */
    private function notify(string $slug, string $to, ?int $clientId, array $variables): void
    {
        try {
            (new Mail())->queueTemplate($slug, $to, $variables, $clientId);
        } catch (RuntimeException) {
            // No such template, or it is switched off. Nothing to do.
        }
    }

    /**
     * Record a Successful Sign-In In login_logs
     *
     * Delegated to the model, so this and AuthStaff write the same shape of row.
     * @param int $relId Client/Contact ID
     * @param string $relType client or contact
     * @return void
     */
    private function log(int $relId, string $relType): void
    {
        (new LoginLogModel())->createLog($relId, $relType);
    }

    /**
     * The Visitor's IP, Or a Placeholder
     * @return string
     */
    private function ip(): string
    {
        $ip = Visitor::ip();

        return is_string($ip) && $ip !== '' ? $ip : 'unknown';
    }
}
