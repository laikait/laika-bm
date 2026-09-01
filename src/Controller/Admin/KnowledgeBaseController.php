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

namespace LBM\Controller\Admin;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Laika\Model\Model;
use Laika\Service\Request;
use LBM\Model\KnowledgeBaseCategoryModel;
use LBM\Service\KnowledgeBase;

/**
 * The knowledgebase: help articles and the categories they sit in.
 *
 * Both live on one controller because they are one feature to an operator -
 * nobody manages categories except in order to file articles - and splitting
 * them would mean two sidebar entries for one job.
 *
 * As with announcements, the listing shows everything. `browse()` is the admin
 * view and includes drafts and scheduled articles; `published()` is the public
 * one and applies both the active flag and the publication date.
 *
 * Slugs are generated from the title when the operator leaves the field empty,
 * because a slug is a URL and an operator typing one by hand will eventually
 * type a space. They are unique in the schema, so a collision is resolved here
 * rather than surfacing as a driver error on save.
 */
class KnowledgeBaseController extends AdminController
{
    protected function nav(): string
    {
        return 'knowledgebase';
    }

    ####################################################################################
    /*==================================== ARTICLES ==================================*/
    ####################################################################################

    /**
     * The Article List
     * @return string
     */
    public function index(): string
    {
        return $this->screen('kb-articles', local('knowledgebase'), [
            'pager'      =>  KnowledgeBase::browse(
                $this->conditions(['category' => 'category_relid']),
                $this->search()
            ),
            'categories' =>  $this->categoryChoices(),
            'now'        =>  date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Write an Article
     * @return ?string
     */
    public function create(): ?string
    {
        if (Request::isPost()) {
            $input = Request::inputs();

            if ($this->validateArticle($input)) {
                $id = KnowledgeBase::create($this->articleFields($input, true));
                $row = KnowledgeBase::find($id);

                $this->log('article.created', 'Added article ' . $row['title']);

                return $this->done('staff.kb', local('article_added'));
            }
        }

        return $this->articleForm(null, local('add_article'));
    }

    /**
     * Edit an Article
     * @param string $article Article Uid
     * @return ?string
     */
    public function edit(string $article): ?string
    {
        $row = $this->record(KnowledgeBase::find($article), 'article');

        if (Request::isPost()) {
            $input = Request::inputs();

            if ($this->validateArticle($input, (int) $row['kb_id'])) {
                KnowledgeBase::update((int) $row['kb_id'], $this->articleFields($input, false));

                $this->log('article.updated', 'Updated article ' . $row['title']);

                return $this->done('staff.kb', local('article_updated'));
            }
        }

        return $this->articleForm($row, local('edit_named', $row['title']));
    }

    /**
     * Delete an Article
     * @param string $article Article Uid
     * @return ?string
     */
    public function delete(string $article): ?string
    {
        $row = $this->record(KnowledgeBase::find($article), 'article');

        KnowledgeBase::delete((int) $row['kb_id']);

        $this->log('article.deleted', 'Deleted article ' . $row['title']);

        return $this->done('staff.kb', local('article_deleted'));
    }

    ####################################################################################
    /*=================================== CATEGORIES =================================*/
    ####################################################################################

    /**
     * The Category List
     * @return string
     */
    public function categories(): string
    {
        return $this->screen('kb-categories', local('categories'), [
            'categories' =>  KnowledgeBase::categories(),
            'counts'     =>  KnowledgeBase::articleCounts(),
        ]);
    }

    /**
     * Add a Category
     * @return ?string
     */
    public function categoryCreate(): ?string
    {
        if (Request::isPost()) {
            $input = Request::inputs();

            if ($this->validateCategory($input)) {
                $this->categoryModel()->insert($this->categoryFields($input, true));

                $this->log('kb.category.created', 'Added article category ' . $input['name']);

                return $this->done('staff.kb.categories', local('category_added'));
            }
        }

        return $this->categoryForm(null, local('add_category'));
    }

    /**
     * Edit a Category
     * @param string $category Category Uid
     * @return ?string
     */
    public function categoryEdit(string $category): ?string
    {
        $row = $this->record(KnowledgeBase::category($category), 'category');

        if (Request::isPost()) {
            $input = Request::inputs();

            if ($this->validateCategory($input, (int) $row['kb_cat_id'])) {
                $model = $this->categoryModel();
                $model->where([$model->id => (int) $row['kb_cat_id']])
                    ->update($this->categoryFields($input, false));

                $this->log('kb.category.updated', 'Updated article category ' . $row['name']);

                return $this->done('staff.kb.categories', local('category_updated'));
            }
        }

        return $this->categoryForm($row, local('edit_named', $row['name']));
    }

    /**
     * Delete a Category
     *
     * Articles filed under it are not deleted with it - they lose their category
     * and appear under "all articles" until they are refiled. Removing somebody's
     * written work because they tidied a folder is not a tidy-up, it is a loss.
     * @param string $category Category Uid
     * @return ?string
     */
    public function categoryDelete(string $category): ?string
    {
        $row = $this->record(KnowledgeBase::category($category), 'category');
        $id = (int) $row['kb_cat_id'];

        KnowledgeBase::updateWhere(['category_relid' => $id], ['category_relid' => null]);

        $model = $this->categoryModel();
        $model->where([$model->id => $id])->delete();

        $this->log('kb.category.deleted', 'Deleted article category ' . $row['name']);

        return $this->done('staff.kb.categories', local('category_deleted'));
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * The Category Model
     * @return Model
     */
    private function categoryModel(): Model
    {
        return new KnowledgeBaseCategoryModel();
    }

    /**
     * Categories As Select Choices
     * @return array<int,string>
     */
    private function categoryChoices(): array
    {
        $choices = [];

        foreach (KnowledgeBase::categories() as $row) {
            $choices[(int) $row['kb_cat_id']] = (string) $row['name'];
        }

        return $choices;
    }

    /**
     * Render The Article Form
     * @param ?array $row Existing Row, Or Null To Create
     * @param string $title Page Title
     * @return string
     */
    private function articleForm(?array $row, string $title): string
    {
        return $this->screen('kb-article-form', $title, [
            'article'    =>  $row,
            'editing'    =>  $row !== null,
            'categories' =>  $this->categoryChoices(),
        ]);
    }

    /**
     * Render The Category Form
     * @param ?array $row Existing Row, Or Null To Create
     * @param string $title Page Title
     * @return string
     */
    private function categoryForm(?array $row, string $title): string
    {
        return $this->screen('kb-category-form', $title, [
            'category' =>  $row,
            'editing'  =>  $row !== null,
        ]);
    }

    /**
     * Check An Article
     * @param array $input Submitted Data
     * @param ?int $ignore Article Id To Ignore When Checking The Slug
     * @return bool
     */
    private function validateArticle(array $input, ?int $ignore = null): bool
    {
        $ok = $this->require([
            'title' =>  local('title_required'),
            'body'  =>  local('body_required'),
        ], $input);

        if (!$ok) {
            return false;
        }

        $slug = $this->slug($input, 'title');
        $model = KnowledgeBase::model();
        $clash = $model->where(['slug' => $slug]);

        if ($ignore !== null) {
            $clash->whereNot([$model->id => $ignore]);
        }

        if ($clash->exists()) {
            Request::addError('slug', local('slug_taken', $slug));

            return false;
        }

        return true;
    }

    /**
     * Check a Category
     * @param array $input Submitted Data
     * @param ?int $ignore Category Id To Ignore When Checking The Slug
     * @return bool
     */
    private function validateCategory(array $input, ?int $ignore = null): bool
    {
        if (!$this->require(['name' => local('name_required')], $input)) {
            return false;
        }

        $slug = $this->slug($input, 'name');
        $model = $this->categoryModel();
        $clash = $model->where(['slug' => $slug]);

        if ($ignore !== null) {
            $clash->whereNot([$model->id => $ignore]);
        }

        if ($clash->exists()) {
            Request::addError('slug', local('slug_taken', $slug));

            return false;
        }

        return true;
    }

    /**
     * Map The Article Form Onto Columns
     * @param array $input Submitted Data
     * @param bool $creating Whether This Is a New Row
     * @return array
     */
    private function articleFields(array $input, bool $creating): array
    {
        $published = trim((string) ($input['published_at'] ?? ''));

        $data = [
            'title'          =>  trim((string) ($input['title'] ?? '')),
            'slug'           =>  $this->slug($input, 'title'),
            'body'           =>  (string) ($input['body'] ?? ''),
            'category_relid' =>  (int) ($input['category_relid'] ?? 0) ?: null,
            'is_featured'    =>  ($input['is_featured'] ?? 'false') === 'true' ? 'yes' : 'no',
            'is_active'      =>  ($input['is_active'] ?? 'false') === 'true' ? 'yes' : 'no',
        ];

        if ($published !== '') {
            $data['published_at'] = str_replace('T', ' ', $published)
                . (strlen($published) === 16 ? ':00' : '');
        } elseif ($creating) {
            $data['published_at'] = date('Y-m-d H:i:s');
        }

        if ($creating) {
            $data['staff_relid'] = (int) (current_staff()['sid'] ?? 0);
        }

        return $data;
    }

    /**
     * Map The Category Form Onto Columns
     *
     * kb_categories carries a uid but no created/updated columns, and it is
     * written through the model rather than an Action - so unlike everywhere
     * else the uid has to be stamped here. Action::stamp() is not in this path.
     * @param array $input Submitted Data
     * @param bool $creating Whether This Is a New Row
     * @return array
     */
    private function categoryFields(array $input, bool $creating): array
    {
        $data = [
            'name'      =>  trim((string) ($input['name'] ?? '')),
            'slug'      =>  $this->slug($input, 'name'),
            'parent_id' =>  (int) ($input['parent_id'] ?? 0) ?: null,
            'is_active' =>  ($input['is_active'] ?? 'false') === 'true' ? 'yes' : 'no',
        ];

        if ($creating) {
            $data['uid'] = lbm_uid();
        }

        return $data;
    }

    /**
     * The Slug To Save
     *
     * Taken from the slug field when the operator typed one, and derived from
     * the title otherwise. Deriving is the common case: a slug is a URL, and an
     * operator filling one in by hand will eventually put a space in it.
     * @param array $input Submitted Data
     * @param string $from Field To Derive From When No Slug Was Typed
     * @return string
     */
    private function slug(array $input, string $from): string
    {
        $slug = trim((string) ($input['slug'] ?? ''));

        if ($slug === '') {
            $slug = (string) ($input[$from] ?? '');
        }

        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';

        return trim($slug, '-') ?: 'untitled';
    }
}
