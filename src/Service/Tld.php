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
 * The domain price list - what the shop sells and what each TLD costs.
 *
 * A relay forwards method calls, not constants: `Tld::TERMS` fatals here. Reach
 * it through `terms()` beside it, or through the action.
 *
 * @see \LBM\Action\Tld
 * @method static string[] actions()
 * @method static array browse(array $where = [], ?string $search = null, ?int $limit = null)
 * @method static ?string cycleForYears(int $years)
 * @method static ?array find(int|string|null $key)
 * @method static array forSale()
 * @method static array listing(bool $activeOnly = false)
 * @method static ?array match(string $domain)
 * @method static int modify(int|string $key, array $input)
 * @method static Model model()
 * @method static ?string normaliseDomain(string $domain)
 * @method static string normaliseTld(string $tld)
 * @method static ?string priceFor(array $tld, int $currencyId, int $years = 1, string $action = 'register')
 * @method static int remove(int|string $key)
 * @method static ?array split(string $domain)
 * @method static int store(array $input)
 * @method static array terms()
 * @method static int[] termsFor(array $tld)
 * @method static int yearsForCycle(string $cycle)
 */
class Tld extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'action.tld';
    }
}
