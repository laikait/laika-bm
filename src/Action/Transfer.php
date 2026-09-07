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

use Throwable;
use Laika\Model\Model;
use LBM\Model\DomainModel;
use LBM\Service\Status;
use LBM\Support\RegistersDomains;

/**
 * Bringing a domain in from another registrar.
 *
 * `RegistrarInterface::transfer()` was declared in Phase 9 and was, after 27.2,
 * the ONE verb of five with no caller anywhere. `domains.type` has carried
 * `transfer` since Phase 0 and nothing ever wrote it; `tlds.transfer_price` and
 * `tlds.epp_required` were read by nothing; and `domains.epp_code` says
 * "encrypted" in the schema with nothing to encrypt.
 *
 * ---------------------------------------------------------------------------
 * THIS CLASS IS ONLY THE DELIVERY HALF
 * ---------------------------------------------------------------------------
 * Recording a paid domain line as a `domains` row belongs to Action\Registration
 * and stays there, for both kinds of line. Two classes writing `domains` from
 * the same order lines is a race with a UNIQUE name column at the end of it, and
 * the two differ in exactly one field - `domains.type`, taken from the order
 * line's `domain_action`.
 *
 * So the split is 22.4's split, one layer along: Registration records, and each
 * of Registration / Transfer / DomainRenewal delivers the verb it owns.
 *
 * ---------------------------------------------------------------------------
 * PENDING IS THE NORMAL ANSWER, AND IT IS NOT A FAILURE
 * ---------------------------------------------------------------------------
 * The contract says so outright: a transfer waits on the losing registrar or on
 * the registrant approving it, so `success` true WITH `pending` true is what a
 * healthy transfer looks like for days.
 *
 * That makes the retry rule the opposite of a registration's. A submitted
 * transfer must NEVER be submitted again - a second request while the first is
 * in flight is at best rejected and at worst a second billable transfer - so
 * `registrar_data['transfer_submitted']` is written the moment the registrar
 * accepts it, and awaiting() will not pick that row up again. Only a REFUSED
 * transfer is retried, and only MAX_ATTEMPTS times.
 *
 * ---------------------------------------------------------------------------
 * NOTHING POLLS, BECAUSE THERE IS NOTHING TO POLL WITH
 * ---------------------------------------------------------------------------
 * `RegistrarInterface` has no "how is that transfer going" verb. It has five,
 * and none of them answers the question, so a submitted transfer is completed
 * either by the registrar module reporting `pending` false on the one call it
 * gets, or by a member of staff pressing Complete when the registry tells them.
 *
 * That is a real limitation of the Phase 9 contract and it is said out loud
 * rather than worked around: inventing a poll out of `available()` would ask
 * "is this name registered", which is true throughout a transfer and after a
 * failed one, and would mark transfers complete that had never happened.
 * The admin domain screen shows the submission date so nobody has to guess.
 */
class Transfer extends Action
{
    use RegistersDomains;

    /** @var string Domain Status Lookup Table */
    public const STATUSES = 'domain_statuses';

    /**
     * @var int How Many Times a REFUSED Transfer Is Retried
     *
     * A refusal is usually a wrong auth code or a lock at the losing registrar,
     * and neither is fixed by asking again - but a registry being briefly
     * unreachable is. Three, then it waits for a person, exactly as a
     * registration does.
     */
    public const MAX_ATTEMPTS = 3;

    /** @var int Most Domains Touched In One Tick */
    public const BATCH = 20;

    public function model(): Model
    {
        return new DomainModel();
    }

    protected function createdColumn(): ?string
    {
        return 'domain_created_at';
    }

    protected function updatedColumn(): ?string
    {
        return 'domain_updated_at';
    }

    ####################################################################################
    /*=================================== EXTERNAL ===================================*/
    ####################################################################################

    /**
     * The Cron Line
     * @return string
     */
    public function run(): string
    {
        $result = $this->deliver();

        return $result['submitted'] . ' submitted, ' . $result['done'] . ' completed, '
            . $result['failed'] . ' failed, ' . $result['waiting'] . ' waiting on a code, '
            . $result['manual'] . ' for staff';
    }

