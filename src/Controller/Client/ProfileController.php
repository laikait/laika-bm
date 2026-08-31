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

namespace LBM\Controller\Client;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use RuntimeException;
use Laika\Service\Request;
use Laika\Core\Exceptions\HttpException;
use LBM\Service\AuthClient;
use LBM\Service\Client;
use LBM\Service\ClientContact;
use LBM\Service\Country;
use LBM\Service\Currency;

/**
 * The client's own details, password, currency and sub-logins.
 *
 * The important thing in this file is what a client is *not* allowed to write.
 * LBM\Action\Client::FIELDS is the admin panel's list, and it includes
 * status_relid, is_restricted and tax_exempt - a client who could post those
 * could un-suspend their own account or make themselves tax free. So this
 * controller passes a much shorter whitelist of its own, and the two are kept
 * apart deliberately: the admin list is meant to grow, and it must not grow
 * into this one.
 *
 * Sub-logins are managed here too. A contact is another way into the same
 * account, so creating one is a privilege the account holder keeps: a contact
 * cannot make further contacts, whatever their permission flags say.
 */
class ProfileController extends ClientController
{
    /**
     * @var string[] What a Client May Change About Themselves
     *
     * Not Client::FIELDS. That list belongs to the admin panel and carries
     * status_relid, is_restricted and tax_exempt, none of which a client may
     * decide for themselves.
     */
    private const WRITABLE = [
        'company_name', 'first_name', 'middle_name', 'last_name',
        'phone_cc', 'phone_number', 'street', 'city', 'state', 'postcode',
        'country_relid',
    ];

    /** @var string[] What a Client May Change About a Sub-Login */
    private const CONTACT_WRITABLE = [
        'first_name', 'middle_name', 'last_name', 'email', 'username',
        'phone_cc', 'phone_number', 'street', 'city', 'state', 'postcode',
        'country_relid',
    ];

    protected function nav(): string
    {
        return 'profile';
    }

    ####################################################################################
    /*==================================== DETAILS ===================================*/
    ####################################################################################

    /**
     * The Profile Screen
     * @return string
     */
    public function index(): string
    {
        $this->allow('profile');

        return $this->screen('profile', 'My details', [
            'countries'  =>  Country::choices(),
            'currencies' =>  $this->currencyChoices(),
            'contacts'   =>  ClientContact::forClient($this->owner()),
        ]);
    }

    /**
     * Update The Client's Own Details
     *
     * The email address is left out on purpose. It is the sign-in identifier and
     * the address every notice goes to, so changing it silently is an account
     * takeover waiting to happen - it wants a confirmation link to the new
     * address, which is a mail round-trip this phase does not have. Until then
     * it is a support request, which is honest about what it costs.
     * @return ?string
     */
    public function update(): ?string
    {
        $this->allow('profile', self::UPDATE);

        $input = Request::inputs();
        $clientId = $this->owner();
        $existing = $this->client() ?? [];

        $required = [
            'first_name' =>  'Your first name is required.',
            'last_name'  =>  'Your last name is required.',
        ];

        if (!$this->require($required, $input)) {
            return $this->done('client.profile', 'Please check the form and try again.', false);
        }

        $data = array_intersect_key($input, array_flip(self::WRITABLE));

        return $this->attempt(
            function () use ($clientId, $data, $existing): void {
                Client::modify($clientId, $data);

                $this->log(
                    'client.profile.updated',
                    'Updated the account details.',
                    $this->changed($existing, $data)
                );
            },
            'client.profile',
            'Your details have been saved.'
        );
    }

