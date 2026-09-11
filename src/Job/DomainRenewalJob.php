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

namespace LBM\Job;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Throwable;
use Laika\Queue\Abstracts\Job;
use LBM\Action\Activity;
use LBM\Action\Client;
use LBM\Action\Invoice;
use LBM\Action\Mail;
use LBM\Action\Tax;
use LBM\Action\Tld;
use LBM\Model\DomainModel;
use LBM\Model\InvoiceItemModel;
use LBM\Service\Status;
use LBM\Support\Clock;

/**
 * Raises the invoices that keep a domain from lapsing.
 *
 * `InvoiceGenerateJob` for domains, and read that class first - the period
 * check, the double-billing rule and the reason next_due_date is advanced here
 * rather than at payment are all the same and are not repeated.
 *
 * Phase 27.1 taught the product to register a domain and left it there: every
 * name it created carried a `next_due_date` that NOTHING read, so a registered
 * domain was never billed again and quietly lapsed while the panel went on
 * saying `active`. This is the other half.
 *
 * ---------------------------------------------------------------------------
 * THE PRICE COMES FROM THE TLD, NOT FROM WHAT THEY PAID LAST TIME
 * ---------------------------------------------------------------------------
 * `domains.amount` is what was charged at registration, and a first year is
 * very often discounted - so renewing at it would repeat a promotion the
 * operator meant to give once. `tlds.renew_price` is a separate column for
 * exactly this reason and had never been read by anything.
 *
 * `Tld::renewalPrice()` deliberately ignores whether the TLD is still on
 * sale. Withdrawing one means stop selling new names, not abandon the customers
 * already on it.
 *
 * ---------------------------------------------------------------------------
 * A TLD THAT CANNOT BE PRICED IS SKIPPED LOUDLY
 * ---------------------------------------------------------------------------
 * If the TLD row has gone, or its currency no longer matches the one the domain
 * is billed in, there is no honest figure to put on an invoice - the last one
 * charged is a guess and the current one is in the wrong money. So it is not
 * billed, and it is RECORDED, because the failure mode of quietly skipping is a
 * domain that expires with nobody having been asked for anything.
 */
class DomainRenewalJob extends Job
{
    /** @var string Queue Name */
    public string $queue = 'default';

    /** @var int Retries */
    public int $maxTries = 2;

    /** @var int Seconds Before a Retry */
    public int $retryAfter = 300;

    /**
     * @var int How Far Ahead To Look, In Days
     *
     * Longer than a service's fourteen. Losing a domain is not recoverable the
     * way a suspended hosting account is - somebody else can register it the
     * moment it drops - so the customer gets a month rather than a fortnight.
     */
    public const LOOKAHEAD_DAYS = 30;

    /** @var string Domain Status Lookup Table */
    public const STATUSES = 'domain_statuses';

    /**
     * @var string[] Statuses a Domain Can Be Renewed From
     *
     * `expired` is in the list on purpose: a domain past its date is exactly
     * the one that most needs an invoice raising, and the registry grace period
     * is what makes paying it still worth something.
     */
    public const RENEWABLE = ['active', 'expired'];

    /** @var ?int One Domain, Or Null For Everything Due */
    private ?int $domainId;

    /**
     * @param ?int $domainId Domain ID. Null bills everything due
     */
    public function __construct(?int $domainId = null)
    {
        $this->domainId = $domainId;
    }

