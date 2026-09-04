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
use LBM\Pipeline\Permission;
use LBM\Filter\ActivityFilter;
use LBM\Controller\Admin\AuthController;
use LBM\Controller\Admin\StaffController;
use LBM\Controller\Admin\OrderController;
use LBM\Controller\Admin\ClientController;
use LBM\Controller\Admin\DomainController;
use LBM\Controller\Admin\ModuleController;
use LBM\Controller\Admin\ReportController;
use LBM\Controller\Admin\GatewayController;
use LBM\Controller\Admin\UtilController;
use LBM\Controller\Admin\ServerController;
use LBM\Controller\Admin\TicketController;
use LBM\Controller\Admin\InvoiceController;
use LBM\Controller\Admin\ProductController;
use LBM\Controller\Admin\ProfileController;
use LBM\Controller\Admin\AnnouncementController;
use LBM\Controller\Admin\KnowledgeBaseController;
use LBM\Controller\Admin\ActivityController;
use LBM\Controller\Admin\CurrencyController;
use LBM\Controller\Admin\SettingsController;
use LBM\Controller\Admin\DashboardController;
use LBM\Controller\Admin\TransactionController;

####################################################################################
/*--------------------------------- ADMIN PANEL ----------------------------------*/
####################################################################################
//
// Conventions used throughout, so a reader can predict any URL from any other:
//
//   /admin/<plural>              GET   collection, searched and filtered by query
//   /admin/<plural>/new          GET   blank form          POST  create
//   /admin/<singular>/{uid}      GET   single record
//   /admin/<singular>/{uid}/edit GET   filled form         POST  update
//   /admin/<singular>/{uid}/...  POST  a named state change
//
// Plural for collections and singular for members is what keeps `/clients/new`
// from ever colliding with a record identifier.
//
// GET for every search and listing (instruction 17), POST for every mutation
// (instruction 16), CSRF-checked centrally in GlobalPipeline (instruction 15).
//
// Records are addressed by `uid`, never by the auto-increment primary key, so a
// URL leaks nothing about how many clients or invoices exist.
//
// Controllers are referenced as [Class::class, 'method']. A bare 'Foo@bar'
// string would be resolved against App\Controller\ by Invoke::controller(), which
// is the app root - not this package.
//
// ActivityFilter is attached to the same group. Filters run on the way out,
// after the controller has returned, so it only records requests that actually
// completed - and it writes a generic entry only where the action recorded
// nothing more specific of its own.
//
// One thing to know before adding a route here: the group's Auth pipeline is
// attached by Handler::applyToPrefix() *after* this closure has run, so it is
// appended behind any per-route pipeline. A guarded route's real chain is
// [Permission, Auth], not [Auth, Permission]. LBM\Pipeline\Permission is written
// to resolve the staff member itself rather than to depend on that order, so
// nothing here has to work around it.

/** @var string Identifier pattern - uid, never the primary key */
$uid = '[a-zA-Z0-9\-]+';

