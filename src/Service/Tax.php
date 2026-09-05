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
 * Tax rules, and the rate that comes out of them.
 *
 * A relay forwards method calls, not constants: `Tax::MAX_RATE` fatals here.
 * Reach it through the action.
 *
 * @see \LBM\Action\Tax
 * @method static string amountOn(string $net, string $rate)
 * @method static array browse(array $where = [], ?string $search = null, ?int $limit = null)
 * @method static bool configured()
 * @method static bool exempt(?array $client)
 * @method static ?array find(int|string|null $key)
 * @method static void flush()
 * @method static bool inclusive()
 * @method static array listing(bool $activeOnly = false)
 * @method static Model model()
 * @method static string netOf(string $gross, string $rate)
 * @method static string rateFor(?array $client, ?array $product = null)
 * @method static string rateForClient(?int $clientId, ?int $productId = null)
 * @method static int remove(int|string $key)
 * @method static array rulesFor(?array $client)
 * @method static int save(array $input, int|string|null $key = null)
 */
class Tax extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'action.tax';
    }
}