    /**
     * Run The Job
     * @return void
     */
    public function handle(): void
    {
        // A worker runs no pipeline, so nothing has put this process on the
        // operator's timezone - and every date column here is a TIMESTAMP the
        // database converts with the session timezone.
        Clock::apply();

        foreach ($this->due() as $domain) {
            try {
                $this->bill($domain);
            } catch (Throwable $e) {
                (new Activity())->record(
                    'domain.renewal.failed',
                    'Could not raise a renewal invoice for '
                        . ($domain['domain'] ?? 'a domain') . ': ' . $e->getMessage()
                );
            }
        }
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Domains Whose Renewal Falls Inside The Window
     * @return array
     */
    private function due(): array
    {
        $model = new DomainModel();

        if ($this->domainId !== null) {
            $row = $model->where([$model->id => $this->domainId])->first();

            return is_array($row) ? [$row] : [];
        }

        $days = option_int('domain_renew_days', self::LOOKAHEAD_DAYS);
        $days = $days > 0 ? $days : self::LOOKAHEAD_DAYS;

        $horizon = date('Y-m-d H:i:s', strtotime("+{$days} days"));

        $rows = $model->notNull('next_due_date')
            ->where(['next_due_date' => $horizon], '<=')
            ->order($model->id, 'ASC')
            ->get();

        return array_values(array_filter($rows, [$this, 'billable']));
    }

    /**
     * Whether a Domain Should Be Invoiced At All
     *
     * `auto_renew` is the customer's own instruction and has existed in the
     * schema since Phase 0 with nothing reading it. Somebody who has turned it
     * off has decided to let the name go, and billing them anyway is charging
     * for something they said they did not want.
     *
     * The status filter is here rather than in the query because `RENEWABLE` is
     * a list of names that have to be resolved to ids, and the set is already
     * bounded by the lookahead window.
     * @param array $domain Domain Row
     * @return bool
     */
    private function billable(array $domain): bool
    {
        if (($domain['auto_renew'] ?? 'yes') !== 'yes') {
            return false;
        }

        $status = (int) ($domain['status_relid'] ?? 0);

        foreach (self::RENEWABLE as $name) {
            if ($status === (int) Status::idOf(self::STATUSES, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Raise One Domain's Renewal Invoice
     * @param array $domain Domain Row
     * @return void
     */
    private function bill(array $domain): void
    {
        $domainId = (int) $domain['domain_id'];
        $periodStart = (string) ($domain['next_due_date'] ?? date('Y-m-d H:i:s'));

        if ($this->alreadyBilled($domainId, $periodStart)) {
            return;
        }

        $tlds = new Tld();
        $tld = $tlds->byName((string) ($domain['tld'] ?? ''));
        $years = max(1, $tlds->yearsForCycle((string) ($domain['billing_cycle'] ?? 'annual')));
        $currencyId = (int) ($domain['currency_relid'] ?? 0);

        $amount = is_array($tld) ? $tlds->renewalPrice($tld, $currencyId, $years) : null;

        // Loud, not quiet. See the class docblock: a domain skipped in silence
        // is one that expires with nobody having been asked for anything.
        if ($amount === null) {
            (new Activity())->record(
                'domain.renewal.unpriced',
                'Cannot price a renewal for ' . ($domain['domain'] ?? '?')
                    . '. Its TLD is missing or is priced in another currency.'
            );

            return;
        }

        $periodEnd = date('Y-m-d H:i:s', strtotime("+{$years} years", (int) strtotime($periodStart)));

        // Asked fresh, for the reason Action\Tax makes at length: a customer who
        // has moved, or a rate that changed, is charged what is due NOW. A
        // domain has no product, so the client's own rules decide.
        $tax = new Tax();
        $rate = $tax->rateForClient((int) ($domain['client_relid'] ?? 0), 0);

        $items = [[
            'description'  =>  $this->describe($domain, $years),
            'quantity'     =>  '1',
            'unit_price'   =>  $tax->inclusive() ? $tax->netOf($amount, $rate) : $amount,
            'tax'          =>  $rate,
            'domain_relid' =>  $domainId,
            'period_start' =>  $periodStart,
            'period_end'   =>  $periodEnd,
        ]];

        $invoiceId = (new Invoice())->store([
            'client_relid'     =>  $domain['client_relid'] ?? null,
            'currency_relid'   =>  $currencyId ?: null,
            'invoice_due_date' =>  $periodStart,
        ], $items);

        // Moving the due date forward is what stops tomorrow's run raising the
        // same invoice. It is NOT what tells the registrar anything - see
        // Action\DomainRenewal, which reads the paid line instead.
        $model = new DomainModel();
        $model->where([$model->id => $domainId])->update([
            'next_due_date'     =>  $periodEnd,
            'domain_updated_at' =>  date('Y-m-d H:i:s'),
        ]);

        (new Activity())->record(
            'domain.renewal.invoiced',
            'Raised a renewal invoice for ' . ($domain['domain'] ?? '?') . '.',
            Activity::SYSTEM
        );

        $this->notify($invoiceId, $domain);
    }

    /**
     * Whether An Invoice Item Already Covers This Period
     *
     * The same rule InvoiceGenerateJob follows, against the same pair of
     * columns: the invoice line IS the record of what has been billed, and a
     * flag on the domain could fall out of step with it.
     * @param int $domainId Domain ID
     * @param string $periodStart Period Start
     * @return bool
     */
    private function alreadyBilled(int $domainId, string $periodStart): bool
    {
        return (new InvoiceItemModel())->where([
            'domain_relid' =>  $domainId,
            'period_start' =>  $periodStart,
        ])->count() > 0;
    }

    /**
     * What The Invoice Line Says
     * @param array $domain Domain Row
     * @param int $years Term
     * @return string
     */
    private function describe(array $domain, int $years): string
    {
        $name = (string) ($domain['domain'] ?? 'domain');

        return 'Domain renewal: ' . $name . ' (' . $years . ' year' . ($years === 1 ? '' : 's') . ')';
    }

    /**
     * Tell The Client Their Renewal Invoice Is Waiting
     * @param int $invoiceId Invoice ID
     * @param array $domain Domain Row
     * @return void
     */
    private function notify(int $invoiceId, array $domain): void
    {
        $invoice = (new Invoice())->find($invoiceId);
        $client = (new Client())->find((int) ($domain['client_relid'] ?? 0));

        if ($invoice === null || $client === null) {
            return;
        }

        try {
            (new Mail())->queueTemplate(
                'domain-renewal',
                (string) $client['email'],
                [
                    'first_name'     =>  $client['first_name'] ?? '',
                    'domain'         =>  $domain['domain'] ?? '',
                    'expiry_date'    =>  format_date($domain['expiry_date'] ?? null),
                    'invoice_number' =>  $invoice['invoice_number'] ?? '',
                    'total'          =>  money($invoice['total'] ?? 0, $invoice['currency_relid'] ?? null),
                    'due_date'       =>  format_date($invoice['invoice_due_date'] ?? null),
                ],
                (int) $client['cid']
            );
        } catch (Throwable $e) {
            // A message that cannot be queued must not undo an invoice that has
            // already been raised. The queue records its own failures.
            (new Activity())->record(
                'domain.renewal.notice.failed',
                'Raised the renewal invoice but could not queue the notice: ' . $e->getMessage()
            );
        }
    }
}
