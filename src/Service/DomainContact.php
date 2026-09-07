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
 * Who a domain is registered to.
 *
 * A relay forwards method calls, not constants: `DomainContact::TYPES` fatals
 * here. Reach it through `types()`, or through the action.
 *
 * @see \LBM\Action\DomainContact
 * @method static array all(array $where = [], string $direction = 'ASC', ?string $order = null)
 * @method static ?array defaultsFrom(array $client)
 * @method static ?array find(int|string|null $key)
 * @method static int forget(int $domainId, string $type)
 * @method static array forDomain(int $domainId)
 * @method static array forRegistrar(int $domainId)
 * @method static bool hasRegistrant(int $domainId)
 * @method static string[] missingFrom(array $contact)
 * @method static Model model()
 * @method static ?array ofType(int $domainId, string $type)
 * @method static int put(int $domainId, string $type, array $input)
 * @method static bool seedFor(int $domainId, int $clientId)
 * @method static string[] types()
 */
class DomainContact extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'action.domain.contact';
    }
}
