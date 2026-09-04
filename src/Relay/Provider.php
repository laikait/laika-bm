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

namespace LBM\Relay;

use LBM\Action;
use LBM\Mail\MailerFactory;
use LBM\Support\Money;
use LBM\Support\Status;
use LBM\Support\Permission;
use LBM\Support\Paginator;
use LBM\Support\PasswordValidator;
use Laika\Relay\RelayProvider;

class Provider extends RelayProvider
{
    public function register(): void
    {
        // Support -------------------------------------------------------------
        //
        // Singletons because each one memoises: Status holds the lookup tables,
        // Money the currency list, Permission the parsed role JSON. A second
        // instance would mean a second round of the same queries.
        //
        // No uid binding here any more. Uid and Icon both moved into
        // laika-core and are reached through Laika\Service\{Uid,Icon},
        // so a second copy in this package could only drift away from the
        // one the framework itself uses.
        $this->registry->singleton('support.money', Money::class);
        $this->registry->singleton('support.status', Status::class);
        $this->registry->singleton('support.paginator', Paginator::class);
        $this->registry->singleton('support.permission', Permission::class);
        $this->registry->singleton('support.password.validator', PasswordValidator::class);

        // Mail ----------------------------------------------------------------
        //
        // Lazy on purpose. MailerFactory reads mail_* out of the options table,
        // which needs a database - and during install there is not one yet.
        // singleton() only builds on first make(), so nothing touches the DB
        // until something actually sends.
        $this->registry->singleton('mailer', MailerFactory::class);

        // Actions -------------------------------------------------------------
        //
        // All the business logic, fronted by the LBM\Service\* facades so a
        // controller reads as Invoice::store(...) rather than assembling
        // collaborators itself.
        //
        // Singletons for the same reason as the support classes: several of them
        // memoise per request - Country the whole reference list, Activity
        // whether anything has been recorded yet, which is what ActivityFilter
        // reads on the way out. A fresh instance per call would lose that.
        //
        // Every one is lazy. The container builds on first make(), and none of
        // these constructors touches the database, so binding them all costs
        // nothing on a request that uses none of them - including a request
        // during install, when there is no database to touch.
        foreach ([
            'action.client'         =>  Action\Client::class,
            'action.client.contact' =>  Action\ClientContact::class,
            'action.client.note'    =>  Action\ClientNote::class,
            'action.client.service' =>  Action\ClientService::class,
            'action.staff'          =>  Action\Staff::class,
            'action.product'        =>  Action\Product::class,
            'action.order'          =>  Action\Order::class,
            'action.invoice'        =>  Action\Invoice::class,
            'action.transaction'    =>  Action\Transaction::class,
            'action.support'        =>  Action\Support::class,
            'action.currency'       =>  Action\Currency::class,
            'action.country'        =>  Action\Country::class,
            'action.activity'       =>  Action\Activity::class,
            'action.setting'        =>  Action\Setting::class,
            'action.mail'           =>  Action\Mail::class,
            'action.auth.staff'     =>  Action\AuthStaff::class,
            'action.auth.client'    =>  Action\AuthClient::class,
            'action.domain'         =>  Action\Domain::class,
            'action.server'         =>  Action\Server::class,
            'action.module'         =>  Action\Module::class,
            'action.announcement'   =>  Action\Announcement::class,
            'action.knowledgebase'  =>  Action\KnowledgeBase::class,
            'action.todo'           =>  Action\Todo::class,
            'action.gateway'        =>  Action\Gateway::class,
            'action.gateway.callback' =>  Action\GatewayCallback::class,
            'action.provision'      =>  Action\Provision::class,
            'action.dunning'        =>  Action\Dunning::class,
            'action.termination'    =>  Action\Termination::class,
        ] as $accessor => $class) {
            $this->registry->singleton($accessor, $class);
        }
    }

    public function boot(): void
    {
        // Every provider has registered by now. Nothing to wire yet.
    }
}
