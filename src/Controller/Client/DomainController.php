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

use Throwable;
use RuntimeException;
use Laika\Service\Request;
use Laika\Core\Exceptions\HttpException;
use LBM\Service\Country;
use LBM\Service\Domain;
use LBM\Service\DomainContact;
use LBM\Service\Transfer;
use LBM\Support\RegistersDomains;

/**
 * A client's domains.
 *
 * One thing here is writable: the nameservers. That is the setting people
 * genuinely need at odd hours - pointing a domain at a new host - and it is the
 * one that costs an operator a support ticket every time it has to be done for
 * somebody.
 *
 * Everything else is read-only. Renewing, transferring and unlocking all cost
 * money or move ownership, and both belong to a registrar module talking to a
 * real registrar rather than to a form that only updates this database.
 *
 * THE NAMESERVER WRITE NOW REACHES THE REGISTRAR, when there is a module for
 * it. Until Phase 27.1 it did not, and the message it showed - "recorded and
 * passed to support" - was not true either: nothing was passed anywhere, no
 * ticket was raised and no email was sent. The row was written and that was
 * all. The screen has been saying so to customers since Phase 8, on a table
 * nothing could fill, so nobody had ever read it.
 *
 * Both outcomes are still possible and they are told apart, because they need
 * opposite things from the customer:
 *
 *   - A module answered. The set the REGISTRY reports is what gets stored -
 *     RegistrarInterface says outright that it is not always what was asked
 *     for, and storing the request instead would leave this install disagreeing
 *     with the only copy that matters.
 *   - No module, or the call failed. The intent is recorded, staff are told
 *     through the activity log, and the customer is told it has not taken
 *     effect yet. Letting somebody believe their DNS had moved is the failure
 *     worth avoiding here.
 */
class DomainController extends ClientController
{
    use RegistersDomains;

    /** @var int The Fewest Nameservers a Domain Needs */
    private const MINIMUM = 2;

    /** @var int The Most The Form Accepts */
    private const MAXIMUM = 5;

    protected function nav(): string
    {
        return 'domains';
    }

    /**
     * The Client's Domains
     * @return string
     */
    public function index(): string
    {
        $this->allow('domain');

        $page = Domain::browseForClient($this->owner());

        foreach ($page['rows'] as $index => $row) {
            $page['rows'][$index]['days_left'] = Domain::daysToExpiry($row);
            $page['rows'][$index]['expired'] = Domain::isExpired($row);
        }

        return $this->screen('domains', local('my_domains'), [
            'pager'    =>  $page,
            'statuses' =>  Domain::statuses(),
        ]);
    }

    /**
     * One Domain
     * @param string $domain Domain Uid
     * @return string
     */
    public function show(string $domain): string
    {
        $this->allow('domain');

        $row = $this->domain($domain);

        return $this->screen('domain', (string) $row['domain'], [
            'domain'      =>  $row,
            'nameservers' =>  Domain::nameservers((int) $row['domain_id']),

            // Who the name is registered to. Their own legal data, which they
            // must be able to see and correct - it is what a registry publishes
            // about them.
            'contacts'    =>  DomainContact::forDomain((int) $row['domain_id']),
            'roles'       =>  DomainContact::types(),
            'countries'   =>  Country::all(),
            'expired'     =>  Domain::isExpired($row),
            'days'        =>  Domain::daysToExpiry($row),
            'minimum'     =>  self::MINIMUM,
            'maximum'     =>  self::MAXIMUM,

            // Whether a change made here will actually reach the registrar.
            // The screen has to say which, because the two outcomes ask
            // different things of the customer - one is done, the other means
            // waiting for somebody. Computed by building the driver, since a
            // module named but not installed reads the same in the database as
            // one that works.
            'pushes'      =>  $this->driverForDomain($row) !== null,

            // A transfer in progress asks something of the customer that a
            // registration never does, and it is the only screen that can ask.
            // Three states, and they need three different sentences: waiting
            // on an auth code we do not have, sent to the registrar and
            // waiting on them, or not a transfer at all.
            'transfer'    =>  (string) ($row['type'] ?? '') === 'transfer',
            'submitted'   =>  Transfer::wasSubmitted($row),

            // Whether one is ON FILE, never what it is. An auth code is a
            // bearer credential: whoever holds it can move the name, so it is
            // written once and never rendered back to anybody at all.
            'has_code'    =>  Transfer::authCode($row) !== null,
            'needs_code'  =>  Transfer::needsCode($row),
        ]);
    }

