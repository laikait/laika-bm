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
use Laika\Service\Redirect;
use LBM\Controller\Controller;
use LBM\Pipeline\Auth;
use LBM\Service\AuthStaff;

/**
 * Signing staff in and out.
 *
 * Registered outside the guarded route group - a login page behind the auth
 * pipeline would redirect to itself forever.
 *
 * Extends the plain Controller rather than AdminController: there is no signed
 * in staff member to put in the topbar, no sidebar to mark active, and no
 * permission to check. The auth layout is a centred card with none of that.
 */
class AuthController extends Controller
{
    /**
     * The Admin Template, From The Operator's Settings
     *
     * Pinned to ADMIN rather than left to the current request: this controller
     * only ever renders admin screens, and current_template() reads the URL.
     * @return string Example: 'admin/bootstrap'
     */
    protected function theme(): string
    {
        return template_dir(ADMIN);
    }

    ####################################################################################
    /*================================= EXTERNAL API =================================*/
    ####################################################################################

    /**
     * Sign In
     *
     * GET renders the form, POST tries the credentials (instructions 16, 17).
     * @return ?string
     */
    public function login(): ?string
    {
        // Already signed in - send them where they were going rather than
        // showing a login form to somebody who is demonstrably logged in.
        if (Auth::check(ADMIN)) {
            Redirect::to('staff.dashboard');

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
                $result = AuthStaff::attempt($identifier, $password);

                if ($result['ok']) {
                    Redirect::with(local('signed_in'), true)->to('staff.dashboard');

                    return null;
                }

                // On the form rather than against a field: which half was wrong
                // is exactly what a failed sign-in must not reveal.
                Request::addError('form', (string) $result['error']);
            }
        }

        return $this->render('login', [
            'page_title' =>  local('sign_in'),
        ]);
    }

    /**
     * Sign Out
     *
     * POST only - a GET logout can be fired by any image tag on any page the
     * staff member visits, logging them out at somebody else's choosing.
     * @return ?string
     */
    public function logout(): ?string
    {
        AuthStaff::logout();

        Redirect::with(local('signed_out'), true)->to('staff.login');

        return null;
    }
}
