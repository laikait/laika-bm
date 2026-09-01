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

namespace LBM\Controller\Front;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Laika\Service\Request;
use LBM\Service\KnowledgeBase;

/**
 * The public knowledgebase: categories, articles, and a search across them.
 *
 * Search is GET, like every other listing in this application, so a result page
 * can be linked and shared - which for a help article is most of the point.
 *
 * The one POST here is the helpfulness vote at the foot of an article, and it
 * is CSRF-checked by GlobalPipeline like every other POST, with no exception
 * made for it being anonymous. A vote is not authenticated - there is nobody to
 * authenticate - but it is still a write, and an unauthenticated write is
 * exactly the kind a cross-site form likes to make.
 */
class KnowledgeBaseController extends FrontController
{
    /**
     * Which Top-Nav Item Is Current
     * @return string
     */
    protected function nav(): string
    {
        return 'knowledgebase';
    }

    /**
     * Categories, Or Search Results Across All Of Them
     * @return string
     */
    public function index(): string
    {
        $search = trim((string) Request::input('search', ''));

        // A search spans every category, so the category grid is replaced by a
        // flat result list rather than shown above it - two different answers
        // to "what am I looking at" on one screen reads as a bug.
        if ($search !== '') {
            return $this->screen('kb-search', local('search_results'), [
                'meta_description' =>  null,
                'search'           =>  $search,
                'pager'            =>  KnowledgeBase::published(null, $search),
            ]);
        }

        return $this->screen('knowledgebase', local('knowledgebase'), [
            'meta_description' =>  local('knowledgebase_meta', app_name()),
            'categories'       =>  KnowledgeBase::categories(true),
            'counts'           =>  KnowledgeBase::articleCounts(),
            'featured'         =>  KnowledgeBase::featured(6),
        ]);
    }

    /**
     * One Category And Its Articles
     * @param string $category Category Slug
     * @return string
     */
    public function category(string $category): string
    {
        $category = KnowledgeBase::categoryBySlug($category, true);

        if (!$this->found($category)) {
            return $this->notFound();
        }

        return $this->screen('kb-category', (string) $category['name'], [
            'meta_description' =>  local('kb_category_meta', (string) $category['name']),
            'category'         =>  $category,
            'pager'            =>  KnowledgeBase::published((int) $category['kb_cat_id']),
        ]);
    }

    /**
     * One Article
     * @param string $article Article Slug
     * @return string
     */
    public function article(string $article): string
    {
        $slug = $article;
        $article = KnowledgeBase::publishedBySlug($slug);

        if (!$this->found($article)) {
            return $this->notFound();
        }

        KnowledgeBase::recordView((int) $article['kb_id']);

        return $this->screen('kb-article', (string) $article['title'], [
            'meta_description' =>  $this->summarise((string) ($article['body'] ?? '')),
            'article'          =>  $article,
            'category'         =>  $article['category_relid']
                ? KnowledgeBase::category((int) $article['category_relid'])
                : null,
        ]);
    }

    /**
     * Record Whether An Article Helped
     * @param string $article Article Slug
     * @return ?string
     */
    public function vote(string $article): ?string
    {
        $slug = $article;
        $article = KnowledgeBase::publishedBySlug($slug);

        if (!$this->found($article)) {
            return $this->notFound();
        }

        KnowledgeBase::recordVote(
            (int) $article['kb_id'],
            Request::input('helpful') === 'yes'
        );

        // Back to the article rather than a bare confirmation: the reader is
        // mid-task, and the vote is a courtesy to us, not a step in their work.
        return $this->done('front.kb.article', local('vote_thanks'), true, ['article' => $slug]);
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * The First Sentence Or So Of a Body, As Plain Text
     * @param string $body Article Body, Possibly With Markup
     * @param int $length Maximum Characters
     * @return string
     */
    private function summarise(string $body, int $length = 160): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '');

        if ($text === '' || mb_strlen($text) <= $length) {
            return $text;
        }

        $cut = mb_substr($text, 0, $length);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false ? mb_substr($cut, 0, $space) : $cut, ' ,.;:') . '...';
    }
}
