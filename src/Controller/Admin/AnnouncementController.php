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

use Laika\Service\Request;
use LBM\Service\Announcement;

/**
 * Announcements - the operator's news feed, read on the public site.
 *
 * This listing shows EVERYTHING, drafts and future-dated items included, which
 * is why it uses the inherited browse() rather than the public published().
 * A list screen that hid what it had just saved would be lying about the state
 * of the site, and scheduling is only useful if you can see what is scheduled.
 *
 * Two fields decide visibility and they are genuinely different, so the form
 * offers both rather than collapsing them into one "publish" toggle:
 *
 *   is_active    - the operator's switch. Off means retracted.
 *   published_at - when it starts being visible. A future date is written and
 *                  finished but not yet news.
 */
class AnnouncementController extends AdminController
{
    protected function nav(): string
    {
        return 'announcements';
    }

    /**
     * The Announcement List
     * @return string
     */
    public function index(): string
    {
        return $this->screen('announcements', local('announcements'), [
            'pager' =>  Announcement::browse([], $this->search()),
            'now'   =>  date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Write an Announcement
     * @return ?string
     */
    public function create(): ?string
    {
        if (Request::isPost()) {
            $input = Request::inputs();

            if ($this->validate($input)) {
                $id = Announcement::create($this->fields($input, true));
                $row = Announcement::find($id);

                $this->log('announcement.created', 'Added announcement ' . $row['title']);

                return $this->done('staff.announcements', local('announcement_added'));
            }
        }

        return $this->form(null, local('add_announcement'));
    }

    /**
     * Edit an Announcement
     * @param string $announcement Announcement Uid
     * @return ?string
     */
    public function edit(string $announcement): ?string
    {
        $row = $this->record(Announcement::find($announcement), 'announcement');

        if (Request::isPost()) {
            $input = Request::inputs();

            if ($this->validate($input)) {
                Announcement::update((int) $row['id'], $this->fields($input, false));

                $this->log('announcement.updated', 'Updated announcement ' . $row['title']);

                return $this->done('staff.announcements', local('announcement_updated'));
            }
        }

        return $this->form($row, local('edit_named', $row['title']));
    }

    /**
     * Delete an Announcement
     * @param string $announcement Announcement Uid
     * @return ?string
     */
    public function delete(string $announcement): ?string
    {
        $row = $this->record(Announcement::find($announcement), 'announcement');

        Announcement::delete((int) $row['id']);

        $this->log('announcement.deleted', 'Deleted announcement ' . $row['title']);

        return $this->done('staff.announcements', local('announcement_deleted'));
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Render The Form
     * @param ?array $row Existing Row, Or Null To Create
     * @param string $title Page Title
     * @return string
     */
    private function form(?array $row, string $title): string
    {
        return $this->screen('announcement-form', $title, [
            'announcement' =>  $row,
            'editing'      =>  $row !== null,
        ]);
    }

    /**
     * Check What Was Submitted
     * @param array $input Submitted Data
     * @return bool
     */
    private function validate(array $input): bool
    {
        return $this->require([
            'title' =>  local('title_required'),
            'body'  =>  local('body_required'),
        ], $input);
    }

    /**
     * Map The Form Onto Columns
     *
     * `published_at` defaults to now on a new announcement, so an operator who
     * simply writes and saves gets something visible - which is what they meant.
     * Scheduling is then an explicit act rather than a field they have to fill
     * correctly every time.
     * @param array $input Submitted Data
     * @param bool $creating Whether This Is a New Row
     * @return array
     */
    private function fields(array $input, bool $creating): array
    {
        $published = trim((string) ($input['published_at'] ?? ''));

        $data = [
            'title'     =>  trim((string) ($input['title'] ?? '')),
            'body'      =>  (string) ($input['body'] ?? ''),
            'is_active' =>  ($input['is_active'] ?? 'false') === 'true' ? 'yes' : 'no',
        ];

        if ($published !== '') {
            // The datetime-local input gives back "Y-m-dTH:i", which no database
            // driver accepts as a timestamp.
            $data['published_at'] = str_replace('T', ' ', $published) . (strlen($published) === 16 ? ':00' : '');
        } elseif ($creating) {
            $data['published_at'] = date('Y-m-d H:i:s');
        }

        // The uid and created_at are filled by Action::create()/stamp(), which is
        // where every other table gets them - duplicating that here is how two
        // callers end up disagreeing about the format.
        if ($creating) {
            $data['staff_relid'] = (int) (current_staff()['sid'] ?? 0);
        }

        return $data;
    }
}
