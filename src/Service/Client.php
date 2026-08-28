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
 * Clients - the people who are billed.
 *
 * @see \LBM\Action\Client
 * @method static string adjustCredit(int $clientId, int|float|string $amount)
 * @method static array all(array $where = [], string $direction = 'ASC', ?string $order = null)
 * @method static array browse(array $where = [], ?string $search = null, ?int $limit = null, string $direction = 'DESC')
 * @method static bool canSignIn(array $client)
 * @method static int count(array $where = [])
 * @method static int create(array $data)
 * @method static int delete(int|string $key)
 * @method static int deleteWhere(array $where)
 * @method static bool emailTaken(string $email, ?int $ignore = null)
 * @method static bool exists(array $where)
 * @method static ?array find(int|string|null $key)
 * @method static ?array findByLogin(string $identifier)
 * @method static ?array first(array $where)
 * @method static Model model()
 * @method static int modify(int|string $key, array $input)
 * @method static int remove(int|string $key)
 * @method static void setPassword(int $clientId, string $password)
 * @method static ?int statusId(string $name)
 * @method static string statusTable()
 * @method static array statuses()
 * @method static int store(array $input, ?string $password = null)
 * @method static void touchLogin(int $clientId, string $ip)
 * @method static int update(int|string $key, array $data)
 * @method static int updateWhere(array $where, array $data)
 * @method static bool usernameTaken(string $username, ?int $ignore = null)
 * @method static int verifyEmail(int $clientId)
 */
class Client extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'action.client';
    }
}
