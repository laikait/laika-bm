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

namespace LBM\Controller\Admin;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Laika\Service\Request;
use LBM\Service\Activity;
use LBM\Service\Client;
use LBM\Service\ClientContact;
use LBM\Service\ClientNote;
use LBM\Service\Country;
use LBM\Service\Currency;
use LBM\Service\Domain;
use LBM\Service\Invoice;
use LBM\Service\Order;
use LBM\Service\Password;
use LBM\Service\Support;
use LBM\Service\Transaction;

/**
 * Clients, their contacts and the notes staff leave on them.
 *
 * The client record is the hub of this application: everything else - orders,
 * invoices, tickets, domains - hangs off it, so show() gathers a summary of
 * each rather than making somebody visit five screens to answer one question.
 */
class ClientController extends AdminController
{
    protected function nav(): string
    {
        return 'clients';
    }

    ####################################################################################
    /*=================================== CLIENTS ====================================*/
    ####################################################################################

    /**
     * The Client List
     * @return string
     */
    public function index(): string
    {
        $page = Client::browse(
            $this->conditions(['status' => 'status_relid', 'country' => 'country_relid']),
            $this->search()
        );

        return $this->screen('clients', 'Clients', [
            'pager'      =>  $page,
            'statuses'   =>  Client::statuses(),
            'countries'  =>  Country::choices(),
        ]);
    }

    /**
     * Add a Client
     * @return ?string
     */
    public function create(): ?string
    {
        if (Request::isPost()) {
            $input = Request::inputs();

            if ($this->validate($input)) {
                $password = (string) ($input['password'] ?? '');

                $id = Client::store($input, $password !== '' ? $password : null);

                $client = Client::find($id);

                $this->log('client.created', 'Added client ' . client_name($client));

                return $this->done('staff.client', local('client_added'), true, ['client' => $client['cuid']]);
            }
        }

        return $this->form(null, local('add_client'));
    }

    /**
     * One Client
     * @param string $client Client Uid
     * @return string
     */
    public function show(string $client): string
    {
        $row = $this->record(Client::find($client), 'client');
        $id = (int) $row['cid'];

        return $this->screen('client', client_name($row), [
            'client'       =>  $row,
            'country'      =>  Country::name($row['country_relid'] ?? null, '—'),
            'contacts'     =>  ClientContact::forClient($id),
            'notes'        =>  ClientNote::forClient($id, 5),
            'invoices'     =>  Invoice::browseForClient($id, [], 5)['rows'],
            'orders'       =>  Order::browseForClient($id, 5)['rows'],
            'tickets'      =>  Support::browseForClient($id, 5)['rows'],
            'domains'      =>  Domain::browseForClient($id, 5)['rows'],
            'transactions' =>  Transaction::browseForClient($id, 5)['rows'],
            'outstanding'  =>  Invoice::outstandingFor($id),
        ]);
    }

    /**
     * Edit a Client
     * @param string $client Client Uid
     * @return ?string
     */
    public function edit(string $client): ?string
    {
        $row = $this->record(Client::find($client), 'client');
        $id = (int) $row['cid'];

        if (Request::isPost()) {
            $input = Request::inputs();

            if ($this->validate($input, $id)) {
                $changes = Activity::changes($row, $input);

                Client::modify($id, $input);

                $password = (string) ($input['password'] ?? '');

                if ($password !== '') {
                    $errors = Password::validate($password, $input['password_confirm'] ?? null);

                    if ($errors !== []) {
                        Request::addError('password', $errors[0]);

                        return $this->form($row, local('edit_named', client_name($row)));
                    }

                    Client::setPassword($id, $password);
                }

                $this->log('client.updated', 'Updated client ' . client_name($row), $changes);

                return $this->done('staff.client', local('client_updated'), true, ['client' => $row['cuid']]);
            }
        }

        return $this->form($row, local('edit_named', client_name($row)));
    }

    /**
     * Delete a Client
     * @param string $client Client Uid
     * @return ?string
     */
    public function delete(string $client): ?string
    {
        $row = $this->record(Client::find($client), 'client');
        $name = client_name($row);

        return $this->attempt(
            function () use ($row, $name): void {
                Client::remove((int) $row['cid']);

                $this->log('client.deleted', "Deleted client {$name} and their contacts and notes.");
            },
            'staff.clients',
            local('deleted_named', $name)
        );
    }

    ####################################################################################
    /*=================================== CONTACTS ===================================*/
    ####################################################################################

    /**
     * A Client's Contacts
     * @param string $client Client Uid
     * @return string
     */
    public function contacts(string $client): string
    {
        $row = $this->record(Client::find($client), 'client');

        return $this->screen('client-contacts', client_name($row) . ' — contacts', [
            'client'   =>  $row,
            'contacts' =>  ClientContact::forClient((int) $row['cid']),
        ]);
    }

    /**
     * Add a Contact
     * @param string $client Client Uid
     * @return ?string
     */
    public function contactCreate(string $client): ?string
    {
        $row = $this->record(Client::find($client), 'client');

        if (Request::isPost()) {
            $input = Request::inputs();

            $this->require([
                'first_name' =>  local('first_name_required'),
                'last_name'  =>  local('last_name_required'),
                'email'      =>  local('email_required'),
            ], $input);

            $this->requireEmail('email', $input);

            if (Request::errors() === []) {
                $password = (string) ($input['password'] ?? '');

                ClientContact::store(
                    (int) $row['cid'],
                    $input,
                    $password !== '' ? $password : null
                );

                $this->log('contact.created', 'Added a contact to ' . client_name($row));

                return $this->done(
                    'staff.client.contacts',
                    local('contact_added'),
                    true,
                    ['client' => $row['cuid']]
                );
            }
        }

        return $this->contactForm($row, null);
    }

