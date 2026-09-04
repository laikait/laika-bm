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
use LBM\Controller\Front\CartController;
use LBM\Controller\Front\HomeController;
use LBM\Controller\Front\ErrorController;
use LBM\Controller\Front\SupportController;
use LBM\Controller\Front\ServiceController;
use LBM\Controller\Front\AnnouncementController;
use LBM\Controller\Front\KnowledgeBaseController;

####################################################################################
/*----------------------------------- FRONT AREA ---------------------------------*/
####################################################################################
//
// The public website. Unlike every other area it owns NO URL prefix - it answers
// on `/` and on whatever bare paths are declared below - which makes route
// ordering load-bearing in a way it is nowhere else.
//
// Three facts, all verified against laika-route v2.0.2:
//
//   1. Path::matchRequestRoute() walks routes in REGISTRATION order and returns
//      the FIRST regex that matches. There is no specificity ranking.
//   2. Route files are loaded in filename order - admin, app, client, front,
//      install - so everything with a prefix is already registered by the time
//      this file runs. That ordering is why /admin and /panel stay safe.
//   3. A `{slug}` parameter compiles to `[^/]+` by default, which matches
//      `admin` and `panel` perfectly well.
//
// Taken together: NEVER declare a bare catch-all like Url::get('/{slug}') here.
// It would match /admin, and because this file loads after admin.php it would
// not shadow the admin area today - but it WOULD swallow every future one-
// segment route anybody adds, and it would shadow /admin instantly if the
// filenames ever changed. Every public path below is a literal, or sits under a
// literal prefix. That is the rule; the fallback at the bottom is how an
// unmatched URL is handled instead.

/** @var string Slug pattern - lowercase, digits and hyphens */
$slug = '[a-z0-9\-]+';

/** @var string Identifier pattern - uid, for records with no slug column */
$uid = '[a-zA-Z0-9\-]+';

/*================================ HOME AND PAGES ================================*/
//
// `/` has to be a real route rather than a fallback, and this is the one place
// in the application where that matters. Global pipelines only run once a route
// has MATCHED, so an unmatched `/` would 404 on a fresh checkout instead of
// reaching Install and being redirected into the wizard - which is the very
// first thing a new deployment does.
//
// This replaces the redirect that used to live in app.php, which sent staff to
// the admin dashboard and everybody else to the client area. A public site
// answers here now; the two dashboards are still one click away in the nav.

Url::get('/', [HomeController::class, 'index'])->name('front.home');

Url::get('/about', [HomeController::class, 'about'])->name('front.about');
Url::get('/terms', [HomeController::class, 'terms'])->name('front.terms');
Url::get('/privacy', [HomeController::class, 'privacy'])->name('front.privacy');

/*=================================== SERVICES ===================================*/
//
// Informational. Ordering happens at /cart below, so a product page offers a
// real Add To Cart rather than sending a would-be customer to register first.
//
// The collection is /services and a member is /service/<slug>, singular -
// matching the convention the admin and client areas already use, and keeping
// the two out of each other's way without a regex.

Url::get('/services', [ServiceController::class, 'index'])->name('front.services');
Url::get("/services/{group:{$slug}}", [ServiceController::class, 'group'])->name('front.service.group');
Url::get("/service/{product:{$slug}}", [ServiceController::class, 'show'])->name('front.service');

/*==================================== ORDERING ==================================*/
//
// Cart and checkout. Every path here is a literal below /cart, so none of them
// can be reached by a `{slug}` belonging to something else, and none of them
// needs the ordering care the sections above and below do.
//
// GET reads the cart, POST changes it - the same split the rest of the
// application uses, and the reason there is no `/cart/add/{product}` link. An
// "add to cart" that a GET can perform is one an image tag on somebody else's
// page can perform, and a cart filled by a stranger is a checkout screen that
// lies about what was chosen.
//
// No Auth pipeline. A cart belongs to a browser, not to an account, so a
// visitor fills one and signs in at checkout - which is where the account
// becomes necessary, because an order has a client on it. CartController::
// checkout() sends an unauthenticated visitor to sign in, and AuthController
// brings them back here rather than to the dashboard.

Url::get('/cart', [CartController::class, 'index'])->name('front.cart');

Url::post('/cart/add', [CartController::class, 'add'])->name('front.cart.add');
Url::post('/cart/update', [CartController::class, 'update'])->name('front.cart.update');
Url::post('/cart/remove', [CartController::class, 'remove'])->name('front.cart.remove');
Url::post('/cart/clear', [CartController::class, 'clear'])->name('front.cart.clear');
Url::post('/cart/checkout', [CartController::class, 'checkout'])->name('front.cart.checkout');

/*================================= KNOWLEDGEBASE ================================*/
//
// The one area that keys public URLs on a SLUG rather than a uid. Everything
// else in this application uses uids deliberately, because a guessable id in a
// URL invites trying the next one along. A help article is public by definition,
// so there is nothing to enumerate, and `/knowledgebase/article/how-to-pay` is
// worth having where an opaque uid is not.
//
// `/knowledgebase/article/...` is declared BEFORE `/knowledgebase/{category}`.
// Both match a two-segment path, first-match-wins, so the literal has to come
// first or every article URL would resolve as a category named "article".

Url::get('/knowledgebase', [KnowledgeBaseController::class, 'index'])->name('front.knowledgebase');

Url::get("/knowledgebase/article/{article:{$slug}}", [KnowledgeBaseController::class, 'article'])
    ->name('front.kb.article');

Url::post("/knowledgebase/article/{article:{$slug}}/vote", [KnowledgeBaseController::class, 'vote'])
    ->name('front.kb.vote');

Url::get("/knowledgebase/{category:{$slug}}", [KnowledgeBaseController::class, 'category'])
    ->name('front.kb.category');

/*================================= ANNOUNCEMENTS ================================*/
//
// Keyed on uid: `announcements` has no slug column, only `uid`. Adding one would
// be a schema change for a cosmetic gain, so this follows the convention the
// client area already uses for invoices and tickets.

Url::get('/announcements', [AnnouncementController::class, 'index'])->name('front.announcements');
Url::get("/announcements/{announcement:{$uid}}", [AnnouncementController::class, 'show'])
    ->name('front.announcement');

/*=================================== SUPPORT ====================================*/
//
// The contact form is the only public write in the application. It is a POST and
// so is CSRF-checked by GlobalPipeline like every other, with no exception made
// for the sender being anonymous - an unauthenticated write is precisely the
// kind a cross-site form likes to make.

Url::get('/support', [SupportController::class, 'index'])->name('front.support');

Url::get('/contact', [SupportController::class, 'contact'])->name('front.contact');
Url::post('/contact', [SupportController::class, 'contact']);

/*==================================== FALLBACK ==================================*/
//
// Anything that matched nothing above, and nothing in admin.php, app.php,
// client.php or install.php either.
//
// The pipelines are named EXPLICITLY, and that is not belt and braces:
// Dispatcher::dispatchFallback() runs only the pipelines the fallback itself
// declares. It does NOT merge in the global ones the way dispatch() does. Drop
// them and no language catalogue is loaded, so the first local() in the 404 view
// throws `RuntimeException: 'LANG' Class Doesn't Exists!` and the visitor gets a
// 500 where they should have had a 404.
//
// Install comes first for the same reason it does globally: on a fresh checkout
// a mistyped URL should land in the wizard, not on a 404 rendered out of a
// template whose settings cannot be read yet.

Url::fallback(null, static function (): string {
    return (new ErrorController())->notFound();
}, [Install::class, GlobalPipeline::class]);
