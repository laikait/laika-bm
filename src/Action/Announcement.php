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

namespace LBM\Action;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Laika\Model\Model;
use LBM\Model\AnnouncementModel;

/**
 * Announcements - operator news, written in the admin panel and read on the
 * public site.
 *
 * The table has carried `title`, `body`, `published_at` and `is_active` since
 * the schema was first written, but nothing has ever read or written it. This
 * action and the two controllers that use it are the first.
 *
 * Two flags decide whether a visitor sees a row, and they mean different things:
 *
 *   `is_active`    - the operator's switch. Off means retracted.
 *   `published_at` - when it should start being visible. A row dated in the
 *                    future is written and finished but not yet news.
 *
 * `published()` applies both. It is the only method the public site is meant to
 * list through, which is why the admin-facing `browse()` inherited from Action
 * is left alone rather than being quietly filtered - the admin panel has to see
 * drafts and scheduled items, and a shared method that hid them would make the
 * list screen lie about what exists.
 */
class Announcement extends Action
{
    /**
     * The Model
     * @return Model
     */
    public function model(): Model
    {
        return new AnnouncementModel();
    }

    /**
     * Columns a Search Term Is Matched Against
     * @return array
     */
    protected function searchable(): array
    {
        return ['title', 'body'];
    }

    /**
     * The Column a Listing Is Ordered By
     * @return ?string
     */
    protected function createdColumn(): ?string
    {
        return 'published_at';
    }

    ####################################################################################
    /*=================================== PUBLIC SITE ================================*/
    ####################################################################################

    /**
     * Announcements a Visitor May Read, Newest First
     *
     * Keyset paginated through the inherited paginator, so a long history costs
     * the same as a short one.
     * @param ?int $limit Rows Per Page. Defaults to the data_limit option
     * @return array{rows:array, total:int, limit:int, cursor:?int, next:?int, next_url:?string, previous:?string, has_more:bool}
     */
    public function published(?int $limit = null): array
    {
        return $this->paginate(
            $this->live($this->model()),
            $this->live($this->model()),
            $limit
        );
    }

    /**
     * The Newest Few, For The Home Page
     * @param int $limit How Many
     * @return array
     */
    public function latest(int $limit = 3): array
    {
        return $this->live($this->model())
            ->order('published_at', self::DESC)
            ->limit($limit)
            ->get();
    }

    /**
     * One Announcement a Visitor May Read
     *
     * Null rather than the row when it is retracted or not yet due, so the
     * controller 404s. `announcements` has no slug column - only `uid` - so the
     * public URL keys on uid, matching the convention the client area already
     * uses for invoices and tickets.
     * @param string $uid Announcement UID
     * @return ?array
     */
    public function publishedByUid(string $uid): ?array
    {
        $model = $this->model();

        return $this->live($model)->where([$model->uid => $uid])->first() ?: null;
    }

    ####################################################################################
    /*================================== INTERNAL API ================================*/
    ####################################################################################

    /**
     * Constrain a Query To What The Public May See
     *
     * Both halves matter. Filtering only on is_active would publish a scheduled
     * announcement the moment it was saved, which is the one thing scheduling
     * exists to prevent.
     * @param Model $model Model To Constrain
     * @return Model
     */
    private function live(Model $model): Model
    {
        return $model
            ->where(['is_active' => 'yes'])
            ->where(['published_at' => date('Y-m-d H:i:s')], '<=');
    }
}
