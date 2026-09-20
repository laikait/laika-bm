<?php
/**
 * Laika Bill Manager
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: Proprietary - see LICENSE
 * This file is part of Laika Bill Manager.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace LBM\Controller;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Laika\Service\Response;

/**
 * The 404 for the admin and client areas - Phase 50.
 *
 * Until now there was one fallback, on `/`, rendering the FRONT 404. But the
 * language catalogue is chosen from the first URL segment, so a mistyped
 * /admin/... or /panel/... loaded the admin or panel catalogue and rendered a
 * front view with it - and the first front-only key (`page_not_found`) threw.
 * Every unknown staff or client URL was a 500.
 *
 * Each area now has its own fallback (helpers/routes/front.php, beside the
 * public one), rendered in that area's own theme and language, on the auth
 * layout: the centred card needs nobody signed in, which a 404 cannot assume.
 *
 * Extends the plain Controller for the same reason the AuthControllers do - no
 * topbar user, no sidebar, no permission to check.
 */
class AreaErrorController extends Controller
{
    /**
     * @param string $area ADMIN or PANEL
     */
    public function __construct(private readonly string $area)
    {
    }

    /**
     * Nothing Is At That Address
     *
     * The status goes through the Response service, not http_response_code():
     * the renderer writes the service's status last and would overwrite it.
     * @return string
     */
    public function notFound(): string
    {
        Response::setStatus(404);

        return $this->render('404', [
            'page_title'      =>  local('page_not_found'),
            'dashboard_route' =>  $this->area === PANEL ? 'client.dashboard' : 'staff.dashboard',
        ]);
    }

    /**
     * The Area's Own Template, Pinned
     *
     * Pinned rather than read from the URL, like the AuthControllers: this
     * controller is constructed for one area and renders only that area.
     * @return string Example: 'admin/bootstrap'
     */
    protected function theme(): string
    {
        return template_dir($this->area);
    }
}