    /**
     * Take The Auth Code For a Transfer
     *
     * Stored encrypted through Domain::setEppCode() and never read back to a
     * screen. The cron sweep picks the transfer up on its next tick, which is
     * why this does not submit anything itself: a registry call inside a web
     * request is a page that hangs on somebody else's network.
     *
     * Refused once the transfer has gone. Changing the code after submission
     * cannot reach the registrar - the request is already with them - so
     * accepting it would tell the customer something had been done when
     * nothing had.
     * @param string $domain Domain Uid
     * @return ?string
     */
    public function authCode(string $domain): ?string
    {
        $this->allow('domain', self::UPDATE);

        $row = $this->domain($domain);
        $back = ['domain' => $row['uid']];

        if ((string) ($row['type'] ?? '') !== 'transfer') {
            return $this->done('client.domain', local('domain_not_a_transfer'), false, $back);
        }

        if (Transfer::wasSubmitted($row)) {
            return $this->done('client.domain', local('domain_transfer_sent'), false, $back);
        }

        $code = trim((string) Request::input('auth_code', ''));

        if ($code === '') {
            return $this->done('client.domain', local('auth_code_required'), false, $back);
        }

        Domain::setEppCode((int) $row['domain_id'], $code);

        // The code itself is NOT in the log line. An activity log is read by
        // staff, exported and kept - putting a bearer credential in it undoes
        // the encryption two lines above.
        $this->log(
            'domain.authcode.stored',
            'Stored an auth code for the transfer of ' . $row['domain'] . '.'
        );

        return $this->done('client.domain', local('auth_code_stored'), true, $back);
    }

    /**
     * Correct The Details a Domain Is Registered To
     *
     * The customer's OWN legal data. A registry publishes it, and somebody who
     * has moved house has to be able to fix it without opening a ticket - the
     * same argument that makes nameservers writable here.
     *
     * Recorded FIRST and pushed second, exactly as the nameservers are, and for
     * the same reason: when the registrar call fails, this install still holds
     * what the customer asked for, which is what staff need in order to apply it
     * by hand. A failure is when that matters most.
     *
     * WHAT THE REGISTRY HOLDS AFTERWARDS WINS. Many endings refuse a registrant
     * change outright - it is a trade rather than an edit - so storing the
     * request and calling it done is how a panel comes to disagree with the
     * registry about who owns a name.
     * @param string $domain Domain Uid
     * @return ?string
     */
    public function contacts(string $domain): ?string
    {
        $this->allow('domain', self::UPDATE);

        $row = $this->domain($domain);
        $id = (int) $row['domain_id'];
        $back = ['domain' => $row['uid']];

        $type = trim((string) Request::input('type', ''));
        $input = Request::inputs();

        // Not attempt(), for nameservers()'s reason: the two outcomes are only
        // distinguishable after the work has run, and attempt() builds its
        // success message before it.
        try {
            if (!in_array($type, DomainContact::types(), true)) {
                throw new RuntimeException('That is not a contact role this product knows about.');
            }

            DomainContact::put($id, $type, $input);

            $applied = $this->pushContacts($row);

            $this->log(
                'domain.contacts.changed',
                'Changed the ' . $type . ' contact on ' . $row['domain'] . '.'
                . ($applied ? ' Applied at the registrar.' : ' Not applied at the registrar yet.')
            );
        } catch (HttpException $e) {
            throw $e;
        } catch (Throwable $e) {
            return $this->done('client.domain', $e->getMessage(), false, $back);
        }

        return $this->done(
            'client.domain',
            $applied
                ? local('domain_contacts_applied')
                : local('domain_contacts_recorded'),
            true,
            $back
        );
    }

