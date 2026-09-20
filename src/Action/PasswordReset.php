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

use Laika\Model\Model;
use Laika\Service\Uid;
use Laika\Service\Vault;
use Laika\Service\Visitor;
use LBM\Model\PasswordResetModel;
use LBM\Support\PasswordValidator;

/**
 * Emailed password-reset links, for every kind of account - Phase 52.
 *
 * This lived privately inside AuthClient while only clients could reset. Staff
 * reset now too, and a second copy of token handling is exactly where one side
 * gets a fix and the other does not, so both sign-in actions share this one.
 *
 * The token goes out by email and only its hash is stored. The hash is now
 * Vault::hash() - an HMAC under the app key - rather than bare sha256, so a
 * copy of the database alone cannot even test a guessed token. The token itself
 * is Vault::token(): URL-safe, which is why the reset routes accept `-` and `_`.
 *
 * Every lookup names the account types it will accept. The admin reset page
 * accepts staff tokens and nothing else; the client one accepts clients and
 * contacts. Otherwise a link issued for one area would open the other area's
 * form and reset an account that area has no business touching.
 */
class PasswordReset extends Action
{
    /** @var int How Long a Link Lasts, In Seconds */
    public const TTL = 3600;

    public function model(): Model
    {
        return new PasswordResetModel();
    }

    /**
     * Issue a Reset Token
     *
     * Any earlier outstanding link is retired first, so asking twice does not
     * leave two working keys in somebody's inbox.
     * @param int $relId Account ID
     * @param string $relType PasswordValidator::STAFF, CLIENT or CONTACT
     * @return string The plain token - for the email, never stored
     */
    public function issue(int $relId, string $relType): string
    {
        $this->revoke($relId, $relType);

        $token = Vault::token();
        $model = new PasswordResetModel();
        $ip = Visitor::ip();

        $model->insert([
            $model->uid  =>  Uid::make(),
            'rel_id'     =>  $relId,
            'rel_type'   =>  $relType,
            'token'      =>  Vault::hash($token),
            'ip'         =>  is_string($ip) && $ip !== '' ? $ip : 'unknown',
            'expires_at' =>  date('Y-m-d H:i:s', time() + self::TTL),
            'created_at' =>  $this->now(),
        ]);

        return $token;
    }

    /**
     * The Live Reset Row For a Token, Or Null
     * @param string $token Plain Token
     * @param string[] $relTypes Account Types This Caller Accepts
     * @return ?array
     */
    public function lookup(string $token, array $relTypes): ?array
    {
        $token = trim($token);

        if ($token === '' || $relTypes === []) {
            return null;
        }

        $row = (new PasswordResetModel())
            ->where(['token' => Vault::hash($token)])
            ->whereIn('rel_type', array_values($relTypes))
            ->isNull('used_at')
            ->where(['expires_at' => date('Y-m-d H:i:s')], '>')
            ->first();

        return is_array($row) ? $row : null;
    }

    /**
     * Spend a Token On a New Password
     *
     * Marking the row used and writing the password happen together: a link that
     * still worked after being used would be a second key to the account.
     * @param array $row Row From lookup()
     * @param string $password New Password, Already Validated
     * @return void
     */
    public function consume(array $row, string $password): void
    {
        $relId = (int) $row['rel_id'];
        $relType = (string) $row['rel_type'];
        $passwords = new PasswordValidator();

        (new PasswordResetModel())->transaction(
            function (PasswordResetModel $m) use ($row, $relId, $relType, $password, $passwords): void {
                $m->where([$m->id => (int) $row['reset_id']])->update(['used_at' => $this->now()]);

                $passwords->put($relId, $relType, $password);
            }
        );

        // Any other outstanding link is stale - the password it was issued
        // against no longer exists.
        $this->revoke($relId, $relType);
    }

    /**
     * Drop Every Outstanding Link For An Account
     * @param int $relId Account ID
     * @param string $relType Account Type
     * @return int Affected rows
     */
    public function revoke(int $relId, string $relType): int
    {
        return (new PasswordResetModel())
            ->where(['rel_id' => $relId, 'rel_type' => $relType])
            ->isNull('used_at')
            ->update(['used_at' => $this->now()]);
    }
}
