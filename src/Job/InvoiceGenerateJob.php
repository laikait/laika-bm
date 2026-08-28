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
use LBM\Model\BillingCycleModel;
use LBM\Model\ClientServiceModel;
use LBM\Model\InvoiceItemModel;
use LBM\Action\Activity;
use LBM\Action\Client;
use LBM\Action\Invoice;
use LBM\Action\Mail;
use LBM\Action\Product;
use LBM\Service\Status;

/**
 * Raises the recurring invoices that are coming due.
 *
 * Run daily. It looks ahead by `invoice_generate_days` (default 14) for services
 * whose next due date falls inside that window and raises one invoice per
 * service, so a customer has notice before the money is actually wanted.
 *
 * The whole thing turns on not billing twice. A service is skipped when an
 * invoice item already covers the period being billed - checked against the
 * items rather than against a marker on the service, because a marker can fall
 * out of step with reality and the invoice line *is* the reality. Running this
 * twice in one day, or re-running it after a crash halfway through, therefore
 * costs nothing.
 *
 * It also advances next_due_date, which is what stops tomorrow's run raising the
 * same invoice again once the period check has moved on.
 */
class InvoiceGenerateJob extends Job
{
    /** @var string Queue Name */
    public string $queue = 'default';

    /** @var int Retries */
    public int $maxTries = 2;

    /** @var int Seconds Before a Retry */
    public int $retryAfter = 300;

    /** @var int How Far Ahead To Look, In Days */
    public const LOOKAHEAD_DAYS = 14;

    /** @var string Service Status Lookup Table */
    public const SERVICE_STATUSES = 'client_service_statuses';

    /** @var ?int One Service, Or Null For Everything Due */
    private ?int $serviceId;

    /** @var array<int,string>|null Billing Cycle Names, Keyed By Id */
    private ?array $cycles = null;

    /**
     * @param ?int $serviceId Service ID. Null bills everything due
     */
    public function __construct(?int $serviceId = null)
    {
        $this->serviceId = $serviceId;
    }

