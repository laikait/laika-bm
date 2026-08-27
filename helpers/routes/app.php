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

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Laika\Route\Url;
use Laika\Service\Redirect;
use LBM\Pipeline\Auth;
use LBM\Pipeline\Install;
use LBM\Pipeline\GlobalPipeline;

####################################################################################
/*--------------------------------- GLOBAL SETUP ---------------------------------*/
####################################################################################
//
// Order is deliberate and load-bearing.
//
// Install runs first because it is the only thing that keeps a fresh checkout
// reachable: until the wizard has run there is no database, and it diverts the
// request to /install before anything tries to read one.
//
// GlobalPipeline then boots the request - database, timezone, session, language -
// and CSRF-checks every POST (instructions 5, 15, 21).
Url::globalPipeline([
    Install::class,
    GlobalPipeline::class,
]);

####################################################################################
/*------------------------------------- ROOT -------------------------------------*/
####################################################################################
//
// `/` has to be a real route, not a fallback. Global pipelines only run once a
// route has matched, so an unmatched `/` would 404 on a fresh checkout instead
// of reaching Install and being redirected into the wizard.
//
// Signed-in staff land in the admin area, everybody else in the client area.
Url::get('/', function (): void {
    Redirect::to(Auth::check(ADMIN) ? 'staff.dashboard' : 'client.dashboard');
})->name('home');
