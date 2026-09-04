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
use LBM\Model\ClientServiceModel;
use LBM\Model\InvoiceItemModel;
use LBM\Model\InvoiceModel;
use LBM\Service\Status;
use LBM\Support\ServesServices;

/**
 * Switching a service off when the bill is not paid, and back on when it is.
 *
 * Phase 22 ended with a product that provisions eagerly and collects lazily: a
 * service appeared the moment an invoice settled and then never reacted to
 * anything again, so a customer who stopped paying kept their hosting for ever.
 * This is the other half of that loop.
 *
 * ---------------------------------------------------------------------------
 * ONE PREDICATE, READ IN BOTH DIRECTIONS
 * ---------------------------------------------------------------------------
 * `delinquent()` answers "is there an invoice covering this service that is
 * past its due date, past the grace period, and still owing money". Suspension
 * happens when it says yes; restoration happens when it says no. Deliberately
 * not two rules: two would eventually disagree, and the shape of that
 * disagreement is a service switched off while nothing is owed - a customer who
 * has paid, cannot use what they paid for, and is told by the system that they
 * are in arrears.
 *
 * ---------------------------------------------------------------------------
 * OFF BY DEFAULT, AND THAT IS NOT TIMIDITY
 * ---------------------------------------------------------------------------
 * `suspend_overdue` defaults to false. An operator upgrading an install that
 * has been running for a year has old unpaid invoices in it - written off,
 * disputed, superseded, forgotten - and switching this on by default would take
 * real customers offline on the first cron tick after an update, with no
 * warning and no action from anybody. Turning it on has to be a decision
 * somebody makes, on a screen, having read what it does.
 *
 * ---------------------------------------------------------------------------
 * WHO SUSPENDED IT IS RECORDED, BECAUSE RESTORING DEPENDS ON IT
 * ---------------------------------------------------------------------------
 * `module_data['suspended_by']` holds either `dunning` or `staff`. Cron only
 * ever restores what cron suspended. A service switched off by hand for abuse,
 * fraud or an operator's own reason must not come back because an unrelated
 * invoice got paid - and it is exactly that case which would go unnoticed until
 * the abuse resumed.
 *
 * ---------------------------------------------------------------------------
 * THE MODULE CALL COMES FIRST, THE STATUS SECOND
 * ---------------------------------------------------------------------------
 * If the control panel refuses, the service is NOT marked suspended. Recording
 * a suspension that did not happen is the worst outcome available here: the
 * customer keeps their working service, the operator's screen says it is off,
 * and nobody looks at it again. So a failed module call leaves the status
 * alone, counts the attempt and is retried - the same arrangement Provision
 * uses, and for the same reason.
 *
 * A service with no server and no module is a different case, not a failure:
 * there is nothing to call, the operator sets these up by hand, and the status
 * IS the instruction to them. That one is recorded without a module call.
 *
 * ---------------------------------------------------------------------------
 * THE INVOICE ITEM IS THE LINK
 * ---------------------------------------------------------------------------
 * `invoice_items.service_relid`, written by InvoiceGenerateJob when it raises a
 * renewal. So this only ever acts on RENEWALS, which is correct rather than a
 * limitation: the first invoice for an order is raised before any service
 * exists, and a service that was never provisioned cannot be suspended.
 */
class Dunning extends Action
{
    use ServesServices;

    /** @var string Service Status Lookup Table */
    public const SERVICE_STATUSES = 'client_service_statuses';

    /** @var string Invoice Status Lookup Table */
    public const INVOICE_STATUSES = 'invoice_statuses';

    /** @var int Days After The Due Date Before Anything Is Switched Off */
    public const GRACE_DAYS = 7;

    /** @var int How Many Times a Failing Module Call Is Retried */
    public const MAX_ATTEMPTS = 3;

    /** @var int Most Services One Cron Tick Will Act On, Per Direction */
    public const BATCH = 20;

    /** @var string What module_data['suspended_by'] Says When Cron Did It */
    public const BY_DUNNING = 'dunning';

    /** @var string What It Says When a Member Of Staff Did It */
    public const BY_STAFF = 'staff';

    /** @var string module_data Key Holding a Reprieve's Expiry */
    public const HOLD = 'dunning_hold_until';

    public function model(): Model
    {
        return new ClientServiceModel();
    }

    ####################################################################################
    /*=================================== THE SWEEP ==================================*/
    ####################################################################################

