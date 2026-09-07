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

namespace LBM\Action;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Laika\Model\Model;
use Laika\Service\Uid;
use LBM\Model\DomainContactModel;
use RuntimeException;

/**
 * Who a domain is registered to.
 *
 * ---------------------------------------------------------------------------
 * THE HOLE THIS FILLS
 * ---------------------------------------------------------------------------
 * `domain_contacts` has existed since Phase 0 with no reader and no writer, and
 * `RegistrarInterface::register()` has declared a `contacts` key in its context
 * since Phase 9. Every one of the three call sites - Registration, Transfer,
 * DomainRenewal - passed a hardcoded `'contacts' => []`.
 *
 * So a driver was asked to register a name with NOBODY NAMED ON IT. A registry
 * either refuses that outright, or - and this is what resellers actually hit -
 * falls back to the API account holder's own details, which makes the
 * **operator** the legal registrant of their customer's domain. The customer
 * does not own the name they paid for, and nobody finds out until they try to
 * transfer it away.
 *
 * ---------------------------------------------------------------------------
 * A CONTACT IS A COPY, NOT A LINK
 * ---------------------------------------------------------------------------
 * `domain_contacts` duplicates the address rather than joining to `clients`, and
 * that is the schema being right rather than redundant:
 *
 *   - the registrant of a domain is a LEGAL FACT at a point in time. A customer
 *     moving house must not silently rewrite who owned a name last year.
 *   - a customer can register a domain FOR SOMEBODY ELSE, and often does. A
 *     link would make that unexpressible.
 *   - the registry holds its own copy anyway, and what it holds is the truth -
 *     so ours is a record of what we sent, not a second source.
 *
 * ---------------------------------------------------------------------------
 * THE REGISTRANT IS REQUIRED; THE REST FALL BACK TO IT
 * ---------------------------------------------------------------------------
 * A registry wants registrant, admin, tech and billing. For an ordinary
 * customer buying their own domain all four are the same person, and asking
 * somebody to type their address four times is how you get three of them wrong.
 *
 * So only the registrant is required, and `forRegistrar()` fills the others
 * from it when they have not been given separately. Sending empty admin and
 * tech contacts instead would put the operator's details back in the two slots
 * the registrant slot was fixed for.
 *
 * `abuse` is deliberately NOT filled in that way. It is in the enum and it is a
 * hosting contact rather than a registry one; inventing it from the registrant
 * would publish a customer's home address as an abuse desk.
 *
 * ---------------------------------------------------------------------------
 * A COUNTRY IS NOT OPTIONAL, AND THAT IS A REFUSAL RATHER THAN A ZERO
 * ---------------------------------------------------------------------------
 * `domain_contacts.country_relid` is NOT NULL while `clients.country_relid` is
 * nullable, so a client who never gave a country cannot have a contact built
 * from them. `defaultsFrom()` returns null rather than writing 0 - which is not
 * a country, is not detectably wrong at a glance, and would reach a registry as
 * a nonsense ISO code.
 *
 * @see \LBM\Module\Contracts\RegistrarInterface::contacts()
 */
class DomainContact extends Action
{
    /** @var string[] The Contact Roles a Registry Recognises */
    public const TYPES = ['registrant', 'admin', 'tech', 'billing', 'abuse'];

    /** @var string The One That Must Exist */
    public const REGISTRANT = 'registrant';

    /**
     * @var string[] Roles Filled From The Registrant When Not Given Separately
     *
     * `abuse` is not among them, deliberately - see the class docblock.
     */
    public const MIRRORED = ['admin', 'tech', 'billing'];

    /** @var string[] Columns This Accepts From a Form */
    public const FIELDS = [
        'company_name', 'first_name', 'last_name', 'email', 'phone_cc',
        'phone_number', 'address1', 'address2', 'city', 'state', 'postcode',
        'country_relid',
    ];

    /** @var string[] What a Registry Will Not Accept As Blank */
    public const REQUIRED = ['first_name', 'last_name', 'email', 'address1', 'city', 'country_relid'];

    public function model(): Model
    {
        return new DomainContactModel();
    }

    protected function searchable(): array
    {
        return ['first_name', 'last_name', 'email'];
    }

    /**
     * No Timestamps On This Table
     *
     * The schema carries none. A contact is replaced rather than versioned, and
     * when it last changed is in the activity log where every other
     * who-did-what question is answered.
     * @return ?string
     */
    protected function createdColumn(): ?string
    {
        return null;
    }

    protected function updatedColumn(): ?string
    {
        return null;
    }

    ####################################################################################
    /*=================================== READING ====================================*/
    ####################################################################################

    /**
     * The Roles a Contact Can Hold
     *
     * A method rather than the TYPES constant, because a relay facade forwards
     * method calls and not constants.
     * @return string[]
     */
    public function types(): array
    {
        return self::TYPES;
    }