    /**
     * Point a Domain At Different Nameservers
     * @param string $domain Domain Uid
     * @return ?string
     */
    public function nameservers(string $domain): ?string
    {
        $this->allow('domain', self::UPDATE);

        $row = $this->domain($domain);
        $id = (int) $row['domain_id'];

        $submitted = Request::input('nameservers', []);

        // A textarea posts one string and a repeated field posts an array; the
        // form can produce either, so both are accepted.
        $hosts = is_array($submitted)
            ? $submitted
            : (preg_split('/[\s,]+/', (string) $submitted) ?: []);

        $back = ['domain' => $row['uid']];

        // NOT attempt(), and that is the whole reason this reads differently
        // from every other mutation in the client area. attempt() takes its
        // success message as an argument, so the message is built before the
        // work runs - and the two outcomes here are only distinguishable
        // afterwards. Passing a flag the closure sets would read the value from
        // before the call every time.
        try {
            $clean = $this->clean($hosts);

            // Refused rather than stored: a domain with one nameserver is a
            // domain that stops resolving the moment that host blinks, and an
            // empty list is a domain that stops resolving at once.
            if (count($clean) < self::MINIMUM) {
                throw new RuntimeException(
                    'A domain needs at least ' . self::MINIMUM . ' nameservers.'
                );
            }

            if (count($clean) > self::MAXIMUM) {
                throw new RuntimeException(
                    'A domain takes at most ' . self::MAXIMUM . ' nameservers.'
                );
            }

            // Recorded FIRST, deliberately. If the registrar call then fails,
            // this install still holds what the customer asked for, which is
            // exactly what staff need in order to apply it by hand. Calling
            // first and storing only on success loses the request on every
            // failure - and a failure is when it is most needed.
            Domain::setNameservers($id, $clean);

            $applied = $this->push($row, $clean);

            $this->log(
                'domain.nameservers.changed',
                'Changed the nameservers on ' . $row['domain'] . '.'
                . ($applied ? ' Applied at the registrar.' : ' Not applied at the registrar yet.'),
                ['nameservers' => implode(', ', $clean)]
            );
        } catch (HttpException $e) {
            throw $e;
        } catch (Throwable $e) {
            return $this->done('client.domain', $e->getMessage(), false, $back);
        }

        return $this->done(
            'client.domain',
            local($applied ? 'nameservers_applied' : 'nameservers_recorded'),
            true,
            $back
        );
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Send a Nameserver Change To The Registrar
     *
     * False for every way of not having reached the registry - no module for
     * this registrar, a driver that threw, a driver that reported failure. The
     * caller turns that into "recorded, not applied yet", which is a true
     * sentence in all three cases and the only one a customer can act on.
     *
     * On success the set the REGISTRY reports is written back over the one that
     * was asked for. RegistrarInterface says outright that they are not always
     * the same, and where they differ the registry is right - this install
     * holding a list the registry does not have is how somebody debugs DNS for
     * an afternoon against the wrong facts.
     * @param array $row Domain Row
     * @param string[] $hosts Requested Nameservers
     * @return bool Whether the registrar applied it
     */
    private function push(array $row, array $hosts): bool
    {
        $driver = $this->driverForDomain($row);

        if ($driver === null) {
            return false;
        }

        try {
            $result = $driver->nameservers($row, $hosts);
        } catch (Throwable) {
            return false;
        }

        if (!is_array($result) || empty($result['success'])) {
            return false;
        }

        $held = $result['nameservers'] ?? null;

        if (is_array($held) && $held !== []) {
            Domain::setNameservers((int) $row['domain_id'], $held);
        }

        return true;
    }

    /**
     * Send The Contact Set To The Registrar
     *
     * The whole set rather than the one role that changed, because
     * `RegistrarInterface::contacts()` replaces rather than patches - the same
     * shape as `nameservers()`, and for the same reason.
     * @param array $row Domain Row
     * @return bool Whether the registry took it
     */
    private function pushContacts(array $row): bool
    {
        $driver = $this->driverForDomain($row);

        if ($driver === null) {
            return false;
        }

        $set = DomainContact::forRegistrar((int) $row['domain_id']);

        // No registrant means no set at all - forRegistrar() answers empty
        // rather than partial, because handing a driver admin-only contacts
        // lets it fill the registrant slot itself.
        if ($set === []) {
            return false;
        }

        try {
            $result = $driver->contacts($row, $set);
        } catch (Throwable) {
            return false;
        }

        if (!is_array($result) || empty($result['success'])) {
            return false;
        }

        return true;
    }

    /**
     * Resolve One Of The Client's Own Domains, Or 404
     * @param string $uid Domain Uid
     * @return array
     */
    private function domain(string $uid): array
    {
        return $this->mine(
            static fn(int|string $key, int $clientId): ?array => Domain::forClientKey($key, $clientId),
            $uid,
            'domain'
        );
    }

    /**
     * Tidy Submitted Hostnames
     *
     * Blanks dropped, duplicates dropped, case folded. The form has five rows
     * and most people fill two, so most of what arrives is empty.
     * @param array $hosts Submitted Hostnames
     * @return string[]
     */
    private function clean(array $hosts): array
    {
        $clean = [];

        foreach ($hosts as $host) {
            $host = strtolower(trim((string) $host));

            if ($host !== '' && !in_array($host, $clean, true)) {
                $clean[] = $host;
            }
        }

        return $clean;
    }
}
