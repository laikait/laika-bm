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
 * Promotional codes - money off, at the operator's invitation.
 *
 * A relay forwards method calls, not constants: `Promo::TYPES` fatals here.
 * Reach it through `types()` and `scopes()` beside it, or through the action.
 *
 * @see \LBM\Action\Promo
 * @method static array browse(array $where = [], ?string $search = null, ?int $limit = null)
 * @method static ?array byCode(string $code)
 * @method static bool claim(int $promoId)
 * @method static bool covers(array $promo, array $line)
 * @method static string describe(array $promo)
 * @method static array discountFor(array $promo, array $lines)
 * @method static ?array find(int|string|null $key)
 * @method static array listing(bool $activeOnly = false)
 * @method static Model model()
 * @method static int modify(int|string $key, array $input)
 * @method static ?string refusal(?array $promo, int $currencyId)
 * @method static void release(int $promoId)
 * @method static int remove(int|string $key)
 * @method static string[] scopes()
 * @method static int store(array $input)
 * @method static string totalOf(array $discounts)
 * @method static string[] types()
 * @method static bool usedUp(array $promo)
 */
class Promo extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'action.promo';
    }
}
