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
 * Password rules, hashing and the `passwords` table.
 *
 * @see \LBM\Support\PasswordValidator
 * @method static array validate(string $password, ?string $confirm = null)
 * @method static string hash(string $password)
 * @method static bool verify(string $password, ?string $hash)
 * @method static ?string current(int $relId, string $relType)
 * @method static void put(int $relId, string $relType, string $password)
 */
class Password extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'support.password.validator';
    }
}
