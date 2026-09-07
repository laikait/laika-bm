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
 * Configurable options - how one product is sold in more than one size.
 *
 * A relay forwards method calls, not constants: `ConfigOption::TYPES` fatals
 * here. Reach it through `types()` beside it, or through the action.
 *
 * @see \LBM\Action\ConfigOption
 * @method static int addSub(int $groupId, array $input)
 * @method static int attachToItem(int $orderItemId, array $resolved)
 * @method static array browse(array $where = [], ?string $search = null, ?int $limit = null)
 * @method static int copyToService(int $orderItemId, int $serviceId)
 * @method static string describeChoices(array $resolved, int $limit = 300)
 * @method static ?array detailed(int|string $key, bool $activeOnly = false)
 * @method static array detailedFor(int $serviceId)
 * @method static array detailedForItem(int $orderItemId)
 * @method static int detachFromItem(int $orderItemId)
 * @method static ?array find(int|string|null $key)
 * @method static array forProduct(int $productId, bool $activeOnly = true)
 * @method static array forService(int $serviceId)
 * @method static array listing(bool $withSubs = true)
 * @method static int mapToProduct(int $productId, array $groupIds)
 * @method static int maxQuantity()
 * @method static int maxText()
 * @method static int[] mappedIds(int $productId)
 * @method static Model model()
 * @method static int modify(int|string $key, array $input)
 * @method static int modifySub(int $subId, array $input)
 * @method static ?array optionFor(int $groupId)
 * @method static ?array price(int $subId, int $currencyId, int $cycleId)
 * @method static string priceOf(array $resolved)
 * @method static array pricing(int $subId)
 * @method static array<int,int> productCounts()
 * @method static int remove(int|string $key)
 * @method static int removeSub(int $subId)
 * @method static ?array resolve(array $chosen, int $productId, int $currencyId, int $cycleId)
 * @method static void setPrice(int $subId, int $currencyId, int $cycleId, int|float|string|null $price, int|float|string|null $setup = null)
 * @method static string setupOf(array $resolved)
 * @method static int store(array $input)
 * @method static ?array sub(int $subId)
 * @method static array subs(int $optionId, bool $activeOnly = false)
 * @method static string[] types()
 * @method static array valuesForItem(int $orderItemId)
 */
class ConfigOption extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'action.config.option';
    }
}