    /**
     * Change The Signed-In Person's Password
     *
     * A contact changes their own, not the client's. Both live in the same
     * `passwords` table under different rel_types, and getting this the wrong
     * way round would have a sub-login silently resetting the account holder's
     * password - so which one is being changed is decided here from the guard,
     * never from the form.
     * @return ?string
     */
    public function password(): ?string
    {
        $input = Request::inputs();
        $contact = $this->contact();

        [$id, $type] = $contact === null
            ? [$this->owner(), 'client']
            : [(int) $contact['cc_id'], 'contact'];

        return $this->attempt(
            function () use ($input, $id, $type): void {
                $result = AuthClient::changePassword(
                    $id,
                    (string) ($input['current_password'] ?? ''),
                    (string) ($input['password'] ?? ''),
                    $input['password_confirm'] ?? null,
                    $type
                );

                if (!$result['ok']) {
                    throw new RuntimeException(implode(' ', $result['errors']));
                }
            },
            'client.profile',
            'Your password has been changed.'
        );
    }

    /**
     * Change The Currency The Client Is Billed In
     *
     * Only to a currency the operator actually has switched on - a client who
     * could post any id would set themselves to a currency with no exchange
     * rate, and every total on their account would then be wrong.
     *
     * Existing invoices keep the currency they were raised in. Rewriting a
     * document somebody has already been sent is not a preference change.
     * @return ?string
     */
    public function currency(): ?string
    {
        $this->allow('profile', self::UPDATE);

        $clientId = $this->owner();
        $wanted = (int) Request::input('currency_relid', 0);

        return $this->attempt(
            function () use ($clientId, $wanted): void {
                $currency = $this->activeCurrency($wanted);

                if ($currency === null) {
                    throw new RuntimeException('That is not a currency this site bills in.');
                }

                Client::modify($clientId, ['currency_relid' => $wanted]);

                $this->log(
                    'client.currency.changed',
                    'Changed the billing currency to ' . $currency['currency_code'] . '.'
                );
            },
            'client.profile',
            'Your billing currency has been changed. Invoices already raised keep the currency they were issued in.'
        );
    }

    ####################################################################################
    /*=================================== SUB-LOGINS =================================*/
    ####################################################################################

    /**
     * The Account's Sub-Logins
     * @return string
     */
    public function contacts(): string
    {
        $this->allow('profile');

        return $this->screen('contacts', 'Sub-logins', [
            'contacts' =>  ClientContact::forClient($this->owner()),
            'groups'   =>  ClientContact::groups(),
        ]);
    }

    /**
     * Add a Sub-Login
     * @return ?string
     */
    public function contactCreate(): ?string
    {
        $this->accountHolderOnly();

        $clientId = $this->owner();

        if (Request::isPost()) {
            $input = Request::inputs();

            $required = [
                'first_name' =>  'A first name is required.',
                'last_name'  =>  'A last name is required.',
                'email'      =>  'An email address is required.',
            ];

            $this->require($required, $input);
            $this->requireEmail('email', $input);

            $email = strtolower(trim((string) ($input['email'] ?? '')));

            if ($email !== '' && ClientContact::emailTaken($email)) {
                Request::addError('email', 'A contact already uses that email address.');
            }

            if (Request::errors() === []) {
                $data = array_intersect_key($input, array_flip(self::CONTACT_WRITABLE));
                $data['permissions'] = $input['permissions'] ?? [];

                return $this->attempt(
                    function () use ($clientId, $data, $input): void {
                        ClientContact::store(
                            $clientId,
                            $data,
                            $this->credential($input)
                        );

                        $this->log(
                            'contact.created',
                            'Added a sub-login for ' . $data['email'] . '.'
                        );
                    },
                    'client.contacts',
                    'The sub-login has been added.'
                );
            }
        }

        return $this->screen('contact-form', 'Add a sub-login', [
            'contact'   =>  null,
            'countries' =>  Country::choices(),
            'groups'    =>  ClientContact::groups(),
        ]);
    }