    /**
     * Hand Pending Transfers To Their Registrar Modules
     *
     * Every failure is caught and recorded on the row rather than thrown: one
     * registry being unreachable must not stop the other nineteen names in the
     * batch.
     * @return array{submitted:int,done:int,failed:int,waiting:int,manual:int}
     */
    public function deliver(): array
    {
        $submitted = 0;
        $done = 0;
        $failed = 0;
        $manual = 0;

        foreach ($this->awaiting() as $domain) {
            $result = $this->submit($domain);

            if (!empty($result['manual'])) {
                $manual++;

                continue;
            }

            if (empty($result['success'])) {
                $failed++;

                continue;
            }

            empty($result['pending']) ? $done++ : $submitted++;
        }

        return [
            'submitted' =>  $submitted,
            'done'      =>  $done,
            'failed'    =>  $failed,
            'waiting'   =>  count($this->waitingForCode()),
            'manual'    =>  $manual,
        ];
    }

    /**
     * Transfers Ready To Be Submitted
     *
     * Four conditions, and each one is a different reason to leave a row alone:
     * it is a transfer rather than a registration; it has not been submitted
     * already; it has an auth code if its ending needs one; and it has not been
     * refused too many times.
     * @return array
     */
    public function awaiting(): array
    {
        $out = [];

        foreach ($this->pending() as $domain) {
            if ($this->wasSubmitted($domain)) {
                continue;
            }

            if ($this->needsCode($domain)) {
                continue;
            }

            if ($this->domainAttempts($domain, 'transfer') >= self::MAX_ATTEMPTS) {
                continue;
            }

            $out[] = $domain;

            if (count($out) >= self::BATCH) {
                break;
            }
        }

        return $out;
    }

    /**
     * Transfers That Cannot Move Until The Customer Supplies a Code
     *
     * Counted separately from a failure, and shown as its own number on the cron
     * line, because the two need opposite responses: a failure is the operator's
     * problem and this is the customer's. A transfer sitting here silently is
     * how somebody pays and then hears nothing for a month.
     * @return array
     */
    public function waitingForCode(): array
    {
        $out = [];

        foreach ($this->pending() as $domain) {
            if (!$this->wasSubmitted($domain) && $this->needsCode($domain)) {
                $out[] = $domain;
            }
        }

        return $out;
    }

    /**
     * Transfers Already Sent To a Registrar And Not Yet Finished
     * @return array
     */
    public function inFlight(): array
    {
        $out = [];

        foreach ($this->pending() as $domain) {
            if ($this->wasSubmitted($domain)) {
                $out[] = $domain;
            }
        }

        return $out;
    }

    /**
     * Whether a Transfer Still Needs An Auth Code From The Customer
     *
     * `tlds.epp_required` has existed since Phase 0 and this is its first
     * reader. An ending that does not require one - some ccTLDs do not - is
     * submitted with an empty code rather than waiting for ever for something
     * the registry will never ask for.
     * @param array $domain Domain Row
     * @return bool
     */
    public function needsCode(array $domain): bool
    {
        if ($this->authCode($domain) !== null) {
            return false;
        }

        $tld = (new Tld())->byEnding((string) ($domain['tld'] ?? ''));

        // No TLD row at all: the operator withdrew the ending after the order.
        // Treated as requiring a code, because the safe answer to "does this
        // registry want one" is to ask the customer rather than to submit a
        // transfer with an empty string and have it refused.
        return !is_array($tld) || (string) ($tld['epp_required'] ?? 'yes') === 'yes';
    }

    /**
     * The Auth Code On a Domain, Decrypted
     * @param array $domain Domain Row
     * @return ?string
     */
    public function authCode(array $domain): ?string
    {
        return (new Domain())->eppCode($domain);
    }

