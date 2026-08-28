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
use LBM\Pipeline\Auth;
use LBM\Filter\ActivityFilter;
use LBM\Controller\Client\AuthController;
use LBM\Controller\Client\DomainController;
use LBM\Controller\Client\TicketController;
use LBM\Controller\Client\InvoiceController;
use LBM\Controller\Client\ProfileController;
use LBM\Controller\Client\ServiceController;
use LBM\Controller\Client\DashboardController;

####################################################################################
/*---------------------------------- CLIENT AREA ---------------------------------*/
####################################################################################
//
// Same URL conventions as the admin area - plural collections, singular members,
// GET to read and POST to change - but no Permission pipeline: a client's reach
// is bounded by ownership, not by a role. Every controller here scopes its query
// to the authenticated client, so an id belonging to somebody else simply is not
// found rather than being found and refused.
//
// Client contacts (sub-logins) sign in through this same area on the `contact`
// guard, and their per-contact permission flags are evaluated against their
// parent client's records.

/** @var string Identifier pattern - uid, never the primary key */
$uid = '[a-zA-Z0-9\-]+';

Url::group(PANEL, function () use ($uid): void {

    /*============================== DASHBOARD ==============================*/
    Url::get('/', [DashboardController::class, 'index'])->name('client.dashboard');

    /*=============================== SERVICES ==============================*/
    Url::get('/services', [ServiceController::class, 'index'])->name('client.services');
    Url::get("/service/{service:{$uid}}", [ServiceController::class, 'show'])->name('client.service');
    Url::post("/service/{service:{$uid}}/cancel", [ServiceController::class, 'cancel'])
        ->name('client.service.cancel');

    /*=============================== INVOICES ==============================*/
    Url::get('/invoices', [InvoiceController::class, 'index'])->name('client.invoices');
    Url::get("/invoice/{invoice:{$uid}}", [InvoiceController::class, 'show'])->name('client.invoice');
    Url::get("/invoice/{invoice:{$uid}}/print", [InvoiceController::class, 'print'])
        ->name('client.invoice.print');
    Url::post("/invoice/{invoice:{$uid}}/pay", [InvoiceController::class, 'pay'])
        ->name('client.invoice.pay');

    /*================================ DOMAINS ==============================*/
    Url::get('/domains', [DomainController::class, 'index'])->name('client.domains');
    Url::get("/domain/{domain:{$uid}}", [DomainController::class, 'show'])->name('client.domain');
    Url::post("/domain/{domain:{$uid}}/nameservers", [DomainController::class, 'nameservers'])
        ->name('client.domain.nameservers');

    /*================================ SUPPORT ==============================*/
    Url::get('/tickets', [TicketController::class, 'index'])->name('client.tickets');
    Url::get('/tickets/new', [TicketController::class, 'create'])->name('client.ticket.new');
    Url::post('/tickets/new', [TicketController::class, 'create']);
    Url::get("/ticket/{ticket:{$uid}}", [TicketController::class, 'show'])->name('client.ticket');
    Url::post("/ticket/{ticket:{$uid}}/reply", [TicketController::class, 'reply'])
        ->name('client.ticket.reply');
    Url::post("/ticket/{ticket:{$uid}}/close", [TicketController::class, 'close'])
        ->name('client.ticket.close');

    /*================================ PROFILE ==============================*/
    Url::get('/profile', [ProfileController::class, 'index'])->name('client.profile');
    Url::post('/profile', [ProfileController::class, 'update']);
    Url::post('/profile/password', [ProfileController::class, 'password'])->name('client.profile.password');
    Url::post('/profile/currency', [ProfileController::class, 'currency'])->name('client.profile.currency');

    /*---------------------------- Sub-Logins -----------------------------*/
    Url::get('/contacts', [ProfileController::class, 'contacts'])->name('client.contacts');
    Url::get('/contacts/new', [ProfileController::class, 'contactCreate'])->name('client.contact.new');
    Url::post('/contacts/new', [ProfileController::class, 'contactCreate']);
    Url::get("/contact/{contact:{$uid}}/edit", [ProfileController::class, 'contactEdit'])
        ->name('client.contact.edit');
    Url::post("/contact/{contact:{$uid}}/edit", [ProfileController::class, 'contactEdit']);
    Url::post("/contact/{contact:{$uid}}/delete", [ProfileController::class, 'contactDelete'])
        ->name('client.contact.delete');

})->pipeline([Auth::class])->filter([ActivityFilter::class]);


####################################################################################
/*------------------------------ PUBLIC CLIENT ROUTES ----------------------------*/
####################################################################################
//
// Declared after the guarded group so applyToPrefix() has already run and cannot
// reach them - see the note in admin.php.
Url::group(PANEL, function (): void {
    Url::get('/login', [AuthController::class, 'login'])->name('client.login');
    Url::post('/login', [AuthController::class, 'login']);

    Url::post('/logout', [AuthController::class, 'logout'])->name('client.logout');

    /** Password reset: request a link, then set a new password with the token. */
    Url::get('/forgot-password', [AuthController::class, 'forgot'])->name('client.forgot');
    Url::post('/forgot-password', [AuthController::class, 'forgot']);
    Url::get('/reset-password/{token:[a-zA-Z0-9]+}', [AuthController::class, 'reset'])->name('client.reset');
    Url::post('/reset-password/{token:[a-zA-Z0-9]+}', [AuthController::class, 'reset']);

    /** Client self-registration, gated by the allow_registration option. */
    Url::get('/register', [AuthController::class, 'register'])->name('client.register');
    Url::post('/register', [AuthController::class, 'register']);
});
