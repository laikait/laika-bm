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

namespace LBM\Support;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Laika\Model\Model;
use Laika\Service\Url;
use Laika\Service\Request;

/**
 * Keyset pagination.
 *
 * Pages are walked with `WHERE id > :after LIMIT n`, never with OFFSET: the
 * database can jump straight to the cursor on the primary key index instead of
 * counting and discarding every row before it, so page 900 costs the same as
 * page 2.
 *
 * The trade-off is that pages are only reachable in sequence - there is no
 * "jump to page 40". List screens get first/previous/next plus a total count,
 * which is what a billing back office actually needs.
 *
 * Every query is built with model methods only, so it runs unchanged on any
 * driver laika-model supports.
 */
class Paginator
{
    /** @var string Query Key Holding The Cursor */
    public const CURSOR = 'after';

    /**
     * Fetch One Page
     *
     * The model is expected to arrive with its where/join/select clauses
     * already applied. This adds only the cursor, ordering and limit.
     * @param Model $model Prepared Model
     * @param ?int $limit Rows Per Page. Defaults to the data_limit option
     * @param string $direction 'ASC' or 'DESC'
     * @return array{rows:array, cursor:?int, next:?int, previous:?string, has_more:bool, limit:int}
     */
    public function page(Model $model, ?int $limit = null, string $direction = 'ASC'): array
    {
        $limit  = $limit && $limit > 0 ? $limit : data_limit();
        $cursor = $this->cursor();
        $id     = $model->id;

        if ($cursor !== null) {
            // '>' walking forward through ascending ids, '<' when newest-first.
            $model->where([$id => $cursor], $direction === 'DESC' ? '<' : '>');
        }

        // Fetch one extra row: its presence is what tells us another page
        // exists, without a second COUNT query.
        $rows = $model->order($id, $direction)->limit($limit + 1)->get();

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        $last = $rows ? end($rows) : null;
        reset($rows);

        return [
            'rows'      =>  $rows,
            'limit'     =>  $limit,
            'cursor'    =>  $cursor,
            'has_more'  =>  $hasMore,
            'next'      =>  ($hasMore && $last) ? (int) $last[$id] : null,
            'previous'  =>  $cursor !== null ? Url::withoutQuery([self::CURSOR]) : null,
        ];
    }

    /**
     * Build The URL For The Next Page
     * @param ?int $next Next Cursor
     * @return ?string
     */
    public function nextUrl(?int $next): ?string
    {
        return $next === null ? null : Url::withQuery([self::CURSOR => (string) $next]);
    }

    /**
     * Read The Cursor From The Query String
     * @return ?int Null when starting from the beginning
     */
    public function cursor(): ?int
    {
        $after = Request::input(self::CURSOR);

        if ($after === null || $after === '' || !is_numeric($after)) {
            return null;
        }

        $after = (int) $after;

        return $after > 0 ? $after : null;
    }
}
