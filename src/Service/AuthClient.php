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

namespace LBM\Service;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Laika\Relay\Relay;

/**
 * Signing clients and contacts in and out of the client area.
 *
 * @see \LBM\Action\AuthClient
 * @method static array all(array $where = [], string $direction = 'ASC', ?string $order = null)
 * @method static array attempt(string $identifier, string $password)
 * @method static array browse(array $where = [], ?string $search = null, ?int $limit = null, string $direction = 'DESC')
 * @method static array changePassword(int $clientId, string $current, string $new, ?string $confirm = null, string $relType = \LBM\Support\PasswordValidator::CLIENT)
 * @method static int count(array $where = [])
 * @method static int create(array $data)
 * @method static int delete(int|string $key)
 * @method static int deleteWhere(array $where)
 * @method static bool exists(array $where)
 * @method static ?array find(int|string|null $key)
 * @method static ?array findReset(string $token)
 * @method static ?array first(array $where)
 * @method static string forgot(string $email)
 * @method static void logout()
 * @method static Model model()
 * @method static array register(array $input, string $password)
 * @method static array reset(string $token, string $password, ?string $confirm = null)
 * @method static int revokeResets(int $relId, string $relType)
 * @method static int update(int|string $key, array $data)
 * @method static int updateWhere(array $where, array $data)
 */
class AuthClient extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'action.auth.client';
    }
}
