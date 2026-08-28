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
use LBM\Model\StaffModel;
use LBM\Model\StaffRoleModel;
use LBM\Support\Uid;
use LBM\Service\Status;
use LBM\Service\Permission;
use LBM\Support\Permission as PermissionSupport;
use LBM\Support\PasswordValidator;

/**
 * Staff accounts and the roles that grant them access.
 *
 * What a staff member may do is decided entirely by their role: there are no
 * per-person overrides, so revoking an ability is one edit rather than a hunt
 * through every account that might have been granted it individually.
 */
class Staff extends Action
{
    /** @var string Status Lookup Table */
    public const STATUSES = 'staff_statuses';

    /** @var string[] Columns a Form May Write */
    public const FIELDS = [
        'role_relid', 'first_name', 'middle_name', 'last_name', 'username',
        'email', 'status_relid', 'is_restricted',
    ];

    /** @var string[] Columns That Store Null Rather Than An Empty String */
    private const NULLABLE = ['middle_name', 'username'];

    public function model(): Model
    {
        return new StaffModel();
    }

    protected function searchable(): array
    {
        return ['first_name', 'last_name', 'email', 'username'];
    }

    protected function createdColumn(): ?string
    {
        return 'staff_created_at';
    }

    protected function updatedColumn(): ?string
    {
        return 'staff_updated_at';
    }

    ####################################################################################
    /*==================================== STAFF =====================================*/
    ####################################################################################

