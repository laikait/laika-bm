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
use LBM\Model\InvoiceItemModel;
use LBM\Service\Status;
use LBM\Support\RegistersDomains;

/**
 * Keeping a domain, and losing it.
 *
 * `Registration` gets the name; this keeps it. Two jobs that share a subject and
 * are kept apart because they answer to different things:
 *
 *   `deliver()` - a renewal invoice has been PAID, so tell the registrar. The
 *   money is what triggers it, exactly as with provisioning.
 *
 *   `expire()` - the date has passed, so say so. Nothing triggers this but the
 *   calendar, and it runs whether anybody paid anything or not.
 *
 * ---------------------------------------------------------------------------
 * THE PAID INVOICE LINE IS THE INSTRUCTION
 * ---------------------------------------------------------------------------
 * `DomainRenewalJob` advances `next_due_date` when it RAISES the invoice, the
 * way InvoiceGenerateJob does - so that column cannot also be the marker for
 * "has the registrar been told", because it moves before anybody has paid.
 *
 * `registrar_data['renewed_through']` is that marker: the period end of the
 * last renewal actually applied at the registry. A paid line reaching past it
 * has not been sent yet. One scalar, no list to grow, and it says in words what
 * the registry has been asked for.
 *
 * ---------------------------------------------------------------------------
 * expiry_date IS A REGISTRY FACT. next_due_date IS OUR SCHEDULE.
 * ---------------------------------------------------------------------------
 * The two columns look interchangeable and are not, and keeping them apart is
 * what makes a registrar that reports no expiry survivable:
 *
 *   - `expiry_date` is what the registry said, or NULL. Never computed here.
 *     RegistrarInterface says outright that a locally added year is how a
 *     domain expires while the panel says it is fine.
 *   - `next_due_date` is when WE will bill next, and is always set - from the
 *     registry date when there is one, and from the term that was paid for when
 *     there is not.
 *
 * So `expire()` skips a domain with no expiry date, because it has nothing to
 * judge; and billing carries on regardless, because a term was bought and is
 * running out whatever the registry chose to tell us.
 *
 * ---------------------------------------------------------------------------
 * `grace` IS NOT USED, AND THAT IS DELIBERATE
 * ---------------------------------------------------------------------------
 * `domain_statuses` seeds both `expired` and `grace`, and they would mean the
 * same thing here: past the date, still renewable. Two names for one state ends
 * with half the code checking one and half the other. `expired` says it, and
 * `redemption` is the state that genuinely differs - past the grace window,
 * renewable only at a restore fee.
 *
 * Nothing is ever deleted automatically. The registry decides when a name is
 * released, and a product that deleted the record first would be telling the
 * customer it has gone while they can still get it back.
 */
class DomainRenewal extends Action
{
    use RegistersDomains;

    /** @var string Domain Status Lookup Table */
    public const STATUSES = 'domain_statuses';

    /** @var int How Many Times a Failing Renewal Is Retried Before Staff Are Left To It */
    public const MAX_ATTEMPTS = 3;

    /** @var int Most Domains One Cron Tick Will Work On */
    public const BATCH = 20;

    /**
     * @var int Days After Expiry Before a Domain Needs a Restore Fee
     *
     * Thirty is the usual registry renewal-grace window. It is an option
     * because it genuinely differs by registry, and `.uk` in particular does
     * not work like this at all.
     */
    public const GRACE_DAYS = 30;

    public function model(): Model
    {
        return new DomainModel();
    }

    ####################################################################################
    /*=================================== THE CHAIN ==================================*/
    ####################################################################################

    /**
     * The Cron Entry Point
     * @return string What happened, for the cron log
     */
    public function run(): string
    {
        $applied = $this->deliver();
        $swept = $this->expire();

        return $applied['done'] . ' renewed, ' . $applied['failed'] . ' failed, '
            . $applied['manual'] . ' for staff, ' . $swept['expired'] . ' expired, '
            . $swept['redemption'] . ' into redemption';
    }

