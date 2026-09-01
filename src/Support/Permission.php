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

namespace LBM\Support;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use LBM\Model\StaffRoleModel;

/**
 * Staff role permissions.
 *
 * Stored as JSON on staff_roles.permissions, in the shape
 * {"invoice":{"read":1,"create":1,"update":1,"delete":0}, "order":{...}}
 * and cast to an array by the model.
 */
class Permission
{
    /**
     * @var string[] Permission Groups
     *
     * One entry per admin capability area. The role editor renders a
     * group x action matrix from this list, and the installer grants the
     * superadmin role every pair.
     */
    public const GROUPS = [
        'staff', 'role', 'client', 'product', 'order', 'invoice',
        'transaction', 'ticket', 'note', 'domain', 'server', 'currency',
        'report', 'module', 'settings', 'activity', 'content',
    ];

    // `content` covers announcements and the knowledgebase together. One group
    // rather than two because they are one job to an operator - both are things
    // written here and read on the public site - and because every group adds a
    // row of four checkboxes to the role matrix, which is already long.
    //
    // A role created BEFORE this group existed does not carry it, and
    // allows() answers false for a permission it has never seen. So an existing
    // install has to tick the new boxes once; a fresh install gets them from the
    // installer, which grants the superadmin role every pair in this list.

    /** @var string[] Recognised Actions */
    public const ACTIONS = ['create', 'read', 'update', 'delete'];

    /** @var array<int,array> Permissions Cached Per Role */
    private array $cache = [];

    /**
     * Check a Role Grants an Access
     * @param ?int $roleId Role ID. Null is always denied
     * @param string $access Access Name. Example: 'invoice.read'
     * @return bool
     * @throws \InvalidArgumentException
     */
    public function allows(?int $roleId, string $access): bool
    {
        [$group, $action] = $this->parse($access);

        if (!$roleId) {
            return false;
        }

        $permissions = $this->forRole($roleId);

        return !empty($permissions[$group][$action]);
    }

    /**
     * Get a Role's Whole Permission Set
     * @param int $roleId Role ID
     * @return array
     */
    public function forRole(int $roleId): array
    {
        if (array_key_exists($roleId, $this->cache)) {
            return $this->cache[$roleId];
        }

        $model = new StaffRoleModel();
        $row = $model->select('permissions')->where([$model->id => $roleId])->first();

        // The model casts `permissions` to json, but a role saved with an empty
        // column reads back as null - normalise so callers only see an array.
        $permissions = $row['permissions'] ?? [];

        return $this->cache[$roleId] = is_array($permissions) ? $permissions : [];
    }

    /**
     * Build a Full-Access Permission Set
     *
     * Used for the superadmin role the installer creates.
     * @param string[] $groups Permission Groups
     * @return array
     */
    public function grantAll(array $groups): array
    {
        $permissions = [];

        foreach ($groups as $group) {
            $permissions[$group] = array_fill_keys(self::ACTIONS, 1);
        }

        return $permissions;
    }

    /**
     * Normalise Submitted Checkboxes Into a Permission Set
     *
     * A checkbox that is off is absent from the POST body, so every known
     * group/action pair is written explicitly as 1 or 0 rather than omitted.
     * @param array $groups Permission Groups
     * @param array $input Submitted Data. Example: ['invoice' => ['read' => 'on']]
     * @return array
     */
    public function fromInput(array $groups, array $input): array
    {
        $permissions = [];

        foreach ($groups as $group) {
            foreach (self::ACTIONS as $action) {
                $permissions[$group][$action] = empty($input[$group][$action]) ? 0 : 1;
            }
        }

        return $permissions;
    }

    /**
     * Forget Cached Permissions
     * @param ?int $roleId Role ID. Null clears every role
     * @return void
     */
    public function flush(?int $roleId = null): void
    {
        if ($roleId === null) {
            $this->cache = [];
            return;
        }

        unset($this->cache[$roleId]);
    }

    ##########################################################################
    /*============================ INTERNAL API ============================*/
    ##########################################################################

    /**
     * Split 'group.action' Into Its Parts
     * @param string $access Access Name
     * @return array{0:string,1:string}
     * @throws \InvalidArgumentException
     */
    private function parse(string $access): array
    {
        if (!preg_match('/^[a-z]+\.[a-z]+$/i', $access)) {
            throw new \InvalidArgumentException(
                "Invalid Permission [{$access}]. Expected 'group.action', for example 'invoice.read'."
            );
        }

        [$group, $action] = explode('.', strtolower($access), 2);

        if (!in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException(
                "Invalid Permission Action [{$action}]. Expected one of: " . implode(', ', self::ACTIONS) . '.'
            );
        }

        return [$group, $action];
    }
}
