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
use LBM\Model\BillingCycleModel;
use LBM\Model\ClientServiceModel;
use LBM\Model\OrderItemModel;
use LBM\Model\OrderModel;
use LBM\Model\ServerModel;
use LBM\Service\Status;
use LBM\Support\ServesServices;

/**
 * Turning a paid invoice into a service somebody can actually use.
 *
 * This is the end of the chain the whole product exists for: catalogue, cart,
 * order, invoice, payment - and then something is delivered. It runs in two
 * halves, deliberately kept apart.
 *
 *   HALF ONE, `forInvoice()`: rows only. When an invoice settles, every product
 *   line on its order becomes a `client_services` row in `pending`. No network,
 *   no modules, nothing that can be slow or fail - so it is safe to call from
 *   inside a web request, which is what makes a customer's service appear the
 *   moment they pay rather than up to five minutes later.
 *
 *   HALF TWO, `deliver()`: the module call. Talking to somebody else's control
 *   panel is slow and fails often, so it happens out of band, from cron. The
 *   ServerInterface docblock says this outright - "these are called from a queue
 *   worker, not a web request" - and on a shipped install cron IS that worker,
 *   because `php worker` is a developer tool and is not in the archive.
 *
 * ---------------------------------------------------------------------------
 * THE ORDER ITEM IS THE MARKER, not a flag
 * ---------------------------------------------------------------------------
 * A line is provisioned when `order_items.service_relid` is empty and skipped
 * when it is not. That is the same rule InvoiceGenerateJob follows for double
 * billing, and for the same reason: a separate "provisioned" flag can fall out
 * of step with reality, whereas the link to the service IS the reality. Running
 * this twice therefore costs nothing, which matters because the safety net
 * below runs it on every cron tick.
 *
 * ---------------------------------------------------------------------------
 * ONE SERVICE PER PRODUCT, NOT PER LINE
 * ---------------------------------------------------------------------------
 * Phase 22.2 made a setup fee its own order line, on the `one_time` cycle,
 * beside the recurring line for the same product. Those are two lines and one
 * thing bought - so lines are grouped by (product, domain) and both get pointed
 * at the same service. Provisioning per line would hand the customer two
 * hosting accounts and charge them for one.
 *
 * ---------------------------------------------------------------------------
 * WHY THERE IS NO HOOK ON "invoice paid"
 * ---------------------------------------------------------------------------
 * There are two independent ways an invoice settles - `Invoice::applyPayment()`
 * for money and `Invoice::applyCredit()` for account credit, and the second does
 * not go through the first - plus `markPaid()`, which staff can reach directly.
 * A hook would have to be remembered in three places, and the one nobody
 * remembers is the one that silently never provisions.
 *
 * So `reconcile()` sweeps for settled invoices whose orders still have
 * unprovisioned lines, and cron runs it. The synchronous calls are an
 * optimisation on top of that, not the mechanism: if one is ever missed, the
 * next cron tick puts it right.
 */
class Provision extends Action
{
    use ServesServices;

    /** @var string Service Status Lookup Table */
    public const SERVICE_STATUSES = 'client_service_statuses';

    /** @var string Order Status Lookup Table */
    public const ORDER_STATUSES = 'order_statuses';

    /** @var int How Many Times a Failing Service Is Retried Before Staff Are Left To It */
    public const MAX_ATTEMPTS = 3;

    /** @var int Most Services One Cron Tick Will Try To Deliver */
    public const BATCH = 20;

    /** @var array<string,int>|null Billing Cycle Ids, Keyed By Name */
    private ?array $cycleIds = null;

    public function model(): Model
    {
        return new ClientServiceModel();
    }

    ####################################################################################
    /*=================================== THE CHAIN ==================================*/
    ####################################################################################

    /**
     * The Cron Entry Point
     *
     * Reconcile first, then deliver: a service created by this same tick should
     * not have to wait for the next one.
     * @return string What happened, for the cron log
     */
    public function run(): string
    {
        $created = $this->reconcile();
        $delivered = $this->deliver();

        return $created . ' created, ' . $delivered['done'] . ' provisioned, '
            . $delivered['failed'] . ' failed';
    }

