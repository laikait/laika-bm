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
 * Help articles and their categories.
 *
 * `browse()` is the admin listing and shows everything. `published()` is the
 * public one and applies both the active flag and the publication date.
 *
 * @see \LBM\Action\KnowledgeBase
 * @method static array all(array $where = [], string $direction = 'ASC', ?string $order = null)
 * @method static array articleCounts()
 * @method static array browse(array $where = [], ?string $search = null, ?int $limit = null, string $direction = 'DESC')
 * @method static ?array category(int|string $key)
 * @method static ?array categoryBySlug(string $slug, bool $activeOnly = false)
 * @method static array categories(bool $activeOnly = false)
 * @method static int count(array $where = [])
 * @method static int create(array $data)
 * @method static int delete(int|string $key)
 * @method static int deleteWhere(array $where)
 * @method static bool exists(array $where)
 * @method static array featured(int $limit = 6)
 * @method static ?array find(int|string|null $key)
 * @method static ?array first(array $where)
 * @method static Model model()
 * @method static array published(?int $categoryId = null, ?string $search = null, ?int $limit = null)
 * @method static ?array publishedBySlug(string $slug)
 * @method static int recordVote(int $articleId, bool $helpful)
 * @method static void recordView(int $articleId)
 * @method static int update(int|string $key, array $data)
 * @method static int updateWhere(array $where, array $data)
 */
class KnowledgeBase extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'action.knowledgebase';
    }
}
