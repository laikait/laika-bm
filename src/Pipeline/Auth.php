<?php

declare(strict_types=1);

namespace LBM\Pipeline;

use Laika\Service\Url;
use Laika\Service\Vault;
use Laika\Service\Redirect;
use Laika\Service\Request;
use Laika\Service\Visitor;
use Laika\Service\Auth as AuthCore;
use LBM\Service\Initiate;
use LBM\Model\StaffModel;
use LBM\Model\ClientModel;
use LBM\Model\LoginLogModel;
use LBM\Model\StaffRoleModel;
use LBM\Model\StaffStatusModel;
use LBM\Model\ClientStatusModel;
use Laika\Route\Interfaces\PipelineInterface;
use Laika\Route\Exceptions\PipelineException;

use LANG;
// use Laika\Core\Service\{StaffAuth, ClientAuth, Url};

final class Auth implements PipelineInterface
{
    /** Constants */
    public CONST AUTHORIZED = 1;
    public CONST UNAUTHORIZED = 2;
    public CONST INVALID_TOKEN = 3;
    public CONST INVALID_USER_AGENT = 4;
    public CONST INVALID_DEVICE = 5;
    public CONST INVALID_OS = 6;

    /** @var bool Is Admin Slug */
    private bool $admin_slug = false;

    /** @var bool Is Client Slug */
    private bool $client_slug = false;

    /** @var array Named Redirects */
    private array $redirects;

    /** @var string Current Slug */
    private string $slug;

    /** @var string Username */
    private string $username;

    /** @var string Password */
    private string $password;

    public function __construct()
    {
        // Initiate App
        Initiate::common();

        $this->slug = strtolower(Url::segment(1));
        if ($this->slug == strtolower(ADMIN)) {
            AuthCore::guard('staff');
        } elseif ($this->slug == strtolower(PANEL)) {
            AuthCore::guard('client');
        }

        $this->redirects = [
            strtolower(ADMIN)   =>  [
                'login' =>  'staff.login',
                'dash'  =>  'staff.dashboard',
            ],
            strtolower(PANEL)   =>  [
                'login' =>  'client.login',
                'dash'  =>  'client.dashboard',
            ]
        ];

        $this->username = Request::input('username', '');
        $this->password = Request::input('password', '');
    }

    /**
     * Handle Authentication & Authorization
     * @return ?string
     */
    public function handle(callable $next, array &$params): ?string
    {
        match (true) {
            match_url('staff.login')    =>  $this->processLogin(),
            match_url('client.login')   =>  $this->processLogin(),
            default                     =>  $this->validate()
        };
        return $next($params);
    }


    /**
     * Process Login
     * @return void
     */
    private function processLogin(): void
    {
        // Check Already Session Exists
        $this->checkSessionExists();

        // Check Request is Post
        if (!Request::isPost()) return;

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
        if (!empty(Request::errors())) return;

        $user = $this->getUser();

        // Validate User
        if (
            empty($user) || // Check Data is Not Empty
            ($user['status_name'] != 'active') || // Check User is Active
            !Vault::verifyPassword($this->password, $user['password']) // Match Password
        ) {
            alert_set(LANG::$invalidUser, false);
            return;
        }

        // Login
        unset($user['password']); // Unset password
        try {
            AuthCore::login($user['id'], $user);
        } catch (\Throwable $th) {
            if (DEBUG) throw new PipelineException("Login Failed! {$th->getMessage()}");
            alert_set(LANG::$generalError, false);
            return;
        }
        // Log Data
        $lmodel = new LoginLogModel();
        try {
            $data = [
                'type'          =>  AuthCore::type(),
                'relid'         =>  AuthCore::id(),
                'ip_address'    =>  Visitor::ip(),
                'browser'       =>  Visitor::browser(),
                'os'            =>  Visitor::os(),
                'user_agent'    =>  Visitor::userAgent(),
            ];
            $lmodel->transaction(function (loginlogModel $m) use ($data) {
                $m->insert($data);
            });
        } catch (\Throwable $th) {
            if (DEBUG) throw new PipelineException("Login Log Insert Failed! {$th->getMessage()}");
        }
        // Make Login Log
        Redirect::with(sprintf(LANG::$welcome, $user['first_name']), true)->to($this->redirects[$this->slug]['dash']);
    }

    /**
     * Validate Authenticated
     * @return void
     */
    private function validate(): void
    {
        $data = AuthCore::data();
        if (!$data['success'] || ($data['message'] !== AUTH::AUTHORIZED)) {
            AuthCore::logout();
            Redirect::with(LANG::$unauthenticated, false)->to($this->redirects[$this->slug]['login']);
        }
    }

    /**
     * Check Exists
     * @return void
     */
    private function checkSessionExists(): void
    {
        // Check Already Logged-in
        $data = AuthCore::data();
        if (isset($data['success'], $data['message']) && $data['success'] && $data['message'] === Auth::AUTHORIZED) {
            Redirect::with(LANG::$alreadyExists, true)->to($this->redirects[$this->slug]['dash']);
        }
        return;
    }

    /**
     * Get User From DB
     * @return array
     */
    private function getUser(): array
    {
        switch ($this->slug) {
            case strtolower(ADMIN):
                return $this->getStaff();
                break;
            
            case strtolower(PANEL):
                return $this->getClient();
                break;
            
            default:
                return [];
                break;
        }
    }

    /**
     * Get Staff
     * @return array
     */
    private function getStaff()
    {
        $smodel     =   new StaffModel();
        $srmodel    =   new StaffRoleModel();
        $ssmodel    =   new StaffStatusModel();
        $columns    =   [
            // Staff Columns
            "{$smodel->table}.sid as id",
            "{$smodel->table}.first_name",
            "{$smodel->table}.middle_name",
            "{$smodel->table}.last_name",
            "{$smodel->table}.username",
            "{$smodel->table}.email",
            "{$smodel->table}.password",
            // Role Columns
            "{$srmodel->table}.role_name",
            "{$srmodel->table}.permissions",
            // Status Columns
            "{$ssmodel->table}.status_name"
        ];
        $where = ["{$smodel->table}.username" => $this->username, "{$smodel->table}.email" => $this->username];
        return $smodel->select($columns)
            ->join($srmodel->table, "{$smodel->table}.role_relid", "=","{$srmodel->table}.{$srmodel->id}")
            ->join($ssmodel->table, "{$smodel->table}.status_relid", "=", "{$ssmodel->table}.{$ssmodel->id}")
            ->where($where, "=", 'OR')->first();
    }

    /**
     * Get Client
     * @return array
     */
    private function getClient()
    {
        $cmodel     =   new ClientModel();
        $csmodel    =   new ClientStatusModel();
        $columns    =   [
                // Client Columns
                "{$cmodel->table}.cid as id",
                "{$cmodel->table}.first_name",
                "{$cmodel->table}.middle_name",
                "{$cmodel->table}.last_name",
                "{$cmodel->table}.username",
                "{$cmodel->table}.email",
                "{$cmodel->table}.password",
                // Status Columns
                "{$csmodel->table}.status_name"
            ];
            $where = ["{$cmodel->table}.username" => $this->username, "{$cmodel->table}.email" => $this->username];
            return $cmodel->select($columns)
                ->join($csmodel->table, "{$cmodel->table}.status_relid", "=", "{$csmodel->table}.{$csmodel->id}")
                ->where($where, "=", 'OR')
                ->first();
    }
}