    /**
     * Submit One Transfer To Its Registrar
     *
     * @param array $domain Domain Row
     * @return array{success:bool,pending:bool,manual:bool,message:string}
     */
    public function submit(array $domain): array
    {
        $domainId = (int) ($domain['domain_id'] ?? 0);
        $name = (string) ($domain['domain'] ?? '');

        $driver = $this->driverForDomain($domain);

        // No module for this registrar, or the operator has switched it off.
        // NOT a failure and NOT an attempt, for Registration::register()'s
        // reason: an operator who moves names by hand has no module at all, and
        // counting three failures against a transfer nothing ever tried puts a
        // false error on the row.
        //
        // And NOT marked submitted either. The registry has not been told, so
        // whoever does it by hand still sees it waiting - 27.2's rule.
        if ($driver === null) {
            return [
                'success' =>  false,
                'pending' =>  false,
                'manual'  =>  true,
                'message' =>  'No registrar module. Left for staff to transfer by hand.',
            ];
        }

        $code = (string) ($this->authCode($domain) ?? '');
        $context = $this->contextFor($domain);

        try {
            $result = $driver->transfer($domain, $code, $context);
        } catch (Throwable $e) {
            return $this->failed($domain, 'The registrar module threw: ' . $e->getMessage());
        }

        if (!is_array($result) || empty($result['success'])) {
            $message = is_array($result) ? (string) ($result['message'] ?? '') : '';

            return $this->failed($domain, $message !== '' ? $message : 'The registrar refused the transfer.');
        }

        // WRITTEN BEFORE ANYTHING ELSE. From here on the registry has been told,
        // and that has to be true on the row even if the completion below fails
        // - a second submission is the one outcome that costs money twice.
        $this->rememberDomain($domain, [
            'transfer_submitted' =>  true,
            'transfer_sent_at'   =>  $this->now(),
            'reference'          =>  $result['reference'] ?? null,
            'last_error'         =>  null,
        ]);

        (new Activity())->record(
            'domain.transfer.submitted',
            'Submitted a transfer for ' . $name . ' to the registrar.'
        );

        // Pending is the NORMAL answer - see the class docblock. The row stays
        // `pending`, is not retried, and shows on the admin screen with the date
        // it went out.
        if (!empty($result['pending'])) {
            return [
                'success' =>  true,
                'pending' =>  true,
                'manual'  =>  false,
                'message' =>  'Submitted. Waiting on the losing registrar.',
            ];
        }

        $this->complete($domainId, $this->expiryFrom($result));

        return [
            'success' =>  true,
            'pending' =>  false,
            'manual'  =>  false,
            'message' =>  'Transferred.',
        ];
    }

    /**
     * Finish a Transfer: The Name Is Ours Now
     *
     * Called by submit() when the registrar answers straight away, and by staff
     * from the admin domain screen when the registry tells them days later.
     *
     * `expiry_date` is a registry FACT or null and `next_due_date` is our own
     * schedule - 27.2's split, and the reason it exists. A transfer adds a year
     * at almost every registry, so a transfer whose registrar reported no date
     * is billed a year out rather than never billed at all.
     * @param int $domainId Domain ID
     * @param ?string $expiry What The Registry Said, If Anything
     * @return bool
     */
    public function complete(int $domainId, ?string $expiry = null): bool
    {
        $domain = $this->find($domainId);

        if ($domain === null) {
            return false;
        }

        $this->update($domainId, [
            'registration_date' =>  $domain['registration_date'] ?? $this->now(),
            'expiry_date'       =>  $expiry,
            'next_due_date'     =>  $expiry ?? date('Y-m-d H:i:s', strtotime('+1 year')),
        ]);

        $this->rememberDomain($domain, [
            'transferred_at'  =>  $this->now(),
            'expiry_unknown'  =>  $expiry === null,
            'last_error'      =>  null,
        ]);

        // `active`, NOT `transferred`. The seeded `transferred` status means the
        // name has left us - a domain we have just taken IN is live, renewable
        // and billable, and DomainRenewalJob only ever bills `active` or
        // `expired`. Marking it `transferred` would quietly stop it renewing.
        (new Domain())->setStatus($domainId, 'active');

        (new Activity())->record(
            'domain.transferred',
            'Completed the transfer of ' . ($domain['domain'] ?? 'a domain') . '.'
        );

        return true;
    }