    /**
     * Every Contact On One Domain, Keyed By Role
     * @param int $domainId Domain ID
     * @return array<string,array>
     */
    public function forDomain(int $domainId): array
    {
        if ($domainId <= 0) {
            return [];
        }

        $out = [];

        foreach ($this->all(['domain_relid' => $domainId]) as $row) {
            $type = (string) ($row['type'] ?? '');

            // Keyed by role, so a table that somehow holds two registrants
            // answers with one rather than rendering both and letting whoever
            // reads the screen decide. The last one written wins, which is the
            // one replace() would have left.
            if ($type !== '') {
                $out[$type] = $row;
            }
        }

        return $out;
    }

    /**
     * One Contact By Role
     * @param int $domainId Domain ID
     * @param string $type Role
     * @return ?array
     */
    public function ofType(int $domainId, string $type): ?array
    {
        return $this->forDomain($domainId)[$type] ?? null;
    }

    /**
     * Whether a Domain Has Somebody Named On It
     * @param int $domainId Domain ID
     * @return bool
     */
    public function hasRegistrant(int $domainId): bool
    {
        return $this->ofType($domainId, self::REGISTRANT) !== null;
    }

    /**
     * What Is Missing From a Contact, Or An Empty List
     *
     * A LIST rather than a boolean, because the screen has to say which fields -
     * "this contact is incomplete" sends somebody hunting through eleven boxes.
     * @param array $contact Contact Row Or Submitted Data
     * @return string[] Field names
     */
    public function missingFrom(array $contact): array
    {
        $missing = [];

        foreach (self::REQUIRED as $field) {
            $value = $contact[$field] ?? null;

            if ($field === 'country_relid') {
                if ((int) $value <= 0) {
                    $missing[] = $field;
                }

                continue;
            }

            if (trim((string) $value) === '') {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * A Contact Built From The Client's Own Details
     *
     * NULL when it cannot be built, which is the honest answer rather than a
     * half-filled row: `country_relid` is NOT NULL on this table and nullable on
     * `clients`, so a customer who never gave a country has no contact that can
     * be written. The screen asks for one instead.
     *
     * `clients.street` is one line and `domain_contacts` has two - the second is
     * left null rather than splitting an address on a guess.
     * @param array $client Client Row
     * @return ?array Ready for store(), or null when the client cannot supply one
     */
    public function defaultsFrom(array $client): ?array
    {
        // NO EARLY GUARD ON THE COUNTRY. A draft had one - `country <= 0` here
        // returned null before building anything - and removing it changed
        // nothing at all: the contact is built and then checked, and
        // missingFrom() already treats a country of 0 as missing. Two places
        // deciding the same thing is how one of them drifts. Proven dead by a
        // sabotage that left the walk green.
        $country = (int) ($client['country_relid'] ?? 0);

        $contact = [
            'company_name'  =>  $client['company_name'] ?? null,
            'first_name'    =>  $client['first_name'] ?? null,
            'last_name'     =>  $client['last_name'] ?? null,
            'email'         =>  $client['email'] ?? null,
            'phone_cc'      =>  $client['phone_cc'] ?? null,
            'phone_number'  =>  $client['phone_number'] ?? null,
            'address1'      =>  $client['street'] ?? null,
            'address2'      =>  null,
            'city'          =>  $client['city'] ?? null,
            'state'         =>  $client['state'] ?? null,
            'postcode'      =>  $client['postcode'] ?? null,
            'country_relid' =>  $country,
        ];

        // Built and then CHECKED, not assumed. A client row can be missing a
        // street or a city - both are nullable - and a contact that fails at the
        // registry after the money has been taken is worse than one refused now.
        return $this->missingFrom($contact) === [] ? $contact : null;
    }

    /**
     * The Contact Set As a Registrar Wants It
     *
     * Keyed by role, flat arrays, country as an ISO 2 code rather than our own
     * primary key - a registry has never heard of `countries.country_id`.
     *
     * The mirrored roles are filled from the registrant when they have not been
     * given separately. Sending them empty instead is the whole bug this class
     * exists to close, one slot along.
     * @param int $domainId Domain ID
     * @return array<string,array> Empty when there is no registrant at all
     */
    public function forRegistrar(int $domainId): array
    {
        $stored = $this->forDomain($domainId);
        $registrant = $stored[self::REGISTRANT] ?? null;

        if ($registrant === null) {
            // NOT a partial set. Without a registrant there is nobody to
            // register the name to, and handing a driver admin-only contacts
            // would let it fill the registrant slot itself - which is the
            // failure this whole class is about.
            return [];
        }

        $out = [self::REGISTRANT => $this->flatten($registrant)];

        foreach (self::MIRRORED as $role) {
            $out[$role] = isset($stored[$role])
                ? $this->flatten($stored[$role])
                : $out[self::REGISTRANT];
        }

        if (isset($stored['abuse'])) {
            $out['abuse'] = $this->flatten($stored['abuse']);
        }

        return $out;
    }

    ####################################################################################
    /*=================================== WRITING ====================================*/
    ####################################################################################

    /**
     * Write Or Replace One Role On a Domain
     *
     * Replace rather than update, for `Domain::setNameservers()`'s reason: there
     * is at most one contact per role, the form posts the whole thing, and
     * matching submitted fields against stored ones would only be a way to leave
     * half of an address behind.
     * @param int $domainId Domain ID
     * @param string $type Role
     * @param array $input Submitted Data
     * @return int The contact id
     * @throws RuntimeException
     */
    public function put(int $domainId, string $type, array $input): int
    {
        if ($domainId <= 0) {
            throw new RuntimeException('A contact has to belong to a domain.');
        }

        if (!in_array($type, self::TYPES, true)) {
            throw new RuntimeException('That is not a contact role this product knows about.');
        }

        $data = $this->only($input, self::FIELDS);
        $data['country_relid'] = (int) ($data['country_relid'] ?? 0);

        $missing = $this->missingFrom($data);

        if ($missing !== []) {
            throw new RuntimeException(
                'A registry will not accept this without: ' . implode(', ', $missing) . '.'
            );
        }

        $data['domain_relid'] = $domainId;
        $data['type'] = $type;

        $id = 0;

        $this->model()->transaction(function (DomainContactModel $m) use ($domainId, $type, $data, &$id): void {
            $m->where(['domain_relid' => $domainId, 'type' => $type])->delete();

            $m->insert([$m->uid => Uid::make()] + $data);

            $rows = $m->where(['domain_relid' => $domainId, 'type' => $type])->get();
            $id = (int) ($rows[0]['dc_id'] ?? 0);
        });

        return $id;
    }

    /**
     * Give a Domain a Registrant From Its Client, If It Has None
     *
     * Called when a domain row is created. Idempotent by construction: it does
     * nothing at all when a registrant already exists, so the provisioning sweep
     * can reach it as often as it likes without overwriting an address a
     * customer has since corrected.
     *
     * Returns false when the client cannot supply one, which is NOT an error -
     * the domain is still created, the registrar call will refuse it, and the
     * screen says which details are missing. Refusing to create the domain
     * instead would lose an order somebody has already paid for.
     * @param int $domainId Domain ID
     * @param int $clientId Client ID
     * @return bool Whether a registrant now exists
     */
    public function seedFor(int $domainId, int $clientId): bool
    {
        if ($domainId <= 0 || $clientId <= 0) {
            return false;
        }

        if ($this->hasRegistrant($domainId)) {
            return true;
        }

        $client = (new Client())->find($clientId);

        if ($client === null) {
            return false;
        }

        $contact = $this->defaultsFrom($client);

        if ($contact === null) {
            return false;
        }

        try {
            $this->put($domainId, self::REGISTRANT, $contact);
        } catch (RuntimeException $e) {
            return false;
        }

        return true;
    }

    /**
     * Remove One Role
     *
     * The registrant cannot be removed: a domain with nobody registered to it
     * is a row the registrar call will refuse, and the operator would only find
     * out at the next renewal.
     * @param int $domainId Domain ID
     * @param string $type Role
     * @return int Affected rows
     * @throws RuntimeException
     */
    public function forget(int $domainId, string $type): int
    {
        if ($type === self::REGISTRANT) {
            throw new RuntimeException(
                'Every domain has to have a registrant. Edit this one rather than removing it.'
            );
        }

        return (int) $this->model()
            ->where(['domain_relid' => $domainId, 'type' => $type])
            ->delete();
    }

    // NO forgetDomain(). A first draft had one, to clear the contacts when a
    // domain is deleted - and nothing in this product deletes a domain: there
    // is no remove() on Action\Domain and no delete route on either screen. A
    // verb with no caller is the exact defect this phase exists to fix one
    // table over, and shipping a new one while removing an old one would be
    // hard to argue with a straight face. It goes in with the delete, if a
    // delete is ever wanted.

    ####################################################################################
    /*=================================== HELPERS ====================================*/
    ####################################################################################

    /**
     * One Stored Row As a Registrar Wants It
     *
     * The country becomes its ISO 2 code. `countries.country_id` is ours and
     * means nothing to anybody else.
     * @param array $row Contact Row
     * @return array
     */
    private function flatten(array $row): array
    {
        $country = (new Country())->find((int) ($row['country_relid'] ?? 0));

        return [
            'company_name' =>  (string) ($row['company_name'] ?? ''),
            'first_name'   =>  (string) ($row['first_name'] ?? ''),
            'last_name'    =>  (string) ($row['last_name'] ?? ''),
            'email'        =>  (string) ($row['email'] ?? ''),
            'phone_cc'     =>  (string) ($row['phone_cc'] ?? ''),
            'phone_number' =>  (string) ($row['phone_number'] ?? ''),
            'address1'     =>  (string) ($row['address1'] ?? ''),
            'address2'     =>  (string) ($row['address2'] ?? ''),
            'city'         =>  (string) ($row['city'] ?? ''),
            'state'        =>  (string) ($row['state'] ?? ''),
            'postcode'     =>  (string) ($row['postcode'] ?? ''),
            'country'      =>  (string) ($country['iso2'] ?? ''),
        ];
    }
}
