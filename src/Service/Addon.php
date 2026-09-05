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
 * Addons - the extras sold alongside a product.
 *
 * A relay forwards method calls, not constants: `Addon::PRICING_MODELS` fatals
 * here. Reach it through `pricingModels()` beside it, or through the action.
 *
 * @see \LBM\Action\Addon
 * @method static int attach(array $service, int $addonId, string $amount)
 * @method static array browse(array $where = [], ?string $search = null, ?int $limit = null)
 * @method static array detailedFor(int $serviceId)
 * @method static ?array find(int|string|null $key)
 * @method static array forProduct(int $productId, bool $activeOnly = true)
 * @method static array forService(int $serviceId)
 * @method static array listing(bool $activeOnly = false)
 * @method static int mapToProduct(int $productId, array $addonIds)
 * @method static int[] mappedIds(int $productId)
 * @method static Model model()
 * @method static int modify(int|string $key, array $input)
 * @method static ?array price(int $addonId, int $currencyId, int $cycleId)
 * @method static array pricing(int $addonId)
 * @method static string[] pricingModels()
 * @method static int remove(int|string $key)
 * @method static void setPrice(int $addonId, int $currencyId, int $cycleId, int|float|string|null $price)
 * @method static int store(array $input)
 * @method static string totalFor(int $serviceId)
 */
class Addon extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'action.addon';
    }
}