    /**
     * Find a Staff Member By Their Login Identifier
     * @param string $identifier Username Or Email
     * @return ?array
     */
    public function findByLogin(string $identifier): ?array
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            return null;
        }

        $row = $this->model()
            ->where(['username' => $identifier, 'email' => $identifier], '=', 'OR')
            ->first();

        return is_array($row) ? $row : null;
    }

    /**
     * One Page Of Staff, With Role And Status Names
     * @param array $where Conditions
     * @param ?string $search Search Term
     * @param ?int $limit Rows Per Page
     * @return array
     */
    public function browseWithRoles(array $where = [], ?string $search = null, ?int $limit = null): array
    {
        $staff = new StaffModel();
        $roles = new StaffRoleModel();

        $s = $staff->table;
        $r = $roles->table;

        // The join lives here rather than in browse() because the base method
        // builds the same query twice and a joined count would double-count on
        // any one-to-many. This one is many-to-one, so it is safe - but keeping
        // the two paths separate means nobody has to check that again later.
        $prefixed = [];

        foreach ($where as $column => $value) {
            $prefixed[str_contains((string) $column, '.') ? (string) $column : "{$s}.{$column}"] = $value;
        }

        $counted = new StaffModel();
        $this->search($this->conditions($counted, $prefixed), $search);

        $listed = new StaffModel();
        $listed->select(["{$s}.*", "{$r}.role_name AS role_name"])
            ->join($r, "{$r}.{$roles->id}", '=', "{$s}.role_relid");

        $this->search($this->conditions($listed, $prefixed), $search);

        return $this->paginate($listed, $counted, $limit, self::DESC);
    }

    /**
     * Create a Staff Member, With Their Password
     * @param array $input Submitted Data
     * @param string $password Plain Password
     * @return int New Staff ID
     */
    public function store(array $input, string $password): int
    {
        $data = $this->fields($input);
        $data['status_relid'] = (int) ($data['status_relid'] ?? 0)
            ?: (Status::idOf(self::STATUSES, 'active') ?? 1);

        $id = 0;

        $this->model()->transaction(function (StaffModel $m) use ($data, $password, &$id): void {
            $id = (int) $m->insert($this->stamp($data, true));

            (new PasswordValidator())->put($id, PasswordValidator::STAFF, $password);
        });

        return $id;
    }

    /**
     * Update a Staff Member
     * @param int|string $key Staff ID Or Uid
     * @param array $input Submitted Data
     * @return int Affected rows
     */
    public function modify(int|string $key, array $input): int
    {
        return $this->update($key, $this->fields($input));
    }

    /**
     * Set a Staff Member's Password
     * @param int $staffId Staff ID
     * @param string $password Plain Password
     * @return void
     */
    public function setPassword(int $staffId, string $password): void
    {
        (new PasswordValidator())->put($staffId, PasswordValidator::STAFF, $password);
    }

    /**
     * Delete a Staff Member
     *
     * Refuses to remove the last one that can still sign in. An installation
     * with no reachable administrator has no way back in short of editing the
     * database by hand.
     * @param int|string $key Staff ID Or Uid
     * @return int Affected rows
     * @throws RuntimeException
     */
    public function remove(int|string $key): int
    {
        $staff = $this->find($key);

        if ($staff === null) {
            return 0;
        }

        if ($this->activeCount() <= 1) {
            throw new RuntimeException('This is the last active staff account - it cannot be deleted.');
        }

        return $this->delete((int) $staff['sid']);
    }

    /**
     * How Many Staff Accounts Can Currently Sign In
     * @return int
     */
    public function activeCount(): int
    {
        $active = Status::idOf(self::STATUSES, 'active');

        $model = $this->model();
        $model->where(['is_restricted' => 'no']);

        if ($active !== null) {
            $model->where(['status_relid' => $active]);
        }

        return $model->count();
    }

    /**
     * Record a Successful Sign-In
     * @param int $staffId Staff ID
     * @param string $ip IP Address
     * @return void
     */
    public function touchLogin(int $staffId, string $ip): void
    {
        $model = $this->model();

        $model->where([$model->id => $staffId])->update([
            'last_login_at' =>  $this->now(),
            'last_login_ip' =>  $ip,
        ]);
    }

    /**
     * Whether a Staff Member May Sign In
     * @param array $staff Staff Row
     * @return bool
     */
    public function canSignIn(array $staff): bool
    {
        if (($staff['is_restricted'] ?? 'no') === 'yes') {
            return false;
        }

        return Status::name(self::STATUSES, $staff['status_relid'] ?? null) === 'active';
    }

    /**
     * Whether An Email Address Is Already Taken
     * @param string $email Email Address
     * @param ?int $ignore Staff ID To Exclude, When Editing
     * @return bool
     */
    public function emailTaken(string $email, ?int $ignore = null): bool
    {
        $model = $this->model();
        $model->where(['email' => trim($email)]);

        if ($ignore !== null) {
            $model->whereNot([$model->id => $ignore]);
        }

        return $model->count() > 0;
    }

    /**
     * Whether a Username Is Already Taken
     * @param string $username Username
     * @param ?int $ignore Staff ID To Exclude, When Editing
     * @return bool
     */
    public function usernameTaken(string $username, ?int $ignore = null): bool
    {
        $username = trim($username);

        if ($username === '') {
            return false;
        }

        $model = $this->model();
        $model->where(['username' => $username]);

        if ($ignore !== null) {
            $model->whereNot([$model->id => $ignore]);
        }

        return $model->count() > 0;
    }

    /**
     * The Status Lookup Table This Resource Uses
     *
     * A method rather than the STATUSES constant, because a relay facade
     * forwards method calls and not constants - so a controller reaching this
     * through LBM\Service\* has no way to read the constant directly.
     * @return string
     */
    public function statusTable(): string
    {
        return self::STATUSES;
    }

    /**
     * The Id Of One Named Status
     * @param string $name Status Name. Example: 'active'
     * @return ?int Null when no status of that name exists
     */
    public function statusId(string $name): ?int
    {
        return Status::idOf(self::STATUSES, $name);
    }

    /**
     * The Status Choices a Form Offers
     * @return array
     */
    public function statuses(): array
    {
        return Status::all(self::STATUSES);
    }

    ####################################################################################
    /*==================================== ROLES =====================================*/
    ####################################################################################

    /**
     * Every Role
     * @return array
     */
    public function roles(): array
    {
        $model = new StaffRoleModel();

        return $model->order('role_name', self::ASC)->get();
    }

    /**
     * Find One Role
     * @param int|string $key Role ID Or Uid
     * @return ?array
     */
    public function role(int|string $key): ?array
    {
        $model = new StaffRoleModel();
        $row = $this->key($model, $key)->first();

        return is_array($row) ? $row : null;
    }

    /**
     * Create a Role
     * @param string $name Role Name
     * @param array $input Submitted Permission Checkboxes
     * @return int New Role ID
     */
    public function storeRole(string $name, array $input): int
    {
        $model = new StaffRoleModel();

        $id = (int) $model->insert([
            $model->uid       =>  Uid::make(),
            'role_name'       =>  trim($name),
            'permissions'     =>  $this->encodePermissions($input),
            'role_created_at' =>  $this->now(),
            'role_updated_at' =>  $this->now(),
        ]);

        Permission::flush();

        return $id;
    }

    /**
     * Update a Role's Name And Permission Matrix
     * @param int|string $key Role ID Or Uid
     * @param string $name Role Name
     * @param array $input Submitted Permission Checkboxes
     * @return int Affected rows
     */
    public function modifyRole(int|string $key, string $name, array $input): int
    {
        $model = new StaffRoleModel();

        $affected = $this->key($model, $key)->update([
            'role_name'       =>  trim($name),
            'permissions'     =>  $this->encodePermissions($input),
            'role_updated_at' =>  $this->now(),
        ]);

        // The support class caches a role's parsed JSON for the request. Without
        // this the editor would redirect back to the permissions it just replaced.
        Permission::flush();

        return $affected;
    }

    /**
     * Delete a Role
     *
     * Refuses while any staff member still holds it - the alternative is an
     * account whose role_relid points at nothing, which reads as no permissions
     * at all and looks like a bug rather than a decision.
     * @param int|string $key Role ID Or Uid
     * @return int Affected rows
     * @throws RuntimeException
     */
    public function removeRole(int|string $key): int
    {
        $role = $this->role($key);

        if ($role === null) {
            return 0;
        }

        $id = (int) $role['role_id'];
        $holders = $this->count(['role_relid' => $id]);

        if ($holders > 0) {
            throw new RuntimeException(
                "This role is assigned to {$holders} staff account(s). Reassign them before deleting it."
            );
        }

        $model = new StaffRoleModel();
        $affected = $model->where([$model->id => $id])->delete();

        Permission::flush($id);

        return $affected;
    }

    /**
     * The Permission Groups The Role Editor Renders
     * @return string[]
     */
    public function permissionGroups(): array
    {
        return PermissionSupport::GROUPS;
    }

    /**
     * The Permission Actions The Role Editor Renders
     * @return string[]
     */
    public function permissionActions(): array
    {
        return PermissionSupport::ACTIONS;
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Reduce Submitted Input To Writable Columns
     * @param array $input Submitted Data
     * @return array
     */
    private function fields(array $input): array
    {
        $data = $this->nullable($this->only($input, self::FIELDS), self::NULLABLE);

        if (array_key_exists('is_restricted', $data)) {
            $data['is_restricted'] = $this->flag($data['is_restricted']);
        }

        if (array_key_exists('email', $data)) {
            $data['email'] = strtolower((string) $data['email']);
        }

        return $data;
    }

    /**
     * Build The Permission JSON From Submitted Checkboxes
     *
     * json_encode()d here rather than left to the model's `json` cast: casts
     * run on read only, and handing insert() an array is an "Array to string
     * conversion" rather than JSON.
     * @param array $input Submitted Data
     * @return string
     */
    private function encodePermissions(array $input): string
    {
        $submitted = $input['permissions'] ?? [];
        $submitted = is_array($submitted) ? $submitted : [];

        $permissions = Permission::fromInput(PermissionSupport::GROUPS, $submitted);

        return json_encode($permissions, JSON_THROW_ON_ERROR);
    }
}