    /**
     * Whether a Transfer Has Already Gone To The Registrar
     * @param array $domain Domain Row
     * @return bool
     */
    public function wasSubmitted(array $domain): bool
    {
        $data = $domain['registrar_data'] ?? null;

        return is_array($data) && !empty($data['transfer_submitted']);
    }

    /**
     * How Many Times a Transfer Has Been Refused
     * @param array $domain Domain Row
     * @return int
     */
    public function attemptsOn(array $domain): int
    {
        return $this->domainAttempts($domain, 'transfer');
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Every Transfer Still Waiting To Become a Domain We Hold
     *
     * The `type` filter is the whole reason this is a separate sweep from
     * Registration's, and its absence there was a real bug this phase found:
     * awaiting() selected on status alone, so the moment transfers existed a
     * pending transfer would have been handed to register() and the product
     * would have tried to register a name somebody else owns.
     * @return array
     */
    private function pending(): array
    {
        $status = Status::idOf(self::STATUSES, 'pending');

        if ($status === null) {
            return [];
        }

        $model = $this->model();

        return $model->where(['status_relid' => $status, 'type' => 'transfer'])
            ->order($model->id, self::ASC)
            ->limit(self::BATCH * 5)
            ->get();
    }

    /**
     * What The Registrar Module Is Told About a Transfer
     *
     * `years` is 1 and is not read from the billing cycle. A transfer adds
     * exactly one year at the registry whatever term the customer bought, and
     * the contract's transfer() takes no term at all - so passing three here
     * would be telling the module something the call cannot act on.
     * @param array $domain Domain Row
     * @return array
     */
    private function contextFor(array $domain): array
    {
        $client = (new Client())->find((int) ($domain['client_relid'] ?? 0));

        $hosts = [];

        foreach ((new Domain())->nameservers((int) ($domain['domain_id'] ?? 0)) as $row) {
            $hosts[] = (string) $row['hostname'];
        }

        return [
            'client'      =>  is_array($client) ? $client : [],
            'contacts'    =>  [],
            'years'       =>  1,

            // Whatever this install has been told, which is usually nothing. A
            // transfer KEEPS the nameservers the name already has unless the
            // registrar is told otherwise, and that is the right default: a
            // transfer that silently repointed a live site at nothing is an
            // outage the customer did not ask for.
            'nameservers' =>  $hosts,
        ];
    }

    /**
     * Read The Expiry Date Out Of a Registrar Answer
     *
     * Parsed rather than trusted: the value comes from somebody else's code and
     * lands in a timestamp column, so a string the database would refuse has to
     * become null here instead of taking the sweep down.
     * @param array $result Driver Result
     * @return ?string
     */
    private function expiryFrom(array $result): ?string
    {
        $expiry = $result['expiry_date'] ?? null;

        if (!is_string($expiry) || trim($expiry) === '') {
            return null;
        }

        $time = strtotime($expiry);

        return $time === false ? null : date('Y-m-d H:i:s', $time);
    }

    /**
     * Record a Refused Transfer
     * @param array $domain Domain Row
     * @param string $message What went wrong
     * @return array{success:bool,pending:bool,manual:bool,message:string}
     */
    private function failed(array $domain, string $message): array
    {
        $this->rememberDomain($domain, [
            'transfer_attempts' =>  $this->domainAttempts($domain, 'transfer') + 1,
            'last_error'        =>  $message,
            'last_tried_at'     =>  $this->now(),
        ]);

        (new Activity())->record(
            'domain.transfer.failed',
            'Could not transfer ' . ($domain['domain'] ?? 'a domain') . '. ' . $message
        );

        return ['success' => false, 'pending' => false, 'manual' => false, 'message' => $message];
    }
}
