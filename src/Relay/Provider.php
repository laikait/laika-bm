<?php
/**
 * Name: Laika Shield
 * Provider: Laika IT
 * Email: strblackhawk@gmail.com
 */

declare(strict_types=1);

namespace LBM\Relay;

// Actions
use LBM\Action\Order;
use LBM\Action\Staff;
use LBM\Action\Client;
use LBM\Action\Invoice;
use LBM\Action\Support;
use LBM\Action\Country;
use LBM\Action\Activity;
use LBM\Action\Currency;
use LBM\Action\AuthStaff;
use LBM\Action\AuthClient;
use LBM\Action\ClientNote;
use LBM\Action\ClientContact;
use Laika\Relay\RelayProvider;

// Supports
use LBM\Support\Initiate;
use LBM\Support\PasswordValidator;

class Provider extends RelayProvider
{
    public function register(): void
    {
        $this->registry->singleton('support.initiate', Initiate::class);
        $this->registry->singleton('support.password.validator', PasswordValidator::class);
        $this->registry->singleton('action.activity', Activity::class);
        $this->registry->singleton('action.auth.staff', AuthStaff::class);
        $this->registry->singleton('action.auth.client', AuthClient::class);
        $this->registry->singleton('action.client', Client::class);
        $this->registry->singleton('action.client.contact', ClientContact::class);
        $this->registry->singleton('action.invoice', Invoice::class);
        $this->registry->singleton('action.staff', Staff::class);
        $this->registry->singleton('action.support', Support::class);
        $this->registry->singleton('action.country', Country::class);
        $this->registry->singleton('action.client.note', ClientNote::class);
        $this->registry->singleton('action.order', Order::class);
        $this->registry->singleton('action.order', Order::class);
        $this->registry->singleton('action.currency', Currency::class);
    } 
}