    /**
     * Find Settled Invoices Whose Orders Still Need Services
     *
     * The safety net described in the class docblock. It looks at what is true -
     * a paid invoice, an order, a line with no service - rather than at whether
     * anybody remembered to call forInvoice().
     * @return int How many services were created
     */
    public function reconcile(): int
    {
        $invoice = new Invoice();
        $orders = new OrderModel();
        $created = 0;

        // Orders that have an invoice. Bounded so a backlog cannot make one
        // cron tick run for an hour.
        $rows = $orders->where(['invoice_relid' => null], '!=')
            ->order($orders->id, self::DESC)
            ->limit(self::BATCH * 5)
            ->get();

        foreach ($rows as $order) {
            $invoiceRow = $invoice->find((int) $order['invoice_relid']);

            if (!is_array($invoiceRow) || !$this->isPaid($invoiceRow)) {
                continue;
            }

            $created += count($this->forOrder($order));
        }

        return $created;
    }

    /**
     * Whether An Invoice Has Actually Been Paid For
     *
     * NOT `Invoice::settledStatusIds()`, and the difference matters enough to
     * spell out: that list is `paid` AND `cancelled`, because it exists to
     * answer "is anything still owed on this" for the unpaid listings and the
     * dashboard. A cancelled invoice owes nothing - and provisioning a service
     * against one would hand over a product the operator has just withdrawn the
     * bill for. Reading it as "was this paid" was the first version of
     * reconcile() and it was wrong.
     *
     * Two ways to be paid, and both are honest:
     *
     *   - the arithmetic says so, `amount_paid + credit >= total`; or
     *   - the STATUS says so, which is a member of staff declaring it - and
     *     markPaid() sets exactly that without touching amount_paid, so an
     *     invoice settled by hand does not satisfy the arithmetic at all.
     *
     * Cancelled overrides both. An invoice paid and then cancelled is a refund
     * waiting to happen, not a service waiting to be built.
     * @param array $invoice Invoice Row
     * @return bool
     */
    private function isPaid(array $invoice): bool
    {
        $status = (int) ($invoice['status_relid'] ?? 0);

        $cancelled = Status::idOf(Invoice::STATUSES, 'cancelled');

        if ($cancelled !== null && $status === $cancelled) {
            return false;
        }

        $paid = Status::idOf(Invoice::STATUSES, 'paid');

        if ($paid !== null && $status === $paid) {
            return true;
        }

        return (new Invoice())->isSettled($invoice);
    }

    /**
     * Create The Services An Invoice Has Paid For
     *
     * Safe to call at any time, from anywhere, as often as you like: a line that
     * already has a service is skipped, so this is the method the synchronous
     * callers use and the method the sweep uses.
     * @param int $invoiceId Invoice ID
     * @return array<int,int> The service ids created, empty when there was nothing to do
     */
    public function forInvoice(int $invoiceId): array
    {
        if ($invoiceId <= 0) {
            return [];
        }

        $invoice = (new Invoice())->find($invoiceId);

        // Only a paid invoice provisions anything. A part payment is not a
        // payment, and a service handed over on one is a service given away.
        // isPaid() rather than isSettled() so this agrees exactly with the
        // sweep - including on an invoice a member of staff marked paid by
        // hand, which moves the status and not amount_paid.
        if (!is_array($invoice) || !$this->isPaid($invoice)) {
            return [];
        }

        $orders = new OrderModel();
        $order = $orders->where(['invoice_relid' => $invoiceId])->first();

        return is_array($order) ? $this->forOrder($order) : [];
    }

