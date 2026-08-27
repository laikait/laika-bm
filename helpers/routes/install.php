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
use LBM\Controller\Install\InstallController;

####################################################################################
/*----------------------------------- INSTALLER ----------------------------------*/
####################################################################################
//
// The one area with no Auth pipeline - there is nobody to authenticate yet.
// LBM\Pipeline\Install guards it instead: it lets these routes through only
// while lf-storage/lbm/install.lock is absent, and redirects them to the admin
// dashboard once it exists.
//
// Every step is GET to render and POST to apply (instructions 16, 17), and every
// POST is CSRF-checked by GlobalPipeline (instruction 15).
//
// Steps are re-entrant. The wizard derives its position from real state - is the
// database config written, do the tables exist, is there an admin account - and
// never from a step counter in the session, so a refresh or a back-button never
// double-applies anything.
Url::group(Install::SEGMENT, function (): void {

    /** Step 1 - welcome and requirements. No POST: there is nothing to apply. */
    Url::get('/', [InstallController::class, 'requirements'])->name('install');

    /** Step 2 - database credentials. POST tests the connection before writing. */
    Url::get('/database', [InstallController::class, 'database'])->name('install.database');
    Url::post('/database', [InstallController::class, 'database']);

    /** Step 3 - create the tables and seed the lookups. */
    Url::get('/migrate', [InstallController::class, 'migrate'])->name('install.migrate');
    Url::post('/migrate', [InstallController::class, 'migrate']);

    /** Step 4 - company name, URL, timezone, currency, formats. */
    Url::get('/settings', [InstallController::class, 'settings'])->name('install.settings');
    Url::post('/settings', [InstallController::class, 'settings']);

    /** Step 5 - the first staff account and its superadmin role. */
    Url::get('/admin', [InstallController::class, 'admin'])->name('install.admin');
    Url::post('/admin', [InstallController::class, 'admin']);

    /** Step 6 - write the lock file and close the wizard. */
    Url::get('/finish', [InstallController::class, 'finish'])->name('install.finish');
    Url::post('/finish', [InstallController::class, 'finish']);
});
