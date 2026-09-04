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
use LBM\Controller\Webhook\GatewayWebhookController;

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

####################################################################################
/*---------------------------------- WEBHOOKS ------------------------------------*/
####################################################################################
//
// Where payment gateways call back. Declared HERE rather than in front.php for
// two reasons, and the first is load order: route files are read in filename
// order - admin, app, client, front, install - so anything in this file is
// registered before front.php's fallback, and a callback can never be answered
// by a 404 page because something else matched first.
//
// The second is that these are not pages. GatewayWebhookController renders no
// template and extends no Controller; it answers a line of text to a machine.
// Putting it among the public site's routes would invite somebody to give it a
// layout.
//
// THE PREFIX COMES FROM GlobalPipeline::WEBHOOK, aliased rather than repeated.
// That constant is what exempts this URL from the CSRF check, and a literal
// typed twice is a literal that can drift - which is exactly how Phase 20.1's
// module type lists came apart, silently, in both directions. Written this way
// the route and its exemption are the same string or neither works.
//
// POST only. There is nothing to read here, and a GET webhook endpoint is an
// invitation to fire one from an image tag.

Url::post(
    '/' . GlobalPipeline::WEBHOOK . '/{gateway:[a-z0-9\-]+}',
    [GatewayWebhookController::class, 'receive']
)->name('webhook.gateway');
