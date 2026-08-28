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
 * Products, their groups, and what they cost.
 *
 * @see \LBM\Action\Product
 * @method static array all(array $where = [], string $direction = 'ASC', ?string $order = null)
 * @method static array browse(array $where = [], ?string $search = null, ?int $limit = null, string $direction = 'DESC')
 * @method static int count(array $where = [])
 * @method static int create(array $data)
 * @method static int delete(int|string $key)
 * @method static int deleteWhere(array $where)
 * @method static bool exists(array $where)
 * @method static ?array find(int|string|null $key)
 * @method static ?array findBySlug(string $slug)
 * @method static ?array first(array $where)
 * @method static ?array group(int|string $key)
 * @method static array groups(bool $activeOnly = false)
 * @method static Model model()
 * @method static int modify(int|string $key, array $input)
 * @method static ?array price(int $productId, int $currencyId, int $cycleId)
 * @method static array pricing(int $productId)
 * @method static array pricingModels()
 * @method static int remove(int|string $key)
 * @method static int removeGroup(int|string $key)
 * @method static int removePrice(int|string $key)
 * @method static int saveGroup(array $input, int|string|null $key = null)
 * @method static int setModuleConfig(int $productId, array $config)
 * @method static void setPrice(int $productId, int $currencyId, int $cycleId, int|float|string $price, int|float|string $setupFee = '0', bool $active = true)
 * @method static ?int statusId(string $name)
 * @method static string statusTable()
 * @method static array statuses()
 * @method static int store(array $input)
 * @method static int update(int|string $key, array $data)
 * @method static int updateWhere(array $where, array $data)
 */
class Product extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'action.product';
    }
}