    /**
     * Apply Every Paid Renewal At Its Registrar
     * @return array{done:int,failed:int,manual:int}
     */
    public function deliver(): array
    {
        $done = 0;
        $failed = 0;
        $manual = 0;

        foreach ($this->awaiting() as $job) {
            $result = $this->renew($job['domain'], (string) $job['period_end'], (int) $job['years']);

            if (!empty($result['manual'])) {
                $manual++;

                continue;
            }

            $result['success'] ? $done++ : $failed++;
        }

        return ['done' => $done, 'failed' => $failed, 'manual' => $manual];
    }

    /**
     * Renewals That Have Been Paid For And Not Yet Sent To The Registry
     *
     * Driven from the INVOICE LINES rather than from the domains, because the
     * money is what authorises a renewal and the line is where the money is.
     * Walking domains instead would mean asking "is there a paid line for this"
     * once per domain, which is the same question asked from the wrong end.
     * @return array<int,array{domain:array,period_end:string,years:int}>
     */
    public function awaiting(): array
    {
        $items = new InvoiceItemModel();

        // Only a renewal line carries both. A registration line has neither -
        // Order::accept() copies a domain_relid that is still null at the
        // moment an order is invoiced, and periods are a renewal idea.
        $rows = $items->notNull('domain_relid')
            ->notNull('period_end')
            ->order($items->id, self::DESC)
            ->limit(self::BATCH * 10)
            ->get();

        $invoices = new Invoice();
        $provision = new Provision();
        $domains = new Domain();

        $seen = [];
        $out = [];

        foreach ($rows as $row) {
            $domainId = (int) ($row['domain_relid'] ?? 0);
            $periodEnd = (string) ($row['period_end'] ?? '');

            if ($domainId <= 0 || $periodEnd === '' || isset($seen[$domainId])) {
                continue;
            }

            $invoice = $invoices->find((int) ($row['invoice_relid'] ?? 0));

            // Provision owns the definition of "paid" - the same one the two
            // other sweeps use, so none of them can ever disagree about an
            // invoice. See its docblock for why it is not settledStatusIds().
            if (!is_array($invoice) || !$provision->isPaid($invoice)) {
                continue;
            }

            $domain = $domains->find($domainId);

            if (!is_array($domain)) {
                continue;
            }

            // Already sent. See the class docblock on why next_due_date cannot
            // be this marker.
            if ($this->renewedThrough($domain) >= strtotime($periodEnd)) {
                continue;
            }

            if ($this->domainAttempts($domain, 'renew') >= self::MAX_ATTEMPTS) {
                continue;
            }

            $seen[$domainId] = true;

            $out[] = [
                'domain'     =>  $domain,
                'period_end' =>  $periodEnd,
                'years'      =>  max(1, (new Tld())->yearsForCycle((string) ($domain['billing_cycle'] ?? 'annual'))),
            ];

            if (count($out) >= self::BATCH) {
                break;
            }
        }

        return $out;
    }

    /**
     * Tell One Registrar To Extend One Registration
     *
     * @param array $domain Domain Row
     * @param string $periodEnd What Was Paid For
     * @param int $years The Term
     * @return array{success:bool,manual:bool,message:string}
     */
    public function renew(array $domain, string $periodEnd, int $years = 1): array
    {
        $domainId = (int) ($domain['domain_id'] ?? 0);
        $name = (string) ($domain['domain'] ?? '');

        $driver = $this->driverForDomain($domain);

        // No module, so somebody renews this by hand at the registrar. NOT a
        // failure and NOT an attempt - counting three failures against a name
        // nothing ever tried puts a lie in the row for whoever reads it next.
        //
        // Nothing is marked renewed either: `renewed_through` says the REGISTRY
        // has been told, and it has not been.
        if ($driver === null) {
            return [
                'success' =>  false,
                'manual'  =>  true,
                'message' =>  'No registrar module. Left for staff to renew by hand.',
            ];
        }

        try {
            $result = $driver->renew($domain, $years, $this->contextFor($domain));
        } catch (Throwable $e) {
            return $this->failed($domain, 'The registrar module threw: ' . $e->getMessage());
        }

        if (!is_array($result) || empty($result['success'])) {
            $message = is_array($result) ? (string) ($result['message'] ?? '') : '';

            return $this->failed($domain, $message !== '' ? $message : 'The registry refused it.');
        }

        $expiry = $this->expiryFrom($result);

        // The registry date when there is one, and the term that was paid for
        // when there is not - see the class docblock. Nothing invents an
        // expiry_date; next_due_date is always set, because a term IS running.
        $this->update($domainId, [
            'expiry_date'   =>  $expiry,
            'next_due_date' =>  $expiry ?? $periodEnd,
        ]);

        $this->rememberDomain($domain, [
            'renewed_through' =>  $periodEnd,
            'renewed_at'      =>  $this->now(),
            'reference'       =>  $result['reference'] ?? ($domain['registrar_data']['reference'] ?? null),
            'expiry_unknown'  =>  $expiry === null,
            'renew_attempts'  =>  0,
            'last_error'      =>  null,
        ]);

        // Back from `expired` if it had already lapsed. A renewal paid inside
        // the grace window is the whole reason expired domains are still billed.
        (new Domain())->setStatus($domainId, 'active');

        (new Activity())->record('domain.renewed', 'Renewed ' . $name . ' at the registrar.');

        return ['success' => true, 'manual' => false, 'message' => 'Renewed.'];
    }

