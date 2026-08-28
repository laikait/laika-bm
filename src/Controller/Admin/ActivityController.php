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
use LBM\Service\Activity;

/**
 * The audit trail.
 *
 * Read only, deliberately. An audit trail somebody can edit from the screen it
 * is displayed on is not an audit trail, so there is no create, no update and
 * no delete here - pruning old entries is a maintenance job, not a button.
 */
class ActivityController extends AdminController
{
    protected function nav(): string
    {
        return 'activities';
    }

    /**
     * The Trail
     * @return string
     */
    public function index(): string
    {
        $where = [];

        // Both filters are string columns, so neither is one of the numeric
        // filters conditions() handles.
        $event = trim((string) Request::input('event', ''));

        if ($event !== '') {
            $where['event'] = $event;
        }

        $author = trim((string) Request::input('author', ''));

        if ($author !== '') {
            $where['author_type'] = $author;
        }

        return $this->screen('admin/activities', 'Activity', [
            'pager'   =>  Activity::browseTrail($where, $this->search()),
            'events'  =>  Activity::events(),
            'authors' =>  Activity::authorTypes(),
        ]);
    }
}