    /**
     * The Cron Entry Point
     *
     * Restore first. The two sets are disjoint, so the order cannot change the
     * outcome - but a customer who has just paid should be back before anything
     * else is considered, and if a tick is ever cut short it should be cut short
     * on the switching-off half.
     * @return string What happened, for the cron log
     */
    public function run(): string
    {
        // RESTORING IS NOT GATED ON THE SWITCH, and that asymmetry is
        // deliberate. An operator who decides the automation is too aggressive
        // switches it off - and if that also stopped restoration, every
        // customer cron had already suspended would stay offline for ever, with
        // no sweep left to bring them back and no sign of why. Switching it off
        // means "stop switching things off", not "never switch anything on".
        $restored = $this->restoreAll();

        if (!$this->enabled()) {
            return $restored['done'] . ' restored, suspension off';
        }

        $suspended = $this->suspendAll();

        return $restored['done'] . ' restored, ' . $suspended['done'] . ' suspended, '
            . ($restored['failed'] + $suspended['failed']) . ' failed';
    }

    /**
     * Whether The Operator Has Switched Automatic Suspension On
     *
     * ONE ARGUMENT. `option_bool()` takes a key and nothing else - it is
     * `preg_match('/^true$/i', option($key, 'false'))` - so a second argument
     * meant as a default is accepted by PHP, ignored, and reads to everybody
     * afterwards like a setting that could be changed. This method was written
     * that way at first and the mistake was invisible, because the value it
     * silently fell back to happened to be the one wanted.
     *
     * The fixed fallback of 'false' is what makes this feature safe on an
     * install that has never heard of it, which is the whole design. Anybody
     * wanting it on by default has to change that, in laika-core, for every
     * boolean option in the product - which is the correct amount of friction.
     *
     * It also means only the literal string 'true' counts: 1, 'yes' and 'on'
     * all read back false, which is why nothing here ever compares by hand.
     * @return bool
     */
    public function enabled(): bool
    {
        return option_bool('suspend_overdue');
    }

    /**
     * How Long After The Due Date a Service Is Left Alone
     * @return int Days
     */
    public function graceDays(): int
    {
        $days = option_int('suspend_overdue_days', self::GRACE_DAYS);

        // Zero would mean "suspend at one second past midnight on the due date",
        // which nobody means by leaving a field empty, and a negative number
        // would suspend before the money had even been asked for.
        return $days > 0 ? $days : self::GRACE_DAYS;
    }

    /**
     * Suspend Everything That Has Run Out Of Grace
     * @return array{done:int,failed:int}
     */
    public function suspendAll(): array
    {
        $done = 0;
        $failed = 0;

        foreach ($this->overdue() as $service) {
            $invoice = $this->delinquent((int) $service['service_id']);

            // Re-checked per service rather than trusted from the sweep that
            // found it. The listing is a join against invoice items; this is the
            // arithmetic. A part-payment that cleared the balance between the two
            // would otherwise cost a paid-up customer their service.
            if ($invoice === null) {
                continue;
            }

            // A member of staff who switched this back on knowing full well the
            // invoice was unpaid has given somebody until a date. Suspending it
            // again four minutes later makes the button they pressed a lie, and
            // makes the operator look to their customer like they cannot work
            // their own software.
            if ($this->onHold($service)) {
                continue;
            }

            $result = $this->suspendService($service, $this->reasonFor($invoice));

            $result['success'] ? $done++ : $failed++;
        }

        return ['done' => $done, 'failed' => $failed];
    }

    /**
     * Bring Back Everything Cron Switched Off That Is Now Paid Up
     * @return array{done:int,failed:int}
     */
    public function restoreAll(): array
    {
        $done = 0;
        $failed = 0;

        foreach ($this->restorable() as $service) {
            $result = $this->restoreService($service);

            $result['success'] ? $done++ : $failed++;
        }

        return ['done' => $done, 'failed' => $failed];
    }