    /**
     * Edit a Sub-Login
     * @param string $contact Contact Uid
     * @return ?string
     */
    public function contactEdit(string $contact): ?string
    {
        $this->accountHolderOnly();

        $row = $this->contactRecord($contact);
        $id = (int) $row['cc_id'];

        if (Request::isPost()) {
            $input = Request::inputs();

            $this->require([
                'first_name' =>  'A first name is required.',
                'last_name'  =>  'A last name is required.',
                'email'      =>  'An email address is required.',
            ], $input);

            $this->requireEmail('email', $input);

            $email = strtolower(trim((string) ($input['email'] ?? '')));

            if ($email !== '' && ClientContact::emailTaken($email, $id)) {
                Request::addError('email', 'Another contact already uses that email address.');
            }

            if (Request::errors() === []) {
                $data = array_intersect_key($input, array_flip(self::CONTACT_WRITABLE));
                $data['permissions'] = $input['permissions'] ?? [];

                return $this->attempt(
                    function () use ($id, $data, $input, $row): void {
                        ClientContact::modify($id, $data);

                        // Blank means "leave it alone", not "clear it" - an edit
                        // form that shows no password and posts an empty one
                        // must not lock somebody out.
                        $password = $this->credential($input);

                        if ($password !== null) {
                            ClientContact::setPassword($id, $password);
                        }

                        $this->log(
                            'contact.updated',
                            'Updated the sub-login for ' . $row['email'] . '.'
                        );
                    },
                    'client.contacts',
                    'The sub-login has been saved.'
                );
            }
        }

        return $this->screen('contact-form', 'Edit sub-login', [
            'contact'   =>  $row,
            'countries' =>  Country::choices(),
            'groups'    =>  ClientContact::groups(),
        ]);
    }

    /**
     * Remove a Sub-Login
     * @param string $contact Contact Uid
     * @return ?string
     */
    public function contactDelete(string $contact): ?string
    {
        $this->accountHolderOnly();

        $row = $this->contactRecord($contact);

        return $this->attempt(
            function () use ($row): void {
                ClientContact::delete((int) $row['cc_id']);

                $this->log('contact.deleted', 'Removed the sub-login for ' . $row['email'] . '.');
            },
            'client.contacts',
            'The sub-login has been removed.'
        );
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Refuse a Sub-Login Managing Sub-Logins
     *
     * A contact is another way into the account, so who gets one is a decision
     * the account holder keeps. A contact who could add another could hand out
     * access the client never granted - and could grant it permissions they do
     * not have themselves.
     * @return void
     * @throws HttpException
     */
    private function accountHolderOnly(): void
    {
        if ($this->isContact()) {
            throw new HttpException(
                403,
                'Only the account holder can manage sub-logins.'
            );
        }
    }

    /**
     * Resolve One Of The Account's Own Contacts, Or 404
     * @param string $uid Contact Uid
     * @return array
     */
    private function contactRecord(string $uid): array
    {
        return $this->mine(
            static fn(int|string $key, int $clientId): ?array => ClientContact::forClientKey($key, $clientId),
            $uid,
            'sub-login'
        );
    }

    /**
     * The Password From a Sub-Login Form, If One Was Typed
     * @param array $input Submitted Data
     * @return ?string
     */
    private function credential(array $input): ?string
    {
        $password = (string) ($input['password'] ?? '');

        return $password === '' ? null : $password;
    }

    /**
     * A Currency, But Only If The Operator Bills In It
     * @param int $id Currency ID
     * @return ?array
     */
    private function activeCurrency(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        foreach (Currency::listing(true) as $row) {
            if ((int) $row['currency_id'] === $id) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Currency Choices
     * @return array<int,string>
     */
    private function currencyChoices(): array
    {
        $choices = [];

        foreach (Currency::listing(true) as $row) {
            $choices[(int) $row['currency_id']] = (string) $row['currency_code'];
        }

        return $choices;
    }

    /**
     * Which Submitted Fields Actually Differ From What Is Stored
     *
     * The audit trail is worth reading only if it records changes. An entry
     * listing every field on the form because somebody pressed Save records
     * nothing at all.
     * @param array $existing Stored Row
     * @param array $submitted Submitted Data
     * @return array<string,string>
     */
    private function changed(array $existing, array $submitted): array
    {
        $changes = [];

        foreach ($submitted as $column => $value) {
            $before = (string) ($existing[$column] ?? '');
            $after = (string) $value;

            if ($before !== $after) {
                $changes[$column] = $after;
            }
        }

        return $changes;
    }
}
