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

namespace LBM\Controller\Client;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Laika\Service\Request;
use Laika\Service\Redirect;
use Laika\Core\Exceptions\HttpException;
use LBM\Controller\Controller;
use LBM\Pipeline\Auth;
use LBM\Service\AuthClient;
use LBM\Service\Country;

/**
 * Signing clients and their sub-logins in and out.
 *
 * Registered outside the guarded route group - a login page behind the auth
 * pipeline would redirect to itself forever.
 *
 * Extends the plain Controller rather than ClientController: there is nobody
 * signed in to put in the topbar and no account to scope anything to. The auth
 * layout is a centred card with none of that.
 *
 * Everything on this screen is written so that an unauthenticated visitor
 * cannot learn which email addresses have accounts. Sign-in gives one message
 * whichever half was wrong; a reset request gives the same answer whether or
 * not the address exists; registration is the one place that has to say an
 * address is taken, and it is gated behind an option for exactly that reason.
 */
class AuthController extends Controller
{
    /**
     * The Client Area Template, From The Operator's Settings
     *
     * Pinned to PANEL rather than left to the current request: this controller
     * only ever renders client screens, and current_template() reads the URL.
     * @return string Example: 'panel/bootstrap'
     */
    protected function theme(): string
    {
        return template_dir(PANEL);
    }

    ####################################################################################
    /*=================================== SIGN IN ====================================*/
    ####################################################################################

    /**
     * Sign In
     *
     * GET renders the form, POST tries the credentials (instructions 16, 17).
     * @return ?string
     */
    public function login(): ?string
    {
        if (Auth::check(PANEL)) {
            Redirect::to('client.dashboard');

            return null;
        }

        if (Request::isPost()) {
            $input = Request::inputs();

            $identifier = trim((string) ($input['username'] ?? ''));
            $password = (string) ($input['password'] ?? '');

            if ($identifier === '') {
                Request::addError('username', local('enter_username_or_email'));
            }

            if ($password === '') {
                Request::addError('password', local('enter_password'));
            }

            if (Request::errors() === []) {
                $result = AuthClient::attempt($identifier, $password);

                if ($result['ok']) {
                    Redirect::with(local('signed_in'), true)->to('client.dashboard');

                    return null;
                }

                // On the form rather than against a field: which half was wrong
                // is exactly what a failed sign-in must not reveal.
                Request::addError('form', (string) $result['error']);
            }
        }

        return $this->render('login', [
            'page_title'   =>  local('sign_in'),
            'registration' =>  option_bool('allow_registration'),
        ]);
    }

    /**
     * Sign Out
     *
     * POST only - a GET logout can be fired by any image tag on any page the
     * client visits, signing them out at somebody else's choosing.
     * @return ?string
     */
    public function logout(): ?string
    {
        AuthClient::logout();

        Redirect::with(local('signed_out'), true)->to('client.login');

        return null;
    }

    ####################################################################################
    /*=============================== PASSWORD RESET =================================*/
    ####################################################################################

    /**
     * Ask For a Reset Link
     *
     * The answer is the same whether or not the address belongs to an account.
     * A form that said "no account with that address" is an enumeration oracle
     * anybody can query at will, and the action returns one message for both
     * cases so this screen has no way to say otherwise.
     * @return ?string
     */
    public function forgot(): ?string
    {
        if (Auth::check(PANEL)) {
            Redirect::to('client.dashboard');

            return null;
        }

        if (Request::isPost()) {
            $email = trim((string) Request::input('email', ''));

            if ($email === '') {
                Request::addError('email', local('enter_account_email'));
            } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                Request::addError('email', local('not_an_email_address'));
            }

            if (Request::errors() === []) {
                Redirect::with(AuthClient::forgot($email), true)->to('client.forgot');

                return null;
            }
        }

        return $this->render('forgot', [
            'page_title' =>  local('reset_your_password'),
        ]);
    }

    /**
     * Set a New Password From a Reset Link
     *
     * The token is checked before the form is even drawn, so somebody following
     * an expired link is told so rather than typing a new password twice and
     * only then being refused.
     * @param string $token Reset Token
     * @return ?string
     */
    public function reset(string $token): ?string
    {
        if (AuthClient::findReset($token) === null) {
            throw new HttpException(410, local('reset_link_expired'));
        }

        if (Request::isPost()) {
            $input = Request::inputs();

            $result = AuthClient::reset(
                $token,
                (string) ($input['password'] ?? ''),
                $input['password_confirm'] ?? null
            );

            if ($result['ok']) {
                Redirect::with(local('password_changed_sign_in'), true)
                    ->to('client.login');

                return null;
            }

            foreach ($result['errors'] as $error) {
                Request::addError('form', $error);
            }
        }

        return $this->render('reset', [
            'page_title' =>  local('choose_a_new_password'),
            'token'      =>  $token,
        ]);
    }

    ####################################################################################
    /*================================= REGISTRATION =================================*/
    ####################################################################################

    /**
     * Open An Account
     *
     * Gated by the allow_registration option: plenty of installations sell only
     * through staff and want no public sign-up at all. When it is off this is a
     * 404 rather than a message, because a switched-off feature should not be
     * discoverable.
     * @return ?string
     */
    public function register(): ?string
    {
        if (!option_bool('allow_registration')) {
            throw new HttpException(404, local('registration_not_open'));
        }

        if (Auth::check(PANEL)) {
            Redirect::to('client.dashboard');

            return null;
        }

        if (Request::isPost()) {
            $input = Request::inputs();

            $result = AuthClient::register($input, (string) ($input['password'] ?? ''));

            if ($result['ok']) {
                Redirect::with(local('account_ready'), true)
                    ->to('client.login');

                return null;
            }

            foreach ($result['errors'] as $error) {
                Request::addError('form', $error);
            }
        }

        return $this->render('register', [
            'page_title' =>  local('create_an_account'),
            'countries'  =>  Country::choices(),
        ]);
    }
}
