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

use Laika\Model\Model;
use Laika\Relay\Relay;

/**
 * The kinds of thing a product can be.
 *
 * @see \LBM\Action\ProductType
 * @method static array all(array $where = [], string $direction = 'ASC', ?string $order = null)
 * @method static array allTypes()
 * @method static array browse(array $where = [], ?string $search = null, ?int $limit = null, string $direction = 'DESC')
 * @method static array choices()
 * @method static int count(array $where = [])
 * @method static int countProducts(int $typeId)
 * @method static int create(array $data)
 * @method static int delete(int|string $key)
 * @method static bool exists(array $where)
 * @method static ?array find(int|string|null $key)
 * @method static ?array first(array $where)
 * @method static Model model()
 * @method static bool needsDomain(int $typeId)
 * @method static bool productNeedsDomain(int $productId)
 * @method static int remove(int $typeId)
 * @method static int save(array $input, int $typeId = 0)
 * @method static int update(int|string $key, array $data)
 */
class ProductType extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'action.producttype';
    }
}
