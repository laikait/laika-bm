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

use LBM\Service\Announcement;

/**
 * Public announcements - the operator's news feed.
 *
 * Reads through Announcement::published() rather than the inherited browse(),
 * so a retracted or future-dated row is absent from the query rather than
 * filtered out of the markup.
 *
 * The detail route keys on uid because `announcements` has no slug column -
 * only `uid`. That matches the convention the client area already uses for
 * invoices and tickets. The knowledgebase is the one place that keys on a slug,
 * and for a reason particular to it: those pages are meant to be found and
 * shared from outside.
 */
class AnnouncementController extends FrontController
{
    /**
     * Which Top-Nav Item Is Current
     * @return string
     */
    protected function nav(): string
    {
        return 'announcements';
    }

    /**
     * Every Published Announcement
     * @return string
     */
    public function index(): string
    {
        return $this->screen('announcements', local('announcements'), [
            'meta_description' =>  local('announcements_meta', app_name()),
            'pager'            =>  Announcement::published(),
        ]);
    }

    /**
     * One Announcement
     * @param string $announcement Announcement UID
     * @return string
     */
    public function show(string $announcement): string
    {
        $row = Announcement::publishedByUid($announcement);

        if (!$this->found($row)) {
            return $this->notFound();
        }

        return $this->screen('announcement', (string) $row['title'], [
            // The opening of the body, stripped of markup, as the summary a
            // search engine shows. Truncated on a word boundary so it does not
            // end mid-syllable.
            'meta_description' =>  $this->summarise((string) ($row['body'] ?? '')),
            'announcement'     =>  $row,
        ]);
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
