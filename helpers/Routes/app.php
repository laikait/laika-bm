<?php
/**
 * Laika Bill Manager
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 */

##########################################################################
/*----------------------------- LBM ROUTES -----------------------------*/
##########################################################################
use Laika\Route\Url;
use LBM\Pipeline\Admin\AdminAuth;
use LBM\Pipeline\InitPipeline;
use LBM\Pipeline\Admin\Clientpipeline;

// Global Init
Url::globalPipeline(InitPipeline::class);


// Admin Route Group
Url::group(ADMIN, function(){
    // Fallback
    Url::fallback('/', function () { echo 'Not done yet'; });

    Url::get('/login', 'Admin\AuthController@login')->name('staff.login'); // Done
    Url::post('/login', 'Admin\AuthController@login');
    Url::get('/logout', 'Admin\AuthController@logout')->name('staff.logout'); // Done

    // Dashboard
    Url::get('/', 'Admin\DashboardController@index')->name('staff.dashboard'); // Almost Done

    // Clients
    Url::get('/clients', 'Admin\ClientController@clients')->name('staff.clients');
    // Single Client
    Url::get('/client/{client:[a-zA-Z0-9\-]+}', 'Admin\ClientController@single')->name('staff.client');
    // Url::post('/client/{client:[a-zA-Z0-9\-]+}', 'Admin\ClientController@single')->pipeline([Clientpipeline::class]);

    // Client Contacts
    Url::get('/client/{client:[a-zA-Z0-9\-]+}/contacts', 'Admin\ClientController@contacts')->name('staff.client.contacts');
    Url::get('/client/{client:[a-zA-Z0-9\-]+}/contact/{contact:[a-zA-Z0-9\-]+}', 'Admin\ClientController@addContact')->name('staff.client.contact');
    
    // Products
    Url::get('/products', 'Admin\ProductController@products')->name('staff.products');
    Url::get('/product/{product:[a-zA-Z0-9\-]+}', function(){})->name('staff.product');
    
    // Orders
    Url::get('/orders', function(){})->name('staff.orders');
    Url::get('/order/{order:[a-zA-Z0-9\-]+}', function(){})->name('staff.order');

    // Tickets
    Url::get('/tickets', function(){})->name('staff.tickets');
    Url::get('/ticket/{ticket:[a-zA-Z0-9\-]+}', function(){})->name('staff.ticket');
    Url::get('/network-status', function(){})->name('staff.network');

    // Staffs
    Url::get('/staffs', function(){})->name('staff.staffs');
    Url::get('/staff/{staff:[a-zA-Z0-9\-]+}', function(){})->name('staff.staff');
    Url::get('/staff-activities', function(){})->name('staff.activities');

    // Addons
    Url::get('/addons', function(){})->name('staff.addons');
    
    // Noticeboard
    Url::get('/addons', function(){})->name('staff.noticeboard');    

    // Invoices
    Url::get('/invoices', function(){})->name('staff.invoices');
    Url::get('/invoice/{invoice:[a-zA-Z0-9\-]+}', function(){})->name('staff.invoice');

    // Reports & Reports Group
    Url::get('/reports', function(){})->name('staff.reports');
    Url::group('/reports', function () {
        Url::get('/invoice-report', function(){})->name('staff.invoice-report');
        Url::get('/order-report', function(){})->name('staff.order-report');
        Url::get('/ticket-feedbacks', function(){})->name('staff.ticket-feedbacks');
    });

    // Others
    Url::get('/my-account', function(){})->name('staff.account');
    Url::get('/settings', function(){})->name('settings');

    // Admin Add Route Group
    Url::group('/add', function(){
        // Add Client
        Url::get('/client', 'Admin\ClientController@create')->name('staff.add.client');
        Url::post('/client', 'Admin\ClientController@create')->pipeline([Clientpipeline::class]);

        // Add Staff
        Url::get('/staff', 'Admin\StaffController@create')->name('staff.add.staff');
        Url::post('/staff', 'Admin\StaffController@create');

        // Add Invoice
        Url::get('/invoice', 'Admin\InvoiceController@create')->name('staff.add.invoice');
        Url::post('/invoice', 'Admin\InvoiceController@create');

        // Add Product
        Url::get('/product', 'Admin\ProductController@create')->name('staff.add.product');
        Url::post('/product', 'Admin\ProductController@create');

        // Add Order
        Url::get('/order', 'Admin\OrderController@create')->name('staff.add.order');
        Url::post('/order', 'Admin\OrderController@create');

        // Add Ticket
        Url::get('/ticket', 'Admin\TicketController@create')->name('staff.add.ticket');
        Url::post('/ticket', 'Admin\TicketController@create');

        // Add Notice
        Url::get('/notice', 'Admin\NoticeController@create')->name('staff.add.notice');
        Url::post('/notice', 'Admin\NoticeController@create');

    });

    // Admin Edit Route Group
    Url::group('/edit', function(){
        // Edit Client
        Url::get('/client/{client:[a-zA-Z0-9\-]+}', 'Admin\ClientController@modify')->name('staff.edit.client');
        Url::post('/client/{client:[a-zA-Z0-9\-]+}', 'Admin\ClientController@modify')->pipeline([Clientpipeline::class]);

    });

})->pipeline([AdminAuth::class]);


// Panel Route Group
Url::group(PANEL, function(){
    // Fallback
    Url::fallback('/', function () { echo 'Not done yet'; });

    Url::get('/login', null)->name('client.login');
});