    /**
     * Create The Services One Order Needs
     * @param array $order Order Row
     * @return array<int,int> The service ids created
     */
    public function forOrder(array $order): array
    {
        $orderId = (int) ($order['oid'] ?? 0);

        if ($orderId <= 0) {
            return [];
        }

        $groups = $this->groupLines($orderId);

        if ($groups === []) {
            return [];
        }

        $created = [];

        foreach ($groups as $group) {
            $id = $this->serviceFor($order, $group);

            if ($id > 0) {
                $created[] = $id;
            }
        }

        // The order is delivered, so it is no longer merely placed. Phase 22.2
        // left a customer's order `pending` precisely so this moment - money
        // received - is what activates it.
        if ($created !== []) {
            $active = Status::idOf(self::ORDER_STATUSES, 'active');

            if ($active !== null && (int) ($order['status_relid'] ?? 0) !== $active) {
                (new Order())->update($orderId, ['status_relid' => $active]);
            }
        }

        return $created;
    }

    /**
     * Hand Pending Services To Their Provisioning Modules
     *
     * The slow half. Every failure is caught and recorded on the service rather
     * than thrown, because one control panel being unreachable must not stop the
     * other nineteen services in the batch from being set up.
     * @return array{done:int,failed:int}
     */
    public function deliver(): array
    {
        $done = 0;
        $failed = 0;

        foreach ($this->awaiting() as $service) {
            $result = $this->provision($service);

            $result['success'] ? $done++ : $failed++;
        }

        return ['done' => $done, 'failed' => $failed];
    }

