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
// `/` is declared in helpers/routes/front.php, not here.
//
// It used to be a redirect - staff to the admin dashboard, everybody else to the
// client area - which was right while the application had no public face. The
// front area answers on `/` now, so the root is its home page and the two
// dashboards are a click away in its nav.
//
// It is still a real route rather than a fallback, and for the reason this
// comment originally gave: global pipelines only run once a route has matched,
// so an unmatched `/` would 404 on a fresh checkout instead of reaching Install
// and being redirected into the wizard.
//
// Load order makes the move safe. Route files are read in filename order -
// admin, app, client, front, install - and matching is first-match-wins, so a
// `/` left here would have beaten front.php's and silently kept the old
// redirect.