    ####################################################################################
    /*==================================== EXPIRY ====================================*/
    ####################################################################################

    /**
     * Move Domains Past Their Dates
     *
     * NOT switchable off, unlike dunning. Phase 23's switch exists because
     * suspending a service takes something away from a paying customer on the
     * strength of an old invoice. Nothing is taken away here: this records that
     * a date has passed, which is true whether or not it is written down, and
     * a panel that goes on saying `active` about a name the customer no longer
     * controls is the failure worth avoiding.
     * @return array{expired:int,redemption:int}
     */
    public function expire(): array
    {
        $now = date('Y-m-d H:i:s');
        $grace = option_int('domain_grace_days', self::GRACE_DAYS);
        $grace = $grace >= 0 ? $grace : self::GRACE_DAYS;

        $expired = 0;
        $redemption = 0;

        $activeId = Status::idOf(self::STATUSES, 'active');
        $expiredId = Status::idOf(self::STATUSES, 'expired');
        $redemptionId = Status::idOf(self::STATUSES, 'redemption');

        if ($activeId === null || $expiredId === null || $redemptionId === null) {
            return ['expired' => 0, 'redemption' => 0];
        }

        $model = $this->model();

        // The query BOUNDS what is read; the loop DECIDES. EITHER ONE ALONE
        // would give the right answer today, and that is said out loud rather
        // than left for somebody to discover: removing one changes no
        // behaviour, which is exactly how a guard gets deleted as dead.
        //
        // They are both kept because they fail differently. The query is what
        // stops this reading every domain in the table to find a handful. The
        // check is what keeps the loop correct on its own - the `active` branch
        // below expires whatever it is handed, so a filter moved or loosened in
        // the query, with no check here, marks the whole table expired.
        //
        // A sabotage therefore has to remove BOTH to go red, and does.
        $rows = $model->notNull('expiry_date')
            ->where(['expiry_date' => $now], '<')
            ->order($model->id, self::ASC)
            ->limit(self::BATCH * 10)
            ->get();

        foreach ($rows as $row) {
            $status = (int) ($row['status_relid'] ?? 0);
            $domainId = (int) $row['domain_id'];
            $ends = strtotime((string) ($row['expiry_date'] ?? ''));

            // A domain with NO expiry date is never touched. The registry never
            // told us when it ends, so there is nothing to judge, and a guess
            // here takes a working domain offline on every screen that reads it.
            if ($ends === false || $ends >= strtotime($now)) {
                continue;
            }

            if ($status === $activeId) {
                $this->update($domainId, ['status_relid' => $expiredId]);

                (new Activity())->record(
                    'domain.expired',
                    ($row['domain'] ?? 'A domain') . ' has passed its expiry date.',
                    Activity::SYSTEM
                );

                $this->notify($row);
                $expired++;

                continue;
            }

            // Past the renewal window. Still recoverable, but not at the price
            // on the renewal invoice any more - which is why it is a different
            // status rather than a longer `expired`.
            if ($status === $expiredId && $ends < strtotime("-{$grace} days")) {
                $this->update($domainId, ['status_relid' => $redemptionId]);

                (new Activity())->record(
                    'domain.redemption',
                    ($row['domain'] ?? 'A domain') . ' is past its grace period and needs a restore fee.',
                    Activity::SYSTEM
                );

                $redemption++;
            }
        }

        return ['expired' => $expired, 'redemption' => $redemption];
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * How Far The Registry Has Already Been Told To Renew To
     * @param array $domain Domain Row
     * @return int A timestamp, 0 when nothing has been applied
     */
    private function renewedThrough(array $domain): int
    {
        $data = $domain['registrar_data'] ?? null;

        if (!is_array($data)) {
            return 0;
        }

        $through = (string) ($data['renewed_through'] ?? '');

        return $through === '' ? 0 : (int) strtotime($through);
    }

    /**
     * What The Registrar Module Is Told
     * @param array $domain Domain Row
     * @return array
     */
    private function contextFor(array $domain): array
    {
        $client = (new Client())->find((int) ($domain['client_relid'] ?? 0));

        return [
            'client'      =>  is_array($client) ? $client : [],
            'contacts'    =>  [],
            'years'       =>  max(1, (new Tld())->yearsForCycle((string) ($domain['billing_cycle'] ?? 'annual'))),
            'nameservers' =>  [],
        ];
    }

    /**
     * Read The Expiry Date Out Of a Registrar Answer
     *
     * Parsed rather than trusted: it comes from somebody else's code and lands
     * in a timestamp column, so a string the database would refuse has to
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
     * Record a Failed Attempt
     * @param array $domain Domain Row
     * @param string $message What went wrong
     * @return array{success:bool,manual:bool,message:string}
     */
    private function failed(array $domain, string $message): array
    {
        $this->rememberDomain($domain, [
            'renew_attempts' =>  $this->domainAttempts($domain, 'renew') + 1,
            'last_error'     =>  $message,
            'last_tried_at'  =>  $this->now(),
        ]);

        (new Activity())->record(
            'domain.renewal.rejected',
            'Could not renew ' . ($domain['domain'] ?? 'a domain') . '. ' . $message
        );

        return ['success' => false, 'manual' => false, 'message' => $message];
    }

    /**
     * Tell The Client Their Domain Has Expired
     *
     * The sentence changes depending on whether there is an invoice waiting,
     * because the two situations need opposite things from them: pay this, or
     * get in touch. A message that says "renew it" without saying how is one
     * they have to write back about.
     * @param array $domain Domain Row
     * @return void
     */
    private function notify(array $domain): void
    {
        $client = (new Client())->find((int) ($domain['client_relid'] ?? 0));

        if (!is_array($client)) {
            return;
        }

        $unpaid = $this->unpaidRenewal((int) $domain['domain_id']);

        $line = $unpaid === null
            ? 'Please get in touch and we will arrange the renewal for you.'
            : 'Invoice ' . $unpaid . ' is waiting - paying it renews the domain.';

        try {
            (new Mail())->queueTemplate(
                'domain-expired',
                (string) $client['email'],
                [
                    'first_name'   =>  $client['first_name'] ?? '',
                    'last_name'    =>  $client['last_name'] ?? '',
                    'domain'       =>  $domain['domain'] ?? '',
                    'expiry_date'  =>  format_date($domain['expiry_date'] ?? null),
                    'invoice_line' =>  $line,
                ],
                (int) $client['cid']
            );
        } catch (Throwable $e) {
            (new Activity())->record(
                'domain.expired.notice.failed',
                'Marked the domain expired but could not queue the notice: ' . $e->getMessage()
            );
        }
    }

    /**
     * The Number Of An Unpaid Renewal Invoice For One Domain
     * @param int $domainId Domain ID
     * @return ?string
     */
    private function unpaidRenewal(int $domainId): ?string
    {
        $items = new InvoiceItemModel();

        $rows = $items->where(['domain_relid' => $domainId])
            ->order($items->id, self::DESC)
            ->limit(5)
            ->get();

        $invoices = new Invoice();
        $provision = new Provision();

        foreach ($rows as $row) {
            $invoice = $invoices->find((int) ($row['invoice_relid'] ?? 0));

            if (is_array($invoice) && !$provision->isPaid($invoice)) {
                return (string) ($invoice['invoice_number'] ?? '');
            }
        }

        return null;
    }
}