    /**
     * Services Waiting To Be Set Up
     *
     * `pending`, on a server, and not already tried too many times. A service
     * whose product has no provisioning module never appears here at all - it is
     * left pending for a member of staff, which is the correct outcome for an
     * operator who sets things up by hand.
     * @return array
     */
    public function awaiting(): array
    {
        $pending = Status::idOf(self::SERVICE_STATUSES, 'pending');

        if ($pending === null) {
            return [];
        }

        $model = $this->model();

        $rows = $model->where(['status_relid' => $pending])
            ->order($model->id, self::ASC)
            ->limit(self::BATCH * 5)
            ->get();

        $out = [];

        foreach ($rows as $row) {
            if ((int) ($row['server_relid'] ?? 0) <= 0) {
                continue;
            }

            if ($this->attempts($row, 'provision') >= self::MAX_ATTEMPTS) {
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
     * Set One Service Up Through Its Module
     *
     * @param array $service Service Row
     * @return array{success:bool,message:string}
     */
    public function provision(array $service): array
    {
        $serviceId = (int) ($service['service_id'] ?? 0);
        $product = (new Product())->find((int) ($service['product_relid'] ?? 0));
        $server = $this->serverRow((int) ($service['server_relid'] ?? 0));

        if (!is_array($product) || !is_array($server)) {
            return $this->failed($service, 'The product or server this service points at is gone.');
        }

        $driver = $this->driverFor($server);

        if ($driver === null) {
            return $this->failed(
                $service,
                'No provisioning module is available for server ' . ($server['name'] ?? '?') . '.'
            );
        }

        try {
            $result = $driver->create($service, [
                'server'  =>  $server,
                'client'  =>  (new Client())->find((int) ($service['client_relid'] ?? 0)) ?? [],
                'product' =>  $product,
                'options' =>  [],
            ]);
        } catch (Throwable $e) {
            // A module that throws is a module that failed, not a fatal for the
            // cron run. The message is recorded so staff can see it.
            return $this->failed($service, 'The module raised an error: ' . $e->getMessage());
        }

        if (!is_array($result) || ($result['success'] ?? false) !== true) {
            return $this->failed(
                $service,
                trim((string) ($result['message'] ?? '')) ?: 'The module reported a failure.'
            );
        }

        $services = new ClientService();

        $username = trim((string) ($result['username'] ?? ''));

        if ($username !== '') {
            $services->modify($serviceId, ['username' => $username]);
        }

        // Through setCredential(), which encrypts. Never written in the clear,
        // never logged - the contract says so and this is the only place that
        // sees the value at all.
        $password = (string) ($result['password'] ?? '');

        if ($password !== '') {
            $services->setCredential($serviceId, $password);
        }

        $this->remember($service, ['provisioned_at' => $this->now(), 'last_error' => null]);

        $services->setStatus($serviceId, 'active');

        (new Activity())->record(
            'service.provisioned',
            'Provisioned service #' . $serviceId . ' on ' . ($server['name'] ?? 'a server') . '.'
        );

        return ['success' => true, 'message' => 'Provisioned.'];
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * The Order's Product Lines, Grouped Into One Entry Per Thing Bought
     *
     * See the class docblock: a setup fee is a second line for the same product,
     * so grouping by (product, domain) is what stops it becoming a second
     * service. The recurring line is preferred as the group's leader because it
     * is the one carrying the ongoing price and cycle.
     * @param int $orderId Order ID
     * @return array<string,array<string,mixed>>
     */
    private function groupLines(int $orderId): array
    {
        $model = new OrderItemModel();

        $lines = $model->where(['order_relid' => $orderId])
            ->order($model->id, self::ASC)
            ->get();

        $groups = [];

        foreach ($lines as $line) {
            // Only products become services. A `domain` line is a registrar's
            // job, and 22.4 does not do domains - see the phase notes.
            if ((string) ($line['type'] ?? '') !== 'product') {
                continue;
            }

            $productId = (int) ($line['product_relid'] ?? 0);

            if ($productId <= 0) {
                continue;
            }

            // Already provisioned. The marker, and the whole idempotency story.
            if ((int) ($line['service_relid'] ?? 0) > 0) {
                continue;
            }

            $key = $productId . '|' . strtolower(trim((string) ($line['domain'] ?? '')));

            $groups[$key] ??= ['lines' => [], 'lead' => null];
            $groups[$key]['lines'][] = $line;

            $cycle = (string) ($line['billing_cycle'] ?? '');

            // The recurring line leads. A group with nothing but a one-off line
            // is a one-off product, and then that line leads by default.
            if ($groups[$key]['lead'] === null || $cycle !== 'one_time') {
                if ($groups[$key]['lead'] === null
                    || (string) ($groups[$key]['lead']['billing_cycle'] ?? '') === 'one_time') {
                    $groups[$key]['lead'] = $line;
                }
            }
        }

        return $groups;
    }

    /**
     * Create One Service From a Group Of Order Lines
     *
     * @param array $order Order Row
     * @param array $group Grouped Lines
     * @return int The new service id, or 0
     */
    private function serviceFor(array $order, array $group): int
    {
        $lead = $group['lead'];

        if (!is_array($lead)) {
            return 0;
        }

        $productId = (int) $lead['product_relid'];
        $product = (new Product())->find($productId);

        if (!is_array($product)) {
            return 0;
        }

        $cycleName = (string) ($lead['billing_cycle'] ?? '') ?: 'monthly';
        $cycleId = $this->cycleId($cycleName);

        $serviceId = (new ClientService())->store([
            'client_relid'        =>  (int) $order['client_relid'],
            'product_relid'       =>  $productId,
            'server_relid'        =>  $this->pickServer($product),
            'domain'              =>  $lead['domain'] ?? null,
            'billing_cycle_relid' =>  $cycleId,
            'currency_relid'      =>  (int) $order['currency_relid'],
            'amount'              =>  (string) ($lead['amount'] ?? '0'),
            'registration_date'   =>  $this->now(),
            'next_due_date'       =>  $this->nextDue($cycleName),
        ]);

        if ($serviceId <= 0) {
            return 0;
        }

        // Point EVERY line in the group at it, the setup-fee line included, so
        // the marker is complete and a second run has nothing left to pick up.
        $items = new OrderItemModel();

        foreach ($group['lines'] as $line) {
            $items->where([$items->id => (int) $line['order_item_id']])
                ->update(['service_relid' => $serviceId]);
        }

        return $serviceId;
    }

    /**
     * Which Server a Product's Services Go On
     *
     * There is no product-to-server column in the schema, so the link is the
     * MODULE NAME: a product provisioned by `cpanel` belongs on a server running
     * `cpanel`. That is the only honest reading of what the two columns mean,
     * and it is why a product with no module gets no server and stays pending
     * for staff - which is the right outcome for an operator who sets accounts
     * up by hand.
     *
     * Among the candidates, the server group's fill type decides. `least_full`
     * takes the one with fewest accounts; everything else takes the first with
     * room, in id order, which is what "sequentially" means.
     * @param array $product Product Row
     * @return ?int
     */
    private function pickServer(array $product): ?int
    {
        $module = trim((string) ($product['module_name'] ?? ''));

        if ($module === '') {
            return null;
        }

        $active = (new Server())->statusId('active');
        $model = new ServerModel();

        $where = ['module_name' => $module];

        if ($active !== null) {
            $where['status_relid'] = $active;
        }

        $servers = $model->where($where)->order($model->id, self::ASC)->get();

        $candidates = [];

        foreach ($servers as $server) {
            $max = $server['max_accounts'] ?? null;

            // NULL max_accounts means uncapped, which is different from zero.
            if ($max !== null && (int) $server['active_accounts'] >= (int) $max) {
                continue;
            }

            $candidates[] = $server;
        }

        if ($candidates === []) {
            return null;
        }

        $fill = $this->fillType((int) ($candidates[0]['group_relid'] ?? 0));

        if ($fill === 'least_full') {
            usort(
                $candidates,
                static fn (array $a, array $b): int
                    => (int) $a['active_accounts'] <=> (int) $b['active_accounts']
            );
        }

        return (int) $candidates[0]['server_id'];
    }

    /**
     * A Server Group's Fill Type
     * @param int $groupId Group ID
     * @return string
     */
    private function fillType(int $groupId): string
    {
        if ($groupId <= 0) {
            return 'sequentially';
        }

        $group = (new Server())->group($groupId);

        return (string) ($group['fill_type'] ?? 'sequentially');
    }


    /**
     * Record a Failed Attempt On The Service
     *
     * The service stays `pending`, so cron tries again - up to MAX_ATTEMPTS,
     * after which it is left alone. A control panel that is down for an hour
     * should be retried; one that refuses the credentials should not be
     * hammered for ever, and the operator needs to be the one who fixes it.
     * @param array $service Service Row
     * @param string $why What went wrong
     * @return array{success:bool,message:string}
     */
    private function failed(array $service, string $why): array
    {
        $attempts = $this->attempts($service, 'provision') + 1;

        $this->remember($service, [
            'provision_attempts' =>  $attempts,
            'last_error'         =>  mb_substr($why, 0, 500),
            'last_attempt_at'    =>  $this->now(),
        ]);

        (new Activity())->record(
            'service.provision_failed',
            'Could not provision service #' . (int) ($service['service_id'] ?? 0)
                . ' (attempt ' . $attempts . '): ' . $why
        );

        return ['success' => false, 'message' => $why];
    }




    /**
     * When The Next Invoice Is Due
     *
     * The same intervals InvoiceGenerateJob::periodEnd() uses, and they have to
     * stay the same: this sets the date that job then bills against, so two
     * different tables of cycle lengths would bill on a date the service does
     * not agree with.
     * @param string $cycle Cycle Name
     * @return ?string
     */
    private function nextDue(string $cycle): ?string
    {
        if ($cycle === 'one_time') {
            return null;
        }

        $interval = match ($cycle) {
            'semi_annual' =>  '+6 months',
            'annual'      =>  '+1 year',
            'biennial'    =>  '+2 years',
            'triennial'   =>  '+3 years',
            'quarterly'   =>  '+3 months',
            'weekly'      =>  '+1 week',
            default       =>  '+1 month',
        };

        return date('Y-m-d H:i:s', strtotime($interval, strtotime($this->now())));
    }

    /**
     * A Billing Cycle's Id, By Name
     * @param string $name Cycle Name
     * @return int
     */
    private function cycleId(string $name): int
    {
        if ($this->cycleIds === null) {
            $this->cycleIds = [];

            foreach ((new BillingCycleModel())->get() as $row) {
                $this->cycleIds[(string) $row['billing_cycle_name']] = (int) $row['billing_cycle_id'];
            }
        }

        return $this->cycleIds[$name] ?? ($this->cycleIds['monthly'] ?? 1);
    }
}