    /**
     * Run The Job
     * @return void
     */
    public function handle(): void
    {
        foreach ($this->due() as $service) {
            try {
                $this->bill($service);
            } catch (Throwable $e) {
                // One service that cannot be billed must not stop the rest of
                // the run - tomorrow's run picks it up again.
                (new Activity())->record(
                    'invoice.generate.failed',
                    'Could not raise an invoice for service #'
                        . ($service['service_id'] ?? '?') . ': ' . $e->getMessage()
                );
            }
        }
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Services Whose Next Payment Falls Inside The Window
     * @return array
     */
    private function due(): array
    {
        $model = new ClientServiceModel();

        if ($this->serviceId !== null) {
            $row = $model->where([$model->id => $this->serviceId])->first();

            return is_array($row) ? [$row] : [];
        }

        $days = option_int('invoice_generate_days', self::LOOKAHEAD_DAYS);
        $days = $days > 0 ? $days : self::LOOKAHEAD_DAYS;

        $horizon = date('Y-m-d H:i:s', strtotime("+{$days} days"));

        $active = Status::idOf(self::SERVICE_STATUSES, 'active');

        if ($active !== null) {
            $model->where(['status_relid' => $active]);
        }

        return $model->notNull('next_due_date')
            ->where(['next_due_date' => $horizon], '<=')
            ->order($model->id, 'ASC')
            ->get();
    }

    /**
     * Raise One Service's Invoice
     * @param array $service Service Row
     * @return void
     */
    private function bill(array $service): void
    {
        $serviceId = (int) $service['service_id'];
        $periodStart = (string) ($service['next_due_date'] ?? date('Y-m-d H:i:s'));

        if ($this->alreadyBilled($serviceId, $periodStart)) {
            return;
        }

        $periodEnd = $this->periodEnd($periodStart, $service);

        $invoiceId = (new Invoice())->store([
            'client_relid'     =>  $service['client_relid'] ?? null,
            'currency_relid'   =>  $service['currency_relid'] ?? null,
            'invoice_due_date' =>  $periodStart,
        ], [[
            'description'   =>  $this->describe($service),
            'quantity'      =>  '1',
            'unit_price'    =>  (string) ($service['amount'] ?? '0'),
            'service_relid' =>  $serviceId,
            'period_start'  =>  $periodStart,
            'period_end'    =>  $periodEnd,
        ]]);

        // Moving the due date forward is what keeps the next run from
        // considering this service again once the period has been billed.
        $services = new ClientServiceModel();
        $services->where([$services->id => $serviceId])->update([
            'next_due_date' =>  $periodEnd,
            'updated_at'    =>  date('Y-m-d H:i:s'),
        ]);

        (new Activity())->record(
            'invoice.generated',
            "Raised a renewal invoice for service #{$serviceId}.",
            Activity::SYSTEM
        );

        $this->notify($invoiceId, $service);
    }

    /**
     * Whether An Invoice Item Already Covers This Period
     * @param int $serviceId Service ID
     * @param string $periodStart Period Start
     * @return bool
     */
    private function alreadyBilled(int $serviceId, string $periodStart): bool
    {
        return (new InvoiceItemModel())->where([
            'service_relid' =>  $serviceId,
            'period_start'  =>  $periodStart,
        ])->count() > 0;
    }

    /**
     * What The Invoice Line Says
     * @param array $service Service Row
     * @return string
     */
    private function describe(array $service): string
    {
        $product = (new Product())->find((int) ($service['product_relid'] ?? 0));
        $name = (string) ($product['product_name'] ?? 'Service renewal');

        $domain = trim((string) ($service['domain'] ?? ''));
        $cycle = $this->cycleName((int) ($service['billing_cycle_relid'] ?? 0));

        if ($domain !== '') {
            $name .= " - {$domain}";
        }

        return $cycle === '' ? $name : "{$name} ({$this->humanCycle($cycle)})";
    }

    /**
     * Where The Billed Period Ends
     * @param string $start Period Start
     * @param array $service Service Row
     * @return string
     */
    private function periodEnd(string $start, array $service): string
    {
        $cycle = $this->cycleName((int) ($service['billing_cycle_relid'] ?? 0));

        // The seeded cycle names, mapped to what they mean in time. A cycle the
        // operator added by hand falls through to monthly rather than to nothing,
        // because a renewal invoice with no period at all is worse than one with
        // a period that has to be corrected.
        $interval = match ($cycle) {
            'one_time'    =>  '+0 days',
            'semi_annual' =>  '+6 months',
            'annual'      =>  '+1 year',
            'biennial'    =>  '+2 years',
            'triennial'   =>  '+3 years',
            'quarterly'   =>  '+3 months',
            'weekly'      =>  '+1 week',
            default       =>  '+1 month',
        };

        return date('Y-m-d H:i:s', strtotime($interval, strtotime($start)));
    }

    /**
     * A Billing Cycle's Name
     *
     * The whole table is read once and held: a run billing four hundred services
     * would otherwise look up the same six rows four hundred times.
     * @param int $cycleId Billing Cycle ID
     * @return string
     */
    private function cycleName(int $cycleId): string
    {
        if ($this->cycles === null) {
            $model = new BillingCycleModel();
            $id = $model->id;

            $this->cycles = [];

            foreach ($model->get() as $row) {
                $this->cycles[(int) $row[$id]] = (string) $row['billing_cycle_name'];
            }
        }

        return $this->cycles[$cycleId] ?? '';
    }

    /**
     * A Billing Cycle Name Fit To Print
     * @param string $cycle Cycle Name
     * @return string
     */
    private function humanCycle(string $cycle): string
    {
        return ucwords(str_replace('_', ' ', $cycle));
    }

    /**
     * Tell The Client Their Invoice Is Waiting
     * @param int $invoiceId Invoice ID
     * @param array $service Service Row
     * @return void
     */
    private function notify(int $invoiceId, array $service): void
    {
        $invoice = (new Invoice())->find($invoiceId);
        $client = (new Client())->find((int) ($service['client_relid'] ?? 0));

        if ($invoice === null || $client === null) {
            return;
        }

        try {
            (new Mail())->queueTemplate(
                'invoice-created',
                (string) $client['email'],
                [
                    'first_name'     =>  $client['first_name'] ?? '',
                    'invoice_number' =>  $invoice['invoice_number'] ?? '',
                    'total'          =>  money($invoice['total'] ?? 0, $invoice['currency_relid'] ?? null),
                    'due_date'       =>  format_date($invoice['invoice_due_date'] ?? null),
                ],
                (int) $client['cid']
            );
        } catch (Throwable) {
            // No such template, or it is switched off. The invoice still stands.
        }
    }
}