Url::group(ADMIN, function () use ($uid): void {

    /*============================== DASHBOARD ==============================*/
    Url::get('/', [DashboardController::class, 'index'])->name('staff.dashboard');

    /*=============================== CLIENTS ===============================*/
    Url::get('/clients', [ClientController::class, 'index'])
        ->name('staff.clients')->pipeline([Permission::class . '|perm=client.read']);

    Url::get('/clients/new', [ClientController::class, 'create'])
        ->name('staff.client.new')->pipeline([Permission::class . '|perm=client.create']);
    Url::post('/clients/new', [ClientController::class, 'create'])
        ->pipeline([Permission::class . '|perm=client.create']);

    Url::get("/client/{client:{$uid}}", [ClientController::class, 'show'])
        ->name('staff.client')->pipeline([Permission::class . '|perm=client.read']);

    Url::get("/client/{client:{$uid}}/edit", [ClientController::class, 'edit'])
        ->name('staff.client.edit')->pipeline([Permission::class . '|perm=client.update']);
    Url::post("/client/{client:{$uid}}/edit", [ClientController::class, 'edit'])
        ->pipeline([Permission::class . '|perm=client.update']);

    Url::post("/client/{client:{$uid}}/delete", [ClientController::class, 'delete'])
        ->name('staff.client.delete')->pipeline([Permission::class . '|perm=client.delete']);

    /*---------------------------- Client Contacts ----------------------------*/
    Url::get("/client/{client:{$uid}}/contacts", [ClientController::class, 'contacts'])
        ->name('staff.client.contacts')->pipeline([Permission::class . '|perm=client.read']);

    Url::get("/client/{client:{$uid}}/contacts/new", [ClientController::class, 'contactCreate'])
        ->name('staff.client.contact.new')->pipeline([Permission::class . '|perm=client.create']);
    Url::post("/client/{client:{$uid}}/contacts/new", [ClientController::class, 'contactCreate'])
        ->pipeline([Permission::class . '|perm=client.create']);

    Url::get("/client/{client:{$uid}}/contact/{contact:{$uid}}/edit", [ClientController::class, 'contactEdit'])
        ->name('staff.client.contact.edit')->pipeline([Permission::class . '|perm=client.update']);
    Url::post("/client/{client:{$uid}}/contact/{contact:{$uid}}/edit", [ClientController::class, 'contactEdit'])
        ->pipeline([Permission::class . '|perm=client.update']);

    Url::post("/client/{client:{$uid}}/contact/{contact:{$uid}}/delete", [ClientController::class, 'contactDelete'])
        ->name('staff.client.contact.delete')->pipeline([Permission::class . '|perm=client.delete']);

    /*------------------------------ Client Notes -----------------------------*/
    Url::get("/client/{client:{$uid}}/notes", [ClientController::class, 'notes'])
        ->name('staff.client.notes')->pipeline([Permission::class . '|perm=note.read']);
    Url::post("/client/{client:{$uid}}/notes", [ClientController::class, 'noteCreate'])
        ->pipeline([Permission::class . '|perm=note.create']);
    Url::post("/client/{client:{$uid}}/note/{note:{$uid}}/delete", [ClientController::class, 'noteDelete'])
        ->name('staff.client.note.delete')->pipeline([Permission::class . '|perm=note.delete']);

    /*=============================== PRODUCTS ==============================*/
    Url::get('/products', [ProductController::class, 'index'])
        ->name('staff.products')->pipeline([Permission::class . '|perm=product.read']);

    Url::get('/products/new', [ProductController::class, 'create'])
        ->name('staff.product.new')->pipeline([Permission::class . '|perm=product.create']);
    Url::post('/products/new', [ProductController::class, 'create'])
        ->pipeline([Permission::class . '|perm=product.create']);

    Url::get("/product/{product:{$uid}}", [ProductController::class, 'show'])
        ->name('staff.product')->pipeline([Permission::class . '|perm=product.read']);

    Url::get("/product/{product:{$uid}}/edit", [ProductController::class, 'edit'])
        ->name('staff.product.edit')->pipeline([Permission::class . '|perm=product.update']);
    Url::post("/product/{product:{$uid}}/edit", [ProductController::class, 'edit'])
        ->pipeline([Permission::class . '|perm=product.update']);

    Url::post("/product/{product:{$uid}}/delete", [ProductController::class, 'delete'])
        ->name('staff.product.delete')->pipeline([Permission::class . '|perm=product.delete']);

    /*--------------------------- Product Groups ---------------------------*/
    Url::get('/product-groups', [ProductController::class, 'groups'])
        ->name('staff.product.groups')->pipeline([Permission::class . '|perm=product.read']);
    Url::post('/product-groups', [ProductController::class, 'groupSave'])
        ->pipeline([Permission::class . '|perm=product.update']);
    Url::post("/product-group/{group:{$uid}}/delete", [ProductController::class, 'groupDelete'])
        ->name('staff.product.group.delete')->pipeline([Permission::class . '|perm=product.delete']);

    /*================================ ORDERS ===============================*/
    Url::get('/orders', [OrderController::class, 'index'])
        ->name('staff.orders')->pipeline([Permission::class . '|perm=order.read']);

    Url::get('/orders/new', [OrderController::class, 'create'])
        ->name('staff.order.new')->pipeline([Permission::class . '|perm=order.create']);
    Url::post('/orders/new', [OrderController::class, 'create'])
        ->pipeline([Permission::class . '|perm=order.create']);

    Url::get("/order/{order:{$uid}}", [OrderController::class, 'show'])
        ->name('staff.order')->pipeline([Permission::class . '|perm=order.read']);

    Url::get("/order/{order:{$uid}}/edit", [OrderController::class, 'edit'])
        ->name('staff.order.edit')->pipeline([Permission::class . '|perm=order.update']);
    Url::post("/order/{order:{$uid}}/edit", [OrderController::class, 'edit'])
        ->pipeline([Permission::class . '|perm=order.update']);

    Url::post("/order/{order:{$uid}}/accept", [OrderController::class, 'accept'])
        ->name('staff.order.accept')->pipeline([Permission::class . '|perm=order.update']);
    Url::post("/order/{order:{$uid}}/cancel", [OrderController::class, 'cancel'])
        ->name('staff.order.cancel')->pipeline([Permission::class . '|perm=order.update']);
    Url::post("/order/{order:{$uid}}/delete", [OrderController::class, 'delete'])
        ->name('staff.order.delete')->pipeline([Permission::class . '|perm=order.delete']);

    /*=============================== INVOICES ==============================*/
    Url::get('/invoices', [InvoiceController::class, 'index'])
        ->name('staff.invoices')->pipeline([Permission::class . '|perm=invoice.read']);

    Url::get('/invoices/new', [InvoiceController::class, 'create'])
        ->name('staff.invoice.new')->pipeline([Permission::class . '|perm=invoice.create']);
    Url::post('/invoices/new', [InvoiceController::class, 'create'])
        ->pipeline([Permission::class . '|perm=invoice.create']);

    Url::get("/invoice/{invoice:{$uid}}", [InvoiceController::class, 'show'])
        ->name('staff.invoice')->pipeline([Permission::class . '|perm=invoice.read']);

    Url::get("/invoice/{invoice:{$uid}}/edit", [InvoiceController::class, 'edit'])
        ->name('staff.invoice.edit')->pipeline([Permission::class . '|perm=invoice.update']);
    Url::post("/invoice/{invoice:{$uid}}/edit", [InvoiceController::class, 'edit'])
        ->pipeline([Permission::class . '|perm=invoice.update']);

    Url::get("/invoice/{invoice:{$uid}}/print", [InvoiceController::class, 'print'])
        ->name('staff.invoice.print')->pipeline([Permission::class . '|perm=invoice.read']);

    Url::post("/invoice/{invoice:{$uid}}/send", [InvoiceController::class, 'send'])
        ->name('staff.invoice.send')->pipeline([Permission::class . '|perm=invoice.update']);
    Url::post("/invoice/{invoice:{$uid}}/pay", [InvoiceController::class, 'pay'])
        ->name('staff.invoice.pay')->pipeline([Permission::class . '|perm=transaction.create']);
    Url::post("/invoice/{invoice:{$uid}}/cancel", [InvoiceController::class, 'cancel'])
        ->name('staff.invoice.cancel')->pipeline([Permission::class . '|perm=invoice.update']);
    Url::post("/invoice/{invoice:{$uid}}/delete", [InvoiceController::class, 'delete'])
        ->name('staff.invoice.delete')->pipeline([Permission::class . '|perm=invoice.delete']);

    /*============================= TRANSACTIONS ============================*/
    Url::get('/transactions', [TransactionController::class, 'index'])
        ->name('staff.transactions')->pipeline([Permission::class . '|perm=transaction.read']);

    Url::get('/transactions/new', [TransactionController::class, 'create'])
        ->name('staff.transaction.new')->pipeline([Permission::class . '|perm=transaction.create']);
    Url::post('/transactions/new', [TransactionController::class, 'create'])
        ->pipeline([Permission::class . '|perm=transaction.create']);

    Url::get("/transaction/{transaction:{$uid}}", [TransactionController::class, 'show'])
        ->name('staff.transaction')->pipeline([Permission::class . '|perm=transaction.read']);

    Url::post("/transaction/{transaction:{$uid}}/refund", [TransactionController::class, 'refund'])
        ->name('staff.transaction.refund')->pipeline([Permission::class . '|perm=transaction.update']);
    Url::post("/transaction/{transaction:{$uid}}/delete", [TransactionController::class, 'delete'])
        ->name('staff.transaction.delete')->pipeline([Permission::class . '|perm=transaction.delete']);

    /*=============================== SUPPORT ===============================*/
    Url::get('/tickets', [TicketController::class, 'index'])
        ->name('staff.tickets')->pipeline([Permission::class . '|perm=ticket.read']);

    Url::get('/tickets/new', [TicketController::class, 'create'])
        ->name('staff.ticket.new')->pipeline([Permission::class . '|perm=ticket.create']);
    Url::post('/tickets/new', [TicketController::class, 'create'])
        ->pipeline([Permission::class . '|perm=ticket.create']);

    Url::get("/ticket/{ticket:{$uid}}", [TicketController::class, 'show'])
        ->name('staff.ticket')->pipeline([Permission::class . '|perm=ticket.read']);
    Url::post("/ticket/{ticket:{$uid}}/reply", [TicketController::class, 'reply'])
        ->name('staff.ticket.reply')->pipeline([Permission::class . '|perm=ticket.update']);
    Url::post("/ticket/{ticket:{$uid}}/status", [TicketController::class, 'status'])
        ->name('staff.ticket.status')->pipeline([Permission::class . '|perm=ticket.update']);
    Url::post("/ticket/{ticket:{$uid}}/delete", [TicketController::class, 'delete'])
        ->name('staff.ticket.delete')->pipeline([Permission::class . '|perm=ticket.delete']);

    Url::get('/ticket-departments', [TicketController::class, 'departments'])
        ->name('staff.ticket.departments')->pipeline([Permission::class . '|perm=ticket.read']);
    Url::post('/ticket-departments', [TicketController::class, 'departmentSave'])
        ->pipeline([Permission::class . '|perm=ticket.update']);

    /*=============================== DOMAINS ===============================*/
    Url::get('/domains', [DomainController::class, 'index'])
        ->name('staff.domains')->pipeline([Permission::class . '|perm=domain.read']);
    Url::get("/domain/{domain:{$uid}}", [DomainController::class, 'show'])
        ->name('staff.domain')->pipeline([Permission::class . '|perm=domain.read']);
    Url::get("/domain/{domain:{$uid}}/edit", [DomainController::class, 'edit'])
        ->name('staff.domain.edit')->pipeline([Permission::class . '|perm=domain.update']);
    Url::post("/domain/{domain:{$uid}}/edit", [DomainController::class, 'edit'])
        ->pipeline([Permission::class . '|perm=domain.update']);

    /*=============================== SERVERS ===============================*/
    Url::get('/servers', [ServerController::class, 'index'])
        ->name('staff.servers')->pipeline([Permission::class . '|perm=server.read']);

    Url::get('/servers/new', [ServerController::class, 'create'])
        ->name('staff.server.new')->pipeline([Permission::class . '|perm=server.create']);
    Url::post('/servers/new', [ServerController::class, 'create'])
        ->pipeline([Permission::class . '|perm=server.create']);

    Url::get("/server/{server:{$uid}}/edit", [ServerController::class, 'edit'])
        ->name('staff.server.edit')->pipeline([Permission::class . '|perm=server.update']);
    Url::post("/server/{server:{$uid}}/edit", [ServerController::class, 'edit'])
        ->pipeline([Permission::class . '|perm=server.update']);

    Url::post("/server/{server:{$uid}}/test", [ServerController::class, 'test'])
        ->name('staff.server.test')->pipeline([Permission::class . '|perm=server.update']);
    Url::post("/server/{server:{$uid}}/delete", [ServerController::class, 'delete'])
        ->name('staff.server.delete')->pipeline([Permission::class . '|perm=server.delete']);

    /*============================== CURRENCIES =============================*/
    Url::get('/currencies', [CurrencyController::class, 'index'])
        ->name('staff.currencies')->pipeline([Permission::class . '|perm=currency.read']);
    Url::post('/currencies', [CurrencyController::class, 'save'])
        ->pipeline([Permission::class . '|perm=currency.update']);
    Url::post("/currency/{currency:{$uid}}/default", [CurrencyController::class, 'makeDefault'])
        ->name('staff.currency.default')->pipeline([Permission::class . '|perm=currency.update']);
    Url::post("/currency/{currency:{$uid}}/delete", [CurrencyController::class, 'delete'])
        ->name('staff.currency.delete')->pipeline([Permission::class . '|perm=currency.delete']);

    /*================================ STAFF ================================*/
    Url::get('/staffs', [StaffController::class, 'index'])
        ->name('staff.staffs')->pipeline([Permission::class . '|perm=staff.read']);

    Url::get('/staffs/new', [StaffController::class, 'create'])
        ->name('staff.staff.new')->pipeline([Permission::class . '|perm=staff.create']);
    Url::post('/staffs/new', [StaffController::class, 'create'])
        ->pipeline([Permission::class . '|perm=staff.create']);

    Url::get("/staff/{staff:{$uid}}", [StaffController::class, 'show'])
        ->name('staff.staff')->pipeline([Permission::class . '|perm=staff.read']);

    Url::get("/staff/{staff:{$uid}}/edit", [StaffController::class, 'edit'])
        ->name('staff.staff.edit')->pipeline([Permission::class . '|perm=staff.update']);
    Url::post("/staff/{staff:{$uid}}/edit", [StaffController::class, 'edit'])
        ->pipeline([Permission::class . '|perm=staff.update']);

    Url::post("/staff/{staff:{$uid}}/delete", [StaffController::class, 'delete'])
        ->name('staff.staff.delete')->pipeline([Permission::class . '|perm=staff.delete']);

    /*-------------------------- Roles & Permissions ------------------------*/
    Url::get('/roles', [StaffController::class, 'roles'])
        ->name('staff.roles')->pipeline([Permission::class . '|perm=role.read']);

    Url::get('/roles/new', [StaffController::class, 'roleCreate'])
        ->name('staff.role.new')->pipeline([Permission::class . '|perm=role.create']);
    Url::post('/roles/new', [StaffController::class, 'roleCreate'])
        ->pipeline([Permission::class . '|perm=role.create']);

    /** The group x action permission matrix (Permission instructions 1-2). */
    Url::get("/role/{role:{$uid}}/edit", [StaffController::class, 'roleEdit'])
        ->name('staff.role.edit')->pipeline([Permission::class . '|perm=role.update']);
    Url::post("/role/{role:{$uid}}/edit", [StaffController::class, 'roleEdit'])
        ->pipeline([Permission::class . '|perm=role.update']);

    Url::post("/role/{role:{$uid}}/delete", [StaffController::class, 'roleDelete'])
        ->name('staff.role.delete')->pipeline([Permission::class . '|perm=role.delete']);

    /*=============================== REPORTS ===============================*/
    Url::get('/reports', [ReportController::class, 'index'])
        ->name('staff.reports')->pipeline([Permission::class . '|perm=report.read']);
    Url::get('/reports/income', [ReportController::class, 'income'])
        ->name('staff.report.income')->pipeline([Permission::class . '|perm=report.read']);
    Url::get('/reports/orders', [ReportController::class, 'orders'])
        ->name('staff.report.orders')->pipeline([Permission::class . '|perm=report.read']);
    Url::get('/reports/tickets', [ReportController::class, 'tickets'])
        ->name('staff.report.tickets')->pipeline([Permission::class . '|perm=report.read']);
    Url::get('/reports/annual', [ReportController::class, 'annual'])
        ->name('staff.report.annual')->pipeline([Permission::class . '|perm=report.read']);
    Url::get('/reports/clients', [ReportController::class, 'clients'])
        ->name('staff.report.clients')->pipeline([Permission::class . '|perm=report.read']);
    Url::get('/reports/performance', [ReportController::class, 'performance'])
        ->name('staff.report.performance')->pipeline([Permission::class . '|perm=report.read']);
    Url::get('/reports/feedback', [ReportController::class, 'feedback'])
        ->name('staff.report.feedback')->pipeline([Permission::class . '|perm=report.read']);

    /*=============================== UTILITIES =============================*/
    //
    // Gated on `settings` rather than a permission group of their own. A new
    // group in Permission::GROUPS is granted to a role only when the role is
    // created, so adding one would hide every screen below from every install
    // that already exists. UtilController explains the reasoning in full.
    //
    // Reading is settings.read; anything that changes the installation is
    // settings.update - and that split is the whole reason the migrate and the
    // version check are separate routes rather than one screen with two buttons.
    Url::get('/utils', [UtilController::class, 'index'])
        ->name('staff.utils')->pipeline([Permission::class . '|perm=settings.read']);
    Url::get('/utils/system', [UtilController::class, 'system'])
        ->name('staff.util.system')->pipeline([Permission::class . '|perm=settings.read']);
    Url::get('/utils/automation', [UtilController::class, 'automation'])
        ->name('staff.util.automation')->pipeline([Permission::class . '|perm=settings.read']);
    Url::get('/utils/update', [UtilController::class, 'update'])
        ->name('staff.util.update')->pipeline([Permission::class . '|perm=settings.read']);
    Url::get('/utils/todos', [UtilController::class, 'todos'])
        ->name('staff.util.todos')->pipeline([Permission::class . '|perm=settings.read']);

    Url::post('/utils/update/check', [UtilController::class, 'check'])
        ->name('staff.util.check')->pipeline([Permission::class . '|perm=settings.update']);
    Url::post('/utils/update/migrate', [UtilController::class, 'migrate'])
        ->name('staff.util.migrate')->pipeline([Permission::class . '|perm=settings.update']);

    // The literal route registers before its parameterised sibling: matching is
    // first-match-wins in registration order with no specificity ranking, and
    // {todo} matches the literal word "add" perfectly well.
    Url::post('/utils/todos/add', [UtilController::class, 'addTodo'])
        ->name('staff.util.todo.add')->pipeline([Permission::class . '|perm=settings.read']);
    Url::post("/utils/todo/{todo:{$uid}}/toggle", [UtilController::class, 'toggleTodo'])
        ->name('staff.util.todo.toggle')->pipeline([Permission::class . '|perm=settings.read']);
    Url::post("/utils/todo/{todo:{$uid}}/delete", [UtilController::class, 'deleteTodo'])
        ->name('staff.util.todo.delete')->pipeline([Permission::class . '|perm=settings.read']);

    /*========================== PAYMENT GATEWAYS ===========================*/
    //
    // settings.read to look, settings.update to change - no new permission
    // group, because Permission::GROUPS is granted to a role only when the role
    // is CREATED, so a new group would be invisible on every installation that
    // already exists. The same decision the utilities screens above took.
    Url::get('/gateways', [GatewayController::class, 'index'])
        ->name('staff.gateways')->pipeline([Permission::class . '|perm=settings.read']);

    // Before /gateway/{gateway}/... for the usual reason, and before
    // /gateways/configure is irrelevant because that one is a POST. A GET
    // listing, so it is filterable and bookmarkable.
    Url::get('/gateways/callbacks', [GatewayController::class, 'callbacks'])
        ->name('staff.gateway.callbacks')->pipeline([Permission::class . '|perm=settings.read']);
    // Literal before parameterised: matching is first-match-wins in registration
    // order, and {gateway} matches the word "configure" perfectly well.
    Url::post('/gateways/configure', [GatewayController::class, 'configure'])
        ->name('staff.gateway.configure')->pipeline([Permission::class . '|perm=settings.update']);
    Url::post("/gateway/{gateway:{$uid}}/settings", [GatewayController::class, 'settings'])
        ->name('staff.gateway.settings')->pipeline([Permission::class . '|perm=settings.update']);
    Url::post("/gateway/{gateway:{$uid}}/toggle", [GatewayController::class, 'toggle'])
        ->name('staff.gateway.toggle')->pipeline([Permission::class . '|perm=settings.update']);
    Url::post("/gateway/{gateway:{$uid}}/delete", [GatewayController::class, 'delete'])
        ->name('staff.gateway.delete')->pipeline([Permission::class . '|perm=settings.update']);

    /*============================== ACTIVITIES =============================*/
    Url::get('/activities', [ActivityController::class, 'index'])
        ->name('staff.activities')->pipeline([Permission::class . '|perm=activity.read']);

    /*=============================== MODULES ===============================*/
    Url::get('/modules', [ModuleController::class, 'index'])
        ->name('staff.modules')->pipeline([Permission::class . '|perm=module.read']);
    // Registered ahead of the parameterised sibling as a matter of habit. These
    // two cannot actually collide - /module/upload is two segments and the
    // toggle route is three - but literal-before-parameterised is the rule that
    // keeps this file safe to add to, since matching is first-match-wins and
    // $uid happily matches a word like "upload".
    Url::post('/module/upload', [ModuleController::class, 'upload'])
        ->name('staff.module.upload')->pipeline([Permission::class . '|perm=module.create']);
    Url::post("/module/{module:{$uid}}/toggle", [ModuleController::class, 'toggle'])
        ->name('staff.module.toggle')->pipeline([Permission::class . '|perm=module.update']);

    /*============================== SETTINGS ===============================*/
    //
    // One GET per tab, one POST per tab. Settings are option rows, and option()
    // memoises per key for the whole request - so every save ends in a redirect
    // rather than re-rendering, or the form would show the value it just replaced.
    Url::get('/settings', [SettingsController::class, 'general'])
        ->name('staff.settings')->pipeline([Permission::class . '|perm=settings.read']);
    Url::post('/settings', [SettingsController::class, 'general'])
        ->pipeline([Permission::class . '|perm=settings.update']);

    Url::get('/settings/localisation', [SettingsController::class, 'localisation'])
        ->name('staff.settings.localisation')->pipeline([Permission::class . '|perm=settings.read']);
    Url::post('/settings/localisation', [SettingsController::class, 'localisation'])
        ->pipeline([Permission::class . '|perm=settings.update']);

    Url::get('/settings/billing', [SettingsController::class, 'billing'])
        ->name('staff.settings.billing')->pipeline([Permission::class . '|perm=settings.read']);
    Url::post('/settings/billing', [SettingsController::class, 'billing'])
        ->pipeline([Permission::class . '|perm=settings.update']);

    Url::get('/settings/security', [SettingsController::class, 'security'])
        ->name('staff.settings.security')->pipeline([Permission::class . '|perm=settings.read']);
    Url::post('/settings/security', [SettingsController::class, 'security'])
        ->pipeline([Permission::class . '|perm=settings.update']);

    Url::get('/settings/mail', [SettingsController::class, 'mail'])
        ->name('staff.settings.mail')->pipeline([Permission::class . '|perm=settings.read']);
    Url::post('/settings/mail', [SettingsController::class, 'mail'])
        ->pipeline([Permission::class . '|perm=settings.update']);

    /** Queues one message through the same path a real notification takes. */
    Url::post('/settings/mail/test', [SettingsController::class, 'mailTest'])
        ->name('staff.settings.mail.test')->pipeline([Permission::class . '|perm=settings.update']);

    Url::get('/settings/email-templates', [SettingsController::class, 'emailTemplates'])
        ->name('staff.settings.templates')->pipeline([Permission::class . '|perm=settings.read']);

    /**
     * Before the {template} routes, and it has to stay there. Matching is
     * first-match-wins in registration order with no specificity ranking, and
     * $uid is [a-zA-Z0-9\-]+ - which matches "new". Registered after, this route
     * would never be reached and the create form would 404 as a missing record.
     */
    Url::get('/settings/email-template/new', [SettingsController::class, 'emailTemplateCreate'])
        ->name('staff.settings.template.new')->pipeline([Permission::class . '|perm=settings.update']);
    Url::post('/settings/email-template/new', [SettingsController::class, 'emailTemplateCreate'])
        ->pipeline([Permission::class . '|perm=settings.update']);

    Url::get("/settings/email-template/{template:{$uid}}", [SettingsController::class, 'emailTemplate'])
        ->name('staff.settings.template')->pipeline([Permission::class . '|perm=settings.read']);
    Url::post("/settings/email-template/{template:{$uid}}", [SettingsController::class, 'emailTemplate'])
        ->pipeline([Permission::class . '|perm=settings.update']);
    Url::post("/settings/email-template/{template:{$uid}}/delete", [SettingsController::class, 'emailTemplateDelete'])
        ->name('staff.settings.template.delete')->pipeline([Permission::class . '|perm=settings.update']);

    Url::get('/settings/statuses', [SettingsController::class, 'statuses'])
        ->name('staff.settings.statuses')->pipeline([Permission::class . '|perm=settings.read']);
    Url::post('/settings/statuses', [SettingsController::class, 'statuses'])
        ->pipeline([Permission::class . '|perm=settings.update']);

    /*============================== MY ACCOUNT =============================*/
    //
    // No permission pipeline: everybody may manage their own account, whatever
    // their role grants them over other people's.
    Url::get('/my-account', [ProfileController::class, 'index'])->name('staff.account');
    Url::post('/my-account', [ProfileController::class, 'update']);
    Url::post('/my-account/password', [ProfileController::class, 'password'])->name('staff.account.password');

    /** Revokes every auth_tokens row for this staff member, not just this one. */
    Url::post('/my-account/sessions/revoke', [ProfileController::class, 'revokeSessions'])
        ->name('staff.account.sessions.revoke');


    /*============================ SITE CONTENT =============================*/
    //
    // Announcements and the knowledgebase - written here, read on the public
    // front area. Both sit under one `content` permission group: they are one
    // job to an operator, and every group adds four checkboxes to the role
    // matrix.

    Url::get('/announcements', [AnnouncementController::class, 'index'])
        ->name('staff.announcements')->pipeline([Permission::class . '|perm=content.read']);

    Url::get('/announcements/new', [AnnouncementController::class, 'create'])
        ->name('staff.announcement.new')->pipeline([Permission::class . '|perm=content.create']);
    Url::post('/announcements/new', [AnnouncementController::class, 'create'])
        ->pipeline([Permission::class . '|perm=content.create']);

    Url::get("/announcement/{announcement:{$uid}}/edit", [AnnouncementController::class, 'edit'])
        ->name('staff.announcement.edit')->pipeline([Permission::class . '|perm=content.update']);
    Url::post("/announcement/{announcement:{$uid}}/edit", [AnnouncementController::class, 'edit'])
        ->pipeline([Permission::class . '|perm=content.update']);

    Url::post("/announcement/{announcement:{$uid}}/delete", [AnnouncementController::class, 'delete'])
        ->name('staff.announcement.delete')->pipeline([Permission::class . '|perm=content.delete']);

    /*=========================== KNOWLEDGEBASE =============================*/
    //
    // Articles and their categories on one controller, for the same reason they
    // share a permission group: nobody manages categories except in order to
    // file articles.

    Url::get('/knowledgebase', [KnowledgeBaseController::class, 'index'])
        ->name('staff.kb')->pipeline([Permission::class . '|perm=content.read']);

    Url::get('/knowledgebase/new', [KnowledgeBaseController::class, 'create'])
        ->name('staff.kb.new')->pipeline([Permission::class . '|perm=content.create']);
    Url::post('/knowledgebase/new', [KnowledgeBaseController::class, 'create'])
        ->pipeline([Permission::class . '|perm=content.create']);

    /*
     * Declared BEFORE the /article/{uid} routes below. Matching is first-match
     * -wins in registration order, and `categories` would otherwise be read as
     * a uid by the article routes if their patterns ever loosened.
     */
    Url::get('/knowledgebase/categories', [KnowledgeBaseController::class, 'categories'])
        ->name('staff.kb.categories')->pipeline([Permission::class . '|perm=content.read']);

    Url::get('/knowledgebase/categories/new', [KnowledgeBaseController::class, 'categoryCreate'])
        ->name('staff.kb.category.new')->pipeline([Permission::class . '|perm=content.create']);
    Url::post('/knowledgebase/categories/new', [KnowledgeBaseController::class, 'categoryCreate'])
        ->pipeline([Permission::class . '|perm=content.create']);

    Url::get("/knowledgebase/category/{category:{$uid}}/edit", [KnowledgeBaseController::class, 'categoryEdit'])
        ->name('staff.kb.category.edit')->pipeline([Permission::class . '|perm=content.update']);
    Url::post("/knowledgebase/category/{category:{$uid}}/edit", [KnowledgeBaseController::class, 'categoryEdit'])
        ->pipeline([Permission::class . '|perm=content.update']);

    Url::post("/knowledgebase/category/{category:{$uid}}/delete", [KnowledgeBaseController::class, 'categoryDelete'])
        ->name('staff.kb.category.delete')->pipeline([Permission::class . '|perm=content.delete']);

    Url::get("/article/{article:{$uid}}/edit", [KnowledgeBaseController::class, 'edit'])
        ->name('staff.kb.edit')->pipeline([Permission::class . '|perm=content.update']);
    Url::post("/article/{article:{$uid}}/edit", [KnowledgeBaseController::class, 'edit'])
        ->pipeline([Permission::class . '|perm=content.update']);

    Url::post("/article/{article:{$uid}}/delete", [KnowledgeBaseController::class, 'delete'])
        ->name('staff.kb.delete')->pipeline([Permission::class . '|perm=content.delete']);

})->pipeline([Auth::class])->filter([ActivityFilter::class]);


####################################################################################
/*------------------------------ PUBLIC ADMIN ROUTES -----------------------------*/
####################################################################################
//
// Registered after the group above, and that ordering is the whole point.
// Url::group()->pipeline() calls Handler::applyToPrefix(), which attaches the
// pipeline to every /admin route registered *so far* - so anything declared
// afterwards is left unguarded. Putting the login form inside the guarded group
// would redirect it to itself, forever.
Url::group(ADMIN, function (): void {
    Url::get('/login', [AuthController::class, 'login'])->name('staff.login');
    Url::post('/login', [AuthController::class, 'login']);

    /** POST, not GET: signing out is a state change (instruction 16). */
    Url::post('/logout', [AuthController::class, 'logout'])->name('staff.logout');
});
