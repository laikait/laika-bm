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

use Laika\Service\Vault;
use LBM\Model\PasswordModel;

/**
 * Password strength rules, hashing, and reads/writes against the `passwords`
 * table.
 *
 * Credentials live in one shared table keyed by (rel_id, rel_type) rather than
 * on staffs/clients/client_contacts, so staff, clients and contacts all
 * authenticate through the same path. Rows are versioned: the live password is
 * the one with revoked_at IS NULL, and changing it revokes the old row instead
 * of overwriting it.
 */
class PasswordValidator
{
    /** @var string Staff Credential Type */
    public const STAFF = 'staff';

    /** @var string Client Credential Type */
    public const CLIENT = 'client';

    /** @var string Client Contact Credential Type */
    public const CONTACT = 'contact';

    /**
     * Check a Password Against The Configured Rules
     * @param string $password Plain Password
     * @param ?string $confirm Confirmation, When The Form Has One
     * @return string[] Error messages, empty when the password is acceptable
     */
    public function validate(string $password, ?string $confirm = null): array
    {
        $errors = [];
        $min = max(6, option_int('password_min_length', 8));

        if (strlen($password) < $min) {
            $errors[] = "Password must be at least {$min} characters long.";
        }

        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Password must contain at least one lowercase letter.';
        }

        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Password must contain at least one uppercase letter.';
        }

        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Password must contain at least one number.';
        }

        if ($confirm !== null && !hash_equals($password, $confirm)) {
            $errors[] = 'Password confirmation does not match.';
        }

        return $errors;
    }

    /**
     * Hash a Password
     * @param string $password Plain Password
     * @return string
     */
    public function hash(string $password): string
    {
        return Vault::hashPassword($password);
    }

    /**
     * Verify a Plain Password Against a Stored Hash
     * @param string $password Plain Password
     * @param ?string $hash Stored Hash
     * @return bool
     */
    public function verify(string $password, ?string $hash): bool
    {
        // Hash the input anyway when there is no stored hash, so a missing
        // credential takes the same time as a wrong one.
        if ($hash === null || $hash === '') {
            Vault::hashPassword($password);
            return false;
        }

        return Vault::verifyPassword($password, $hash);
    }

    /**
     * Get The Live Password Hash For a User
     * @param int $relId Staff/Client/Contact ID
     * @param string $relType One of STAFF, CLIENT, CONTACT
     * @return ?string
     */
    public function current(int $relId, string $relType): ?string
    {
        $model = new PasswordModel();

        $row = $model->select('hash')
            ->where(['rel_id' => $relId, 'rel_type' => $relType])
            ->isNull('revoked_at')
            ->order($model->id, 'DESC')
            ->first();

        return $row['hash'] ?? null;
    }

    /**
     * Set a User's Password
     *
     * Revokes any live row first, then writes the new one, both inside a single
     * transaction so a user is never left with two live passwords or none.
     * @param int $relId Staff/Client/Contact ID
     * @param string $relType One of STAFF, CLIENT, CONTACT
     * @param string $password Plain Password
     * @return void
     */
    public function put(int $relId, string $relType, string $password): void
    {
        $hash = $this->hash($password);
        $now  = date('Y-m-d H:i:s');

        (new PasswordModel())->transaction(
            function (PasswordModel $m) use ($relId, $relType, $hash, $now) {
                $live = $m->select($m->id)
                    ->where(['rel_id' => $relId, 'rel_type' => $relType])
                    ->isNull('revoked_at')
                    ->first();

                if (!empty($live)) {
                    $m->where(['rel_id' => $relId, 'rel_type' => $relType])
                        ->isNull('revoked_at')
                        ->update(['revoked_at' => $now]);
                }

                // The uid is written explicitly. `passwords`.uid is UNIQUE with
                // no default, so leaving it out inserts '' - which works exactly
                // once and then collides with itself on every later password
                // anybody sets.
                $m->insert([
                    $m->uid      =>  Uid::make(),
                    'rel_id'     =>  $relId,
                    'rel_type'   =>  $relType,
                    'hash'       =>  $hash,
                    'created_at' =>  $now,
                ]);
            }
        );
    }
}