    /**
     * Edit a Contact
     * @param string $client Client Uid
     * @param string $contact Contact Uid
     * @return ?string
     */
    public function contactEdit(string $client, string $contact): ?string
    {
        $row = $this->record(Client::find($client), 'client');
        $person = $this->record(
            ClientContact::forClientKey($contact, (int) $row['cid']),
            'contact'
        );

        if (Request::isPost()) {
            $input = Request::inputs();

            $this->require([
                'first_name' =>  local('first_name_required'),
                'last_name'  =>  local('last_name_required'),
                'email'      =>  local('email_required'),
            ], $input);

            $this->requireEmail('email', $input);

            if (Request::errors() === []) {
                ClientContact::modify((int) $person['cc_id'], $input);

                $password = (string) ($input['password'] ?? '');

                if ($password !== '') {
                    ClientContact::setPassword((int) $person['cc_id'], $password);
                }

                $this->log('contact.updated', 'Updated a contact of ' . client_name($row));

                return $this->done(
                    'staff.client.contacts',
                    local('contact_updated'),
                    true,
                    ['client' => $row['cuid']]
                );
            }
        }

        return $this->contactForm($row, $person);
    }

    /**
     * Delete a Contact
     * @param string $client Client Uid
     * @param string $contact Contact Uid
     * @return ?string
     */
    public function contactDelete(string $client, string $contact): ?string
    {
        $row = $this->record(Client::find($client), 'client');
        $person = $this->record(
            ClientContact::forClientKey($contact, (int) $row['cid']),
            'contact'
        );

        ClientContact::delete((int) $person['cc_id']);

        $this->log('contact.deleted', 'Removed a contact from ' . client_name($row));

        return $this->done(
            'staff.client.contacts',
            local('contact_removed'),
            true,
            ['client' => $row['cuid']]
        );
    }

    ####################################################################################
    /*==================================== NOTES =====================================*/
    ####################################################################################

    /**
     * A Client's Notes
     * @param string $client Client Uid
     * @return string
     */
    public function notes(string $client): string
    {
        $row = $this->record(Client::find($client), 'client');

        return $this->screen('client-notes', client_name($row) . ' — notes', [
            'client' =>  $row,
            'notes'  =>  ClientNote::forClient((int) $row['cid'], 50),
        ]);
    }

    /**
     * Add a Note
     * @param string $client Client Uid
     * @return ?string
     */
    public function noteCreate(string $client): ?string
    {
        $row = $this->record(Client::find($client), 'client');
        $note = trim((string) Request::input('note', ''));

        if ($note === '') {
            return $this->done(
                'staff.client.notes',
                local('note_cannot_be_empty'),
                false,
                ['client' => $row['cuid']]
            );
        }

        ClientNote::store((int) $row['cid'], (int) $this->staffId(), $note);

        $this->log('note.created', 'Left a note on ' . client_name($row));

        return $this->done('staff.client.notes', local('note_added'), true, ['client' => $row['cuid']]);
    }

    /**
     * Delete a Note
     * @param string $client Client Uid
     * @param string $note Note Uid
     * @return ?string
     */
    public function noteDelete(string $client, string $note): ?string
    {
        $row = $this->record(Client::find($client), 'client');

        ClientNote::removeForClient($note, (int) $row['cid']);

        $this->log('note.deleted', 'Deleted a note on ' . client_name($row));

        return $this->done('staff.client.notes', local('note_deleted'), true, ['client' => $row['cuid']]);
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Render The Client Form
     * @param ?array $client Client, Or Null When Adding
     * @param string $title Page Title
     * @return string
     */
    private function form(?array $client, string $title): string
    {
        return $this->screen('client-form', $title, [
            'client'      =>  $client,
            'statuses'    =>  $this->statusChoices(Client::statuses()),
            'countries'   =>  Country::choices(),
            'currencies'  =>  $this->currencyChoices(),
        ]);
    }

    /**
     * Render The Contact Form
     * @param array $client Client
     * @param ?array $contact Contact, Or Null When Adding
     * @return string
     */
    private function contactForm(array $client, ?array $contact): string
    {
        return $this->screen(
            'client-contact-form',
            local($contact === null ? 'add_contact' : 'edit_contact'),
            [
                'client'    =>  $client,
                'contact'   =>  $contact,
                'countries' =>  Country::choices(),
                'groups'    =>  ClientContact::groups(),
                'actions'   =>  ['read', 'create', 'update', 'delete'],
            ]
        );
    }

    /**
     * Validate a Client Submission
     * @param array $input Submitted Data
     * @param ?int $ignore Client ID To Exclude, When Editing
     * @return bool
     */
    private function validate(array $input, ?int $ignore = null): bool
    {
        $this->require([
            'first_name' =>  local('first_name_required'),
            'last_name'  =>  local('last_name_required'),
            'email'      =>  local('email_required'),
        ], $input);

        $this->requireEmail('email', $input);

        $email = trim((string) ($input['email'] ?? ''));

        if ($email !== '' && Client::emailTaken($email, $ignore)) {
            Request::addError('email', local('client_email_taken'));
        }

        $username = trim((string) ($input['username'] ?? ''));

        if ($username !== '' && Client::usernameTaken($username, $ignore)) {
            Request::addError('username', local('username_taken'));
        }

        // Only checked when one was typed. On the add form a blank password is
        // a client who signs in later or not at all; on the edit form it means
        // "leave the current one alone".
        $password = (string) ($input['password'] ?? '');

        if ($password !== '') {
            $errors = Password::validate($password, $input['password_confirm'] ?? null);

            if ($errors !== []) {
                Request::addError('password', $errors[0]);
            }
        }

        return Request::errors() === [];
    }

    /**
     * Currency Choices For The Form
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
}