    /**
     * Restore Whatever One Settled Invoice Was Holding Down
     *
     * Called straight from Invoice::applyPayment() and markPaid() so a customer
     * who pays is back in seconds rather than at the next tick. It is an
     * optimisation on top of restoreAll(), never the mechanism - the sweep is
     * what makes the guarantee, because it looks at what is owed rather than at
     * whether anybody remembered to call anything.
     * @param int $invoiceId Invoice ID
     * @return int How many services came back
     */
    public function forInvoice(int $invoiceId): int
    {
        // No enabled() check, for the reason run() gives: a service cron
        // suspended must come back when its invoice is paid whether or not the
        // operator has since switched the sweep off.
        $done = 0;

        foreach ($this->servicesOn($invoiceId) as $serviceId) {
            $service = $this->find($serviceId);

            if (!is_array($service) || !$this->isSuspendedByUs($service)) {
                continue;
            }

            if ($this->delinquent($serviceId) !== null) {
                continue;
            }

            if ($this->restoreService($service)['success']) {
                $done++;
            }
        }

        return $done;
    }

    ####################################################################################
    /*================================== THE VERDICT =================================*/
    ####################################################################################

    /**
     * The Oldest Invoice That Has Run Out Of Grace On This Service
     *
     * The single predicate both directions read. Null means nothing is owed
     * beyond the grace period, which is the definition of "not in arrears" used
     * everywhere in this class.
     *
     * `draft` is excluded along with paid and cancelled: a draft invoice has
     * never been sent to anybody, so it cannot be late.
     * @param int $serviceId Service ID
     * @return ?array The invoice row
     */
    public function delinquent(int $serviceId): ?array
    {
        $invoices = new Invoice();
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . $this->graceDays() . ' days'));

        foreach ($this->invoicesFor($serviceId) as $invoice) {
            $due = (string) ($invoice['invoice_due_date'] ?? '');

            if ($due === '' || strtotime($due) > strtotime($cutoff)) {
                continue;
            }

            if ($invoices->isSettled($invoice)) {
                continue;
            }

            return $invoice;
        }

        return null;
    }

    /**
     * Services That Look Overdue, As One Pass Over The Invoices
     *
     * Driven from the invoice side because that is the small end: a handful of
     * invoices are past grace on any given day, while every active service would
     * otherwise be checked one at a time on every five-minute tick.
     * @return array Service rows
     */
    public function overdue(): array
    {
        $active = Status::idOf(self::SERVICE_STATUSES, 'active');

        if ($active === null) {
            return [];
        }

        $ids = $this->serviceIdsOnLateInvoices();

        if ($ids === []) {
            return [];
        }

        $model = $this->model();

        // Read wider than BATCH, because the attempt cap is applied in PHP. A
        // page full of services whose control panel is refusing would otherwise
        // fill the batch and starve every service that could actually be
        // switched off.
        $rows = $model->whereIn($model->id, $ids)
            ->where(['status_relid' => $active])
            ->order($model->id, self::ASC)
            ->limit(self::BATCH * 5)
            ->get();

        $out = [];

        foreach ($rows as $row) {
            // Bounded, exactly as the restore direction is. The first version of
            // this method had no cap at all, so a control panel that refused
            // every request was called 288 times a day for ever - found by
            // running the sweep a fourth time and watching the counter reach 4.
            //
            // The cap is quiet, and quiet is the risk here: giving up on a
            // suspension leaves somebody with a service they have not paid for,
            // and nobody complains about that. So the failure is kept on the
            // service row and shown on its screen, which is where the operator
            // is looking when they eventually ask why a debtor is still online.
            if ($this->attempts($row, 'suspend') >= self::MAX_ATTEMPTS) {
                continue;
            }

            $out[] = $row;

            if (count($out) >= self::BATCH) {
                break;
            }
        }

        return $out;
    }

    /**
     * Services Cron Switched Off That Are No Longer In Arrears
     * @return array Service rows
     */
    public function restorable(): array
    {
        $suspended = Status::idOf(self::SERVICE_STATUSES, 'suspended');

        if ($suspended === null) {
            return [];
        }

        $model = $this->model();

        // Read wider than BATCH because the marker and the arithmetic are both
        // applied in PHP: a page full of staff-suspended services would
        // otherwise fill the batch and starve the ones genuinely waiting to
        // come back.
        $rows = $model->where(['status_relid' => $suspended])
            ->order($model->id, self::ASC)
            ->limit(self::BATCH * 5)
            ->get();

        $out = [];

        foreach ($rows as $row) {
            if (!$this->isSuspendedByUs($row)) {
                continue;
            }

            if ($this->attempts($row, 'restore') >= self::MAX_ATTEMPTS) {
                continue;
            }

            if ($this->delinquent((int) $row['service_id']) !== null) {
                continue;
            }

            $out[] = $row;

            if (count($out) >= self::BATCH) {
                break;
            }
        }

        return $out;
    }

    /**
     * Whether This Suspension Was Cron's Doing
     *
     * A service with no marker at all reads false. That is the safe direction:
     * rows suspended before this feature existed were suspended by a person, and
     * bringing somebody's abuse suspension back on an unrelated payment is not a
     * mistake anybody notices until the abuse resumes.
     * @param array $service Service Row
     * @return bool
     */
    public function isSuspendedByUs(array $service): bool
    {
        $data = $service['module_data'] ?? null;

        return is_array($data) && ($data['suspended_by'] ?? null) === self::BY_DUNNING;
    }

    ####################################################################################
    /*=================================== THE ACTS ===================================*/
    ####################################################################################

    /**
     * Switch One Service Off
     *
     * @param array $service Service Row
     * @param string $reason Shown to the client, so it is written for them
     * @param string $by self::BY_DUNNING or self::BY_STAFF
     * @return array{success:bool,message:string}
     */
    public function suspendService(array $service, string $reason = '', string $by = self::BY_DUNNING): array
    {
        $serviceId = (int) ($service['service_id'] ?? 0);
        $services = new ClientService();

        if ($services->isFinished($service)) {
            return ['success' => false, 'message' => 'That service has already ended.'];
        }

        $result = $this->call($service, 'suspend', $reason);

        if (!$result['success']) {
            return $this->failed($service, 'suspend', $result['message']);
        }

        $this->remember($service, [
            'suspended_by'     =>  $by,
            'suspended_at'     =>  $this->now(),
            'suspend_attempts' =>  0,
            'restore_attempts' =>  0,
            'last_error'       =>  null,
        ]);

        $services->setStatus($serviceId, 'suspended', $reason);

        (new Activity())->record(
            'service.suspended',
            'Suspended service #' . $serviceId . ' (' . $by . '): ' . $reason
        );

        // Only for the automatic kind. A member of staff switching something off
        // has their own reasons and often their own conversation with the client
        // already under way; a templated "you have not paid" landing in the
        // middle of it is worse than silence.
        if ($by === self::BY_DUNNING) {
            $this->notify($service, 'service-suspended', ['reason' => $reason]);
        }

        return ['success' => true, 'message' => 'Suspended.'];
    }

    /**
     * What Went Wrong Last Time, If Anything Did
     *
     * Read by the service screen. Without it the attempt cap is invisible: the
     * sweep stops trying after MAX_ATTEMPTS and there is nothing anywhere
     * saying so except three activity-log lines somebody would have to go
     * looking for.
     * @param array $service Service Row
     * @return array{error:string,attempts:int,at:string}|null
     */
    public function lastFailure(array $service): ?array
    {
        $data = $service['module_data'] ?? null;

        if (!is_array($data)) {
            return null;
        }

        $error = trim((string) ($data['last_error'] ?? ''));

        if ($error === '') {
            return null;
        }

        $attempts = max(
            (int) ($data['suspend_attempts'] ?? 0),
            (int) ($data['restore_attempts'] ?? 0)
        );

        return [
            'error'    =>  $error,
            'attempts' =>  $attempts,
            'at'       =>  (string) ($data['last_attempt_at'] ?? ''),
            'gave_up'  =>  $attempts >= self::MAX_ATTEMPTS,
        ];
    }

    /**
     * Whether a Reprieve Is Still Running
     * @param array $service Service Row
     * @return bool
     */
    public function onHold(array $service): bool
    {
        $data = $service['module_data'] ?? null;
        $until = is_array($data) ? (string) ($data[self::HOLD] ?? '') : '';

        return $until !== '' && strtotime($until) > strtotime($this->now());
    }

    /**
     * Switch One Service Back On
     *
     * @param array $service Service Row
     * @param bool $hold Give it another grace period before cron may touch it
     * @return array{success:bool,message:string}
     */
    public function restoreService(array $service, bool $hold = false): array
    {
        $serviceId = (int) ($service['service_id'] ?? 0);
        $services = new ClientService();

        if ($services->isFinished($service)) {
            return ['success' => false, 'message' => 'That service has already ended.'];
        }

        $result = $this->call($service, 'unsuspend');

        if (!$result['success']) {
            return $this->failed($service, 'restore', $result['message']);
        }

        $this->remember($service, [
            'suspended_by'     =>  null,
            'suspended_at'     =>  null,
            'restored_at'      =>  $this->now(),
            'suspend_attempts' =>  0,
            'restore_attempts' =>  0,
            'last_error'       =>  null,
            self::HOLD         =>  $hold
                ? date('Y-m-d H:i:s', strtotime('+' . $this->graceDays() . ' days'))
                : null,
        ]);

        // setStatus() clears suspension_reason for any status that is not
        // `suspended`, so a live service stops explaining why it was off.
        $services->setStatus($serviceId, 'active');

        (new Activity())->record('service.restored', 'Restored service #' . $serviceId . '.');

        $this->notify($service, 'service-restored');

        return ['success' => true, 'message' => 'Restored.'];
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Ask The Provisioning Module To Do Something
     *
     * A service with no server, or a server whose module is missing or switched
     * off, reports SUCCESS with no call made. That is not a fudge: an operator
     * who sets accounts up by hand has nothing for this to call, and refusing to
     * record their suspension because no module answered would make the feature
     * useless to them. The distinction that matters is between "nothing to call"
     * and "something answered badly", and only the second is a failure.
     * @param array $service Service Row
     * @param string $verb suspend or unsuspend
     * @param string $reason Passed to suspend()
     * @return array{success:bool,message:string}
     */
    private function call(array $service, string $verb, string $reason = ''): array
    {
        $server = $this->serverRow((int) ($service['server_relid'] ?? 0));

        if ($server === null) {
            return ['success' => true, 'message' => 'No server; recorded without a module call.'];
        }

        $driver = $this->driverFor($server);

        if ($driver === null) {
            return ['success' => true, 'message' => 'No module; recorded without a module call.'];
        }

        $context = [
            'server'  =>  $server,
            'client'  =>  (new Client())->find((int) ($service['client_relid'] ?? 0)) ?? [],
            'product' =>  (new Product())->find((int) ($service['product_relid'] ?? 0)) ?? [],
            'options' =>  [],
        ];

        try {
            $result = $verb === 'suspend'
                ? $driver->suspend($service, $reason, $context)
                : $driver->unsuspend($service, $context);
        } catch (Throwable $e) {
            // A module that throws and a module that returns false are two
            // shapes of the same event. Catching here is what stops one
            // unreachable control panel taking down the whole cron run.
            return ['success' => false, 'message' => 'The module raised an error: ' . $e->getMessage()];
        }

        if (!is_array($result) || ($result['success'] ?? false) !== true) {
            return [
                'success' =>  false,
                'message' =>  trim((string) ($result['message'] ?? '')) ?: 'The module reported a failure.',
            ];
        }

        return ['success' => true, 'message' => (string) ($result['message'] ?? 'Done.')];
    }

    /**
     * Every Unsettled Invoice Covering One Service, Oldest Due Date First
     * @param int $serviceId Service ID
     * @return array
     */
    private function invoicesFor(int $serviceId): array
    {
        $rows = (new InvoiceItemModel())->where(['service_relid' => $serviceId])->get();

        $ids = [];

        foreach ($rows as $row) {
            $id = (int) ($row['invoice_relid'] ?? 0);

            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        if ($ids === []) {
            return [];
        }

        $model = new InvoiceModel();
        $model->whereIn($model->id, array_values($ids));

        $excluded = $this->settledOrDraft();

        if ($excluded !== []) {
            $model->whereNotIn('status_relid', $excluded);
        }

        return $model->order('invoice_due_date', self::ASC)->get();
    }

    /**
     * The Services Named By Items On Invoices That Are Past Grace
     * @return int[]
     */
    private function serviceIdsOnLateInvoices(): array
    {
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . $this->graceDays() . ' days'));

        $invoices = new InvoiceModel();
        $invoices->notNull('invoice_due_date')->where(['invoice_due_date' => $cutoff], '<=');

        $excluded = $this->settledOrDraft();

        if ($excluded !== []) {
            $invoices->whereNotIn('status_relid', $excluded);
        }

        $late = $invoices->order($invoices->id, self::ASC)->limit(self::BATCH * 10)->get();

        $invoiceIds = [];

        foreach ($late as $row) {
            $invoiceIds[] = (int) $row['invoice_id'];
        }

        if ($invoiceIds === []) {
            return [];
        }

        $rows = (new InvoiceItemModel())
            ->whereIn('invoice_relid', $invoiceIds)
            ->notNull('service_relid')
            ->get();

        $ids = [];

        foreach ($rows as $row) {
            $id = (int) ($row['service_relid'] ?? 0);

            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * Invoice Status Ids That Cannot Be Late
     *
     * Paid, cancelled and draft. Deliberately not Invoice::settledStatusIds(),
     * which is paid and cancelled only - it answers "is anything still owed" for
     * the unpaid listings, and a draft invoice is owed in that sense while being
     * something nobody has ever been shown.
     * @return int[]
     */
    private function settledOrDraft(): array
    {
        return array_values(array_filter([
            Status::idOf(self::INVOICE_STATUSES, 'paid'),
            Status::idOf(self::INVOICE_STATUSES, 'cancelled'),
            Status::idOf(self::INVOICE_STATUSES, 'draft'),
        ]));
    }

    /**
     * The Service Ids On One Invoice
     * @param int $invoiceId Invoice ID
     * @return int[]
     */
    private function servicesOn(int $invoiceId): array
    {
        $rows = (new InvoiceItemModel())
            ->where(['invoice_relid' => $invoiceId])
            ->notNull('service_relid')
            ->get();

        $ids = [];

        foreach ($rows as $row) {
            $id = (int) ($row['service_relid'] ?? 0);

            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * What The Client Is Told, In Their Own Terms
     *
     * The contract says the reason is shown to the client, so it is written for
     * them: which invoice it is about. "Overdue invoice" on its own tells
     * somebody with four invoices nothing they can act on.
     * @param array $invoice Invoice Row
     * @return string
     */
    private function reasonFor(array $invoice): string
    {
        $number = trim((string) ($invoice['invoice_number'] ?? ''));

        return $number === ''
            ? 'Suspended for non-payment.'
            : 'Suspended because invoice ' . $number . ' has not been paid.';
    }

    /**
     * Record a Failed Module Call
     *
     * The status is deliberately left where it was. See the class docblock: a
     * suspension recorded against a service that is still running is worse than
     * no suspension at all.
     * @param array $service Service Row
     * @param string $verb suspend or restore
     * @param string $why What went wrong
     * @return array{success:bool,message:string}
     */
    private function failed(array $service, string $verb, string $why): array
    {
        $attempts = $this->attempts($service, $verb) + 1;

        $this->remember($service, [
            $verb . '_attempts' =>  $attempts,
            'last_error'        =>  mb_substr($why, 0, 500),
            'last_attempt_at'   =>  $this->now(),
        ]);

        (new Activity())->record(
            'service.' . $verb . '_failed',
            'Could not ' . $verb . ' service #' . (int) ($service['service_id'] ?? 0)
                . ' (attempt ' . $attempts . '): ' . $why
        );

        return ['success' => false, 'message' => $why];
    }





    /**
     * Tell The Client What Happened To Their Service
     *
     * Queued, never sent inline - cron drains the queue on the same tick. A mail
     * server that is refusing connections must not stop a suspension being
     * recorded, so every failure here is logged and then swallowed.
     *
     * client_area is NOT passed. Templater::withDefaults() supplies it from
     * app_host, which is the only source that works here - named() builds a URL
     * from the request, and a cron run has no request to build one from.
     * @param array $service Service Row
     * @param string $slug Template Slug
     * @param array $extra Extra Placeholders
     * @return void
     */
    private function notify(array $service, string $slug, array $extra = []): void
    {
        $client = (new Client())->find((int) ($service['client_relid'] ?? 0));

        if (!is_array($client)) {
            return;
        }

        $email = trim((string) ($client['email'] ?? ''));

        if ($email === '') {
            return;
        }

        $product = (new Product())->find((int) ($service['product_relid'] ?? 0));

        try {
            (new Mail())->queueTemplate($slug, $email, $extra + [
                'first_name'   =>  (string) ($client['first_name'] ?? ''),
                'last_name'    =>  (string) ($client['last_name'] ?? ''),
                'service_name' =>  (string) ($product['product_name'] ?? 'your service'),
                'domain'       =>  (string) ($service['domain'] ?? ''),
            ], (int) ($service['client_relid'] ?? 0));
        } catch (Throwable $e) {
            (new Activity())->record(
                'service.notify_failed',
                'Could not queue the ' . $slug . ' message for service #'
                    . (int) ($service['service_id'] ?? 0) . ': ' . $e->getMessage()
            );
        }
    }
}
