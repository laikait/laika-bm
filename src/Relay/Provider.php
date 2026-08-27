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

use LBM\Mail\MailerFactory;
use LBM\Support\Uid;
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
        $this->registry->singleton('support.uid', Uid::class);
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
    }

    public function boot(): void
    {
        // Every provider has registered by now. Nothing to wire yet.
    }
}
