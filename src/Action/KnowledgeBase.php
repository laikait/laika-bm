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
use LBM\Model\KnowledgeBaseModel;
use LBM\Model\KnowledgeBaseCategoryModel;

/**
 * The knowledgebase - help articles, grouped into categories, written in the
 * admin panel and read on the public site.
 *
 * Like announcements, the `articles` and `kb_categories` tables have been in
 * the schema from the start with nothing reading or writing them. Unlike
 * announcements they arrived fully shaped for a public site: a unique slug, a
 * self-referencing category tree, a view counter and helpful/unhelpful votes.
 *
 * Slugs, not uids, in public URLs. A knowledgebase is the one part of this
 * application written to be found from outside - by search engines and by
 * people sending each other links - so `/knowledgebase/article/how-to-pay`
 * earns its keep where an opaque uid would not. Everything else in the app
 * keys on uid, deliberately, because a client id in a URL is an invitation to
 * try the next one along; an article is public by definition, so there is
 * nothing to enumerate.
 */
class KnowledgeBase extends Action
{
    /**
     * The Model
     * @return Model
     */
    public function model(): Model
    {
        return new KnowledgeBaseModel();
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
        return 'kb_created_at';
    }

    ####################################################################################
    /*=================================== CATEGORIES =================================*/
    ####################################################################################

    /**
     * Categories
     * @param bool $activeOnly Only Categories a Visitor May See
     * @return array
     */
    public function categories(bool $activeOnly = false): array
    {
        $model = new KnowledgeBaseCategoryModel();

        if ($activeOnly) {
            $model->where(['is_active' => 'yes']);
        }

        return $model->order('name', self::ASC)->get();
    }

    /**
     * One Category, By Slug
     * @param string $slug Category Slug
     * @param bool $activeOnly Only If a Visitor May See It
     * @return ?array
     */
    public function categoryBySlug(string $slug, bool $activeOnly = false): ?array
    {
        $model = (new KnowledgeBaseCategoryModel())->where(['slug' => $slug]);

        if ($activeOnly) {
            $model->where(['is_active' => 'yes']);
        }

        return $model->first() ?: null;
    }

    /**
     * One Category, By Id Or UID
     * @param int|string $key Category Id Or UID
     * @return ?array
     */
    public function category(int|string $key): ?array
    {
        $model = new KnowledgeBaseCategoryModel();
        $column = is_int($key) || ctype_digit((string) $key) ? $model->id : $model->uid;

        return $model->where([$column => $key])->first() ?: null;
    }

    /**
     * How Many Published Articles Each Category Holds
     *
     * Keyed by category id, for a listing that shows a count beside each
     * category without running one query per row.
     * @return array<int,int>
     */
    public function articleCounts(): array
    {
        $counts = [];

        foreach ($this->live($this->model())->get() as $row) {
            $id = (int) ($row['category_relid'] ?? 0);

            if ($id > 0) {
                $counts[$id] = ($counts[$id] ?? 0) + 1;
            }
        }

        return $counts;
    }

    ####################################################################################
    /*=================================== PUBLIC SITE ================================*/
    ####################################################################################

    /**
     * Published Articles, Optionally In One Category Or Matching a Search
     * @param ?int $categoryId Restrict To One Category
     * @param ?string $search Search Term
     * @param ?int $limit Rows Per Page. Defaults to the data_limit option
     * @return array{rows:array, total:int, limit:int, cursor:?int, next:?int, next_url:?string, previous:?string, has_more:bool}
     */
    public function published(?int $categoryId = null, ?string $search = null, ?int $limit = null): array
    {
        $where = $categoryId !== null ? ['category_relid' => $categoryId] : [];

        $counted = $this->live($this->model());
        $this->search($this->conditions($counted, $where), $search);

        $listed = $this->live($this->model());
        $this->search($this->conditions($listed, $where), $search);

        return $this->paginate($listed, $counted, $limit);
    }

    /**
     * The Featured Few, For The Home And Support Pages
     * @param int $limit How Many
     * @return array
     */
    public function featured(int $limit = 6): array
    {
        $rows = $this->live($this->model())
            ->where(['is_featured' => 'yes'])
            ->order('views', self::DESC)
            ->limit($limit)
            ->get();

        // Nothing has been marked featured yet, which is the normal state of a
        // fresh install. Falling back to the most-read articles means the
        // support page is useful before anybody has curated it.
        if ($rows === []) {
            $rows = $this->live($this->model())
                ->order('views', self::DESC)
                ->limit($limit)
                ->get();
        }

        return $rows;
    }

    /**
     * One Article a Visitor May Read
     * @param string $slug Article Slug
     * @return ?array
     */
    public function publishedBySlug(string $slug): ?array
    {
        return $this->live($this->model())->where(['slug' => $slug])->first() ?: null;
    }

    /**
     * Record That An Article Was Read
     *
     * Best effort, and deliberately not allowed to break the page: a failed
     * counter update must never stop somebody reading the help they came for.
     * @param int $articleId Article Id
     * @return void
     */
    public function recordView(int $articleId): void
    {
        $model = $this->model();

        try {
            $row = $model->where([$model->id => $articleId])->first();

            if ($row) {
                $this->model()
                    ->where([$model->id => $articleId])
                    ->update(['views' => (int) ($row['views'] ?? 0) + 1]);
            }
        } catch (\Throwable) {
            // The article still renders. A view count is not worth a 500.
        }
    }

    /**
     * Record a Helpful / Not Helpful Vote
     * @param int $articleId Article Id
     * @param bool $helpful Whether It Helped
     * @return int Rows Affected
     */
    public function recordVote(int $articleId, bool $helpful): int
    {
        $model = $this->model();
        $column = $helpful ? 'votes_helpful' : 'votes_unhelpful';
        $row = $model->where([$model->id => $articleId])->first();

        if (!$row) {
            return 0;
        }

        return $this->model()
            ->where([$model->id => $articleId])
            ->update([$column => (int) ($row[$column] ?? 0) + 1]);
    }

    ####################################################################################
    /*================================== INTERNAL API ================================*/
    ####################################################################################

    /**
     * Constrain a Query To What The Public May See
     *
     * Both halves, for the same reason announcements needs both: `is_active` is
     * the operator's switch, `published_at` is when it starts counting as help.
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
