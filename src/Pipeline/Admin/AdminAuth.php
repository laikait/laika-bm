<?php
/**
 * Laika PHP MVC Framework
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: MIT
 * This file is part of the Laika PHP MVC Framework.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

// Namespace
namespace LBM\Pipeline\Admin;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Laika\Route\Interfaces\PipelineInterface;
use Laika\Auth\Guards\TokenGuard;
use LBM\Model\PasswordModel;
use LBM\Model\LoginLogModel;
use Laika\Auth\AuthManager;
use Laika\Service\Redirect;
use Laika\Session\Session;
use Laika\Service\Request;
use LBM\Model\StaffModel;
use Laika\Service\Vault;
use Laika\Service\CSRF;
use LANG;

class AdminAuth implements PipelineInterface
{
    /** @var TokenGuard */
    protected TokenGuard $guard;

    public function __construct()
    {
        $this->guard = (new AuthManager(config('auth')))->guard('staff');
    }

    /**
     * @param callable $next
     * @param array $params
     */
    public function handle(callable $next, array &$params): ?string
    {
        $token = Session::get(ADMIN . '_token', for:ADMIN);

        $info = $this->guard->validateToken($token, (int) option('login_lifetime', 3600), option_bool('strict_ip'));

        if (match_url('staff.login')) {

            // Redirect To Dashboard if Loggedin & in Login Url
            if (!empty($info)) {
                alert_set(LANG::$authenticated, true);
                Redirect::to('staff.dashboard');
            }

            // Process Login. Stop Pipeline Chains if 
            if (!$this->processLogin()) return $next(false);

            // Redirect to Dashboard
            Redirect::to('staff.dashboard');
        } else {
            if (empty($info)) {
                // Redirect to Login
                alert_set(LANG::$unauthenticated, false);
                Session::pop(ADMIN . '_token', ADMIN);
                Redirect::to('staff.login');
            }
        }

        return $next();
    }

    /**
     * Process Login
     */
    private function processLogin(): bool
    {
        if (!Request::isPost()) return false;

        // Process Login
        $rules = [
            'username'  =>  'required',
            'password'  =>  'required',
        ];

        $message = [
            'username.required' =>  LANG::$requiredField,
            'password.required' =>  LANG::$requiredField
        ];

        // Validate Required Inputs
        Request::validate($rules, $message);

        // Return if Form Has Errors
        if (!empty(Request::errors())) return false;

        // Check Staff Exists
        $sm = new StaffModel();
        $pm = new PasswordModel();

        $staff = $sm->select(['sid', 'first_name', 'last_name'])
                ->whereGroup(function (StaffModel $wg) {
                    $wg->where(['username' => Request::input('username'), 'email' => Request::input('username')], '=', 'OR');
                })
                ->where(['is_restricted' => 'no'])
                ->first();

        // Check Staff Exists
        if (empty($staff)) {
            alert_set(LANG::$invalidUser, false);
            return false;
        }

        // Get User Password
        $pass = $pm->select('hash')
                    ->where(['rel_id' => $staff['sid'], 'rel_type' => 'staff'])
                    ->isNull('revoked_at')
                    ->first();

        // Check Has Password
        if (!isset($pass['hash'])) {
            alert_set(LANG::$invalidUser, false);
            return false;
        }

        if (!Vault::verifyPassword(Request::input('password'), $pass['hash'])) {
            alert_set(LANG::$invalidUser, false);
            return false;
        }

        try {
            // Revoke All Previous Tokens
            $this->guard->revokeAllForUser($staff['sid']);
            // Issue Token
            $res = $this->guard->issueToken($staff['sid'], (int) option('login_lifetime', '3600'));
            // Set Session Token
            Session::set(ADMIN . '_token', $res['token'], ADMIN);

            // Log Login
            (new LoginLogModel())->createLog($staff['sid'], 'staff');
        } catch (\Throwable $th) {
            if (DEBUG) throw $th;
            return false;
        }

        alert_set(sprintf(LANG::$welcome, $staff['first_name']), true);

        return true;
    }
}
