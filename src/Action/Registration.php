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
use LBM\Model\OrderItemModel;
use LBM\Model\OrderModel;
use LBM\Service\Status;
use LBM\Support\RegistersDomains;

/**
 * Turning a paid domain line into a name the customer actually owns.
 *
 * `Provision` for domains, and built the same way for the same reasons - read
 * that class docblock first, because every argument in it applies here and is
 * not repeated. In two halves:
 *
 *   HALF ONE, `forInvoice()`: rows only. Every paid `domain` order line becomes
 *   a `domains` row in `pending`. No network, so it is safe inside a web
 *   request and the customer sees their domain the moment they pay.
 *
 *   HALF TWO, `deliver()`: the registrar call, from cron, because talking to a
 *   registry is slow and fails often.
 *
 * ---------------------------------------------------------------------------
 * THE ORDER ITEM IS THE MARKER
 * ---------------------------------------------------------------------------
 * `order_items.domain_relid` - the column Phase 0 commented "populated after
 * domain registration" and nothing has ever populated. Empty means the line
 * still needs a domain, set means it has one. Same rule as `service_relid`, so
 * running this twice costs nothing.
 *
 * ---------------------------------------------------------------------------
 * ONE LINE, ONE DOMAIN - THERE IS NOTHING TO GROUP
 * ---------------------------------------------------------------------------
 * A product is bought as two lines (the recurring one and the setup fee) and
 * `Provision` has to group them. A domain is one line: the price for the whole
 * term is charged once, up front, because that is what registering for two
 * years costs. So there is no grouping here, and its absence is deliberate
 * rather than missing.
 *
 * ---------------------------------------------------------------------------
 * THE NAME MAY HAVE GONE WHILE THEY WERE PAYING
 * ---------------------------------------------------------------------------
 * `domains.domain` is UNIQUE across the install, and between the cart page and
 * the payment somebody else can take the name. The cart checks at render time,
 * which closes the ordinary case; what is left is a genuine race, and this is
 * where it lands.
 *
 * `Domain::store()` refuses a name that is already recorded, so a duplicate
 * never reaches the driver and no cron tick dies of a unique-key error. What
 * the lookup here adds is the DISTINCTION, which that refusal cannot make:
 *
 *   - Somebody else has it. The customer has paid for a name they cannot have,
 *     and only a person can choose between a refund, a substitute and a
 *     transfer - so it is logged and the order is left undelivered.
 *   - The SAME client has it. Not a conflict at all: it is this order, recorded
 *     on an earlier run by a sweep that stopped before it could write the
 *     marker. The marker is completed and nothing is created.
 *
 * Without the lookup both collapse into the first, and a customer whose order
 * was half-processed once is stuck there for good.
 */
class Registration extends Action
{
    use RegistersDomains;

    /** @var string Domain Status Lookup Table */
    public const STATUSES = 'domain_statuses';

    /** @var string Order Status Lookup Table */
    public const ORDER_STATUSES = 'order_statuses';

    /** @var int How Many Times a Failing Registration Is Retried Before Staff Are Left To It */
    public const MAX_ATTEMPTS = 3;

    /** @var int Most Domains One Cron Tick Will Try To Register */
    public const BATCH = 20;

    /**
     * @var bool Whether The Last domainFor() Call Wrote a New Row
     *
     * domainFor() has three outcomes and only two of them can be told apart
     * from its return value: it created a row, it found the client own row
     * already there, or it refused. The first two both answer with an id.
     */
    private bool $wasCreated = false;

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
        $created = $this->reconcile();
        $delivered = $this->deliver();

        return $created . ' recorded, ' . $delivered['done'] . ' registered, '
            . $delivered['failed'] . ' failed, ' . $delivered['manual'] . ' for staff';
    }

    /**
     * Find Settled Invoices Whose Orders Still Need Domains
     *
     * `Provision::reconcile()` with domains in place of services. Same safety
     * net, same bound on the batch, same reason.
     * @return int How many domain records were created
     */
    public function reconcile(): int
    {
        $invoice = new Invoice();
        $provision = new Provision();
        $orders = new OrderModel();
        $created = 0;

        // notNull(), NOT where([... => null], '!='). The second renders as
        // `invoice_relid != NULL`, which is never true in SQL, so the sweep
        // returned NOTHING - see the phase notes. It looked healthy because the
        // cron line reads "0 created" whether there is nothing to do or the
        // question cannot be answered.
        $rows = $orders->notNull('invoice_relid')
            ->order($orders->id, self::DESC)
            ->limit(self::BATCH * 5)
            ->get();

        foreach ($rows as $order) {
            $invoiceRow = $invoice->find((int) $order['invoice_relid']);

            // Provision owns the definition of "paid" - see its docblock, which
            // is the argument for why this is not settledStatusIds(). Asking it
            // rather than repeating it is what stops the two sweeps ever
            // disagreeing about whether the same invoice counts.
            if (!is_array($invoiceRow) || !$provision->isPaid($invoiceRow)) {
                continue;
            }

            $created += count($this->forOrder($order));
        }

        return $created;
    }

    /**
     * Record The Domains An Invoice Has Paid For
     *
     * Safe to call at any time, as often as you like: a line that already has a
     * domain is skipped.
     * @param int $invoiceId Invoice ID
     * @return array<int,int> The domain ids created
     */
    public function forInvoice(int $invoiceId): array
    {
        if ($invoiceId <= 0) {
            return [];
        }

        $invoice = (new Invoice())->find($invoiceId);

        if (!is_array($invoice) || !(new Provision())->isPaid($invoice)) {
            return [];
        }

        $orders = new OrderModel();
        $order = $orders->where(['invoice_relid' => $invoiceId])->first();

        return is_array($order) ? $this->forOrder($order) : [];
    }

    /**
     * Record The Domains One Order Needs
     * @param array $order Order Row
     * @return array<int,int> The domain ids created
     */
    public function forOrder(array $order): array
    {
        $orderId = (int) ($order['oid'] ?? 0);

        if ($orderId <= 0) {
            return [];
        }

        $model = new OrderItemModel();

        $lines = $model->where(['order_relid' => $orderId, 'type' => 'domain'])
            ->order($model->id, self::ASC)
            ->get();

        if ($lines === []) {
            return [];
        }

        $created = [];
        $handled = 0;

        foreach ($lines as $line) {
            if ((int) ($line['domain_relid'] ?? 0) > 0) {
                $handled++;

                continue;
            }

            $id = $this->domainFor($order, $line);

            if ($id <= 0) {
                continue;
            }

            $handled++;

            $items = new OrderItemModel();

            $items->where([$items->id => (int) $line['order_item_id']])
                ->update(['domain_relid' => $id]);

            // Only a row this call CREATED is counted. domainFor() also answers
            // with the id of a row that was already there, which completes the
            // marker without being a new registration - counting those would
            // make the cron line say it registered domains it merely found.
            if ($this->wasCreated) {
                $created[] = $id;
            }
        }

        // An order holding nothing but domains is never touched by Provision,
        // so if this did not activate it the customer would pay and watch their
        // order sit at `pending` for ever. Only once every domain line on it has
        // been dealt with: an order half of whose names were taken is not
        // delivered.
        if ($handled > 0 && $handled === count($lines)) {
            $active = Status::idOf(self::ORDER_STATUSES, 'active');

            if ($active !== null && (int) ($order['status_relid'] ?? 0) !== $active) {
                (new Order())->update($orderId, ['status_relid' => $active]);
            }
        }

        return $created;
    }

    /**
     * Hand Pending Domains To Their Registrar Modules
     *
     * Every failure is caught and recorded on the row rather than thrown: one
     * registry being unreachable must not stop the other nineteen names in the
     * batch from being registered.
     * @return array{done:int,failed:int,manual:int}
     */
    public function deliver(): array
    {
        $done = 0;
        $failed = 0;
        $manual = 0;

        foreach ($this->awaiting() as $domain) {
            $result = $this->register($domain);

            if (!empty($result['manual'])) {
                $manual++;

                continue;
            }

            $result['success'] ? $done++ : $failed++;
        }

        return ['done' => $done, 'failed' => $failed, 'manual' => $manual];
    }

    /**
     * Domains Waiting To Be Registered
     *
     * `pending`, OF TYPE `register`, and not already tried too many times.
     *
     * The type filter is not decoration. Until Phase 27.3 every row in this
     * table was a registration, so selecting on status alone was right by
     * accident - and the moment a transfer can be pending, the same query
     * hands a name somebody else already owns to register(), which fails three
     * times and writes three errors onto a transfer that was proceeding
     * perfectly well. Action\Transfer::pending() is the mirror of this.
     * @return array
     */
    public function awaiting(): array
    {
        $pending = Status::idOf(self::STATUSES, 'pending');

        if ($pending === null) {
            return [];
        }

        $model = $this->model();

        $rows = $model->where(['status_relid' => $pending, 'type' => 'register'])
            ->order($model->id, self::ASC)
            ->limit(self::BATCH * 5)
            ->get();

        $out = [];

        foreach ($rows as $row) {
            if ($this->domainAttempts($row, 'register') >= self::MAX_ATTEMPTS) {
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
     * Register One Domain At Its Registrar
     *
     * @param array $domain Domain Row
     * @return array{success:bool,manual:bool,message:string}
     */
    public function register(array $domain): array
    {
        $domainId = (int) ($domain['domain_id'] ?? 0);
        $name = (string) ($domain['domain'] ?? '');

        $driver = $this->driverForDomain($domain);

        // No module for this registrar, or the operator has switched it off.
        // NOT a failure and NOT an attempt: an operator who registers names by
        // hand has no module at all, and counting three attempts against a name
        // nothing ever tried would put a false failure on the row.
        if ($driver === null) {
            return [
                'success' =>  false,
                'manual'  =>  true,
                'message' =>  'No registrar module. Left for staff to register by hand.',
            ];
        }

        $context = $this->contextFor($domain);

        try {
            $result = $driver->register($domain, $context);
        } catch (Throwable $e) {
            return $this->failed($domain, 'The registrar module threw: ' . $e->getMessage());
        }

        if (!is_array($result) || empty($result['success'])) {
            $message = is_array($result) ? (string) ($result['message'] ?? '') : '';

            return $this->failed($domain, $message !== '' ? $message : 'The registrar refused it.');
        }

        $expiry = $this->expiryFrom($result);

        // The two columns are NOT the same question, and 27.1 shipped them as
        // if they were. `expiry_date` is a registry fact or null; a registrar
        // that reported no date left BOTH null, and DomainRenewalJob bills from
        // next_due_date - so that domain would never have been billed again by
        // anything, silently, for ever.
        //
        // next_due_date is our own schedule and is always set: from the registry
        // date when there is one, and from the term that was paid for when there
        // is not. A term is running either way.
        $years = max(1, (int) ($context['years'] ?? 1));

        $data = [
            'registration_date' =>  $this->now(),
            'expiry_date'       =>  $expiry,
            'next_due_date'     =>  $expiry ?? date('Y-m-d H:i:s', strtotime("+{$years} years")),
        ];

        $this->update($domainId, $data);

        // A success with no expiry date is still a success, and that is the
        // important half. The name IS registered - refusing to record it would
        // send the next cron tick to register it a SECOND time, which is the
        // one outcome here that costs the operator money at the registry.
        //
        // What is not done is invent the date. RegistrarInterface says outright
        // that a locally added year is how a domain expires while the panel
        // says it is fine, so the row keeps a null expiry and says why.
        $this->rememberDomain($domain, [
            'reference'         =>  $result['reference'] ?? null,
            'registered_at'     =>  $this->now(),
            'expiry_unknown'    =>  $expiry === null,
            'last_error'        =>  null,
        ]);

        (new Domain())->setStatus($domainId, 'active');

        (new Activity())->record(
            'domain.registered',
            'Registered ' . $name . ' at the registrar.'
        );

        return ['success' => true, 'manual' => false, 'message' => 'Registered.'];
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Turn One Paid Domain Line Into a Domain Record
     *
     * @param array $order Order Row
     * @param array $line Order Item Row
     * @return int The domain id to point the line at, or 0
     */
    private function domainFor(array $order, array $line): int
    {
        $this->wasCreated = false;

        $domains = new Domain();
        $name = strtolower(trim((string) ($line['domain'] ?? '')));

        if ($name === '') {
            return 0;
        }

        $clientId = (int) ($order['client_relid'] ?? 0);
        $existing = $domains->byName($name);

        if (is_array($existing)) {
            // Already this client. The marker is what is missing, not the row.
            if ((int) ($existing['client_relid'] ?? 0) === $clientId) {
                return (int) $existing['domain_id'];
            }

            // Somebody else has it. Said out loud, because the customer has
            // paid for a name they cannot have and only a person can decide
            // between a refund, a substitute and a transfer.
            (new Activity())->record(
                'domain.conflict',
                'Order ' . ($order['order_number'] ?? $order['oid'] ?? '?') . ' paid for '
                . $name . ', which is already recorded to another client.'
            );

            return 0;
        }

        $tlds = new Tld();
        $split = $tlds->split($name);

        // The TLD is no longer sold, or never was. The line was priced from
        // a TLD row at checkout, so this means the operator withdrew it in
        // between - the record is still made, because the customer has paid,
        // and it has no registrar to reach so it waits for staff.
        $tld = is_array($split) ? $split['row'] : null;

        $cycle = (string) ($line['billing_cycle'] ?? 'annual');

        if ($tlds->yearsForCycle($cycle) <= 0) {
            $cycle = 'annual';
        }

        try {
            $id = $domains->store([
                'domain'          =>  $name,
                'tld'             =>  is_array($split) ? $split['tld'] : $this->tldOf($name),
                'client_relid'    =>  $clientId,
                'registrar_relid' =>  (int) ($tld['registrar_relid'] ?? 0),
                // What the customer actually bought. `domain_action` is NULL on
                // every line written before Phase 27.3 and on every line that
                // is not a domain at all, and NULL reads as a registration -
                // which is what all of them were.
                'type'            =>  $this->actionOf($line),
                'billing_cycle'   =>  $cycle,
                'currency_relid'  =>  (int) ($order['currency_relid'] ?? 0),
                'amount'          =>  (string) ($line['amount'] ?? '0'),
                'id_protection'   =>  (string) ($tld['id_protection'] ?? 'no'),
                'auto_renew'      =>  'yes',
            ]);
        } catch (Throwable $e) {
            (new Activity())->record(
                'domain.conflict',
                'Could not record ' . $name . ': ' . $e->getMessage()
            );

            return 0;
        }

        if ($id > 0) {
            $this->wasCreated = true;

            // WHO THE NAME IS FOR, built from the client, at the moment the row
            // exists. Without this the registrar call goes out with nobody on
            // it - see Action\DomainContact.
            //
            // A client with no country cannot supply one and this answers false;
            // that is NOT a reason to refuse the domain. The customer has paid,
            // the record belongs in the table, and `register()` will refuse it
            // with something staff can act on. Losing a paid order over a
            // missing postcode would be the worse trade.
            if (!(new DomainContact())->seedFor($id, $clientId)) {
                (new Activity())->record(
                    'domain.contact.missing',
                    'Recorded ' . $name . ' with no registrant: the client has no complete address on file.'
                );
            }
        }

        return $id;
    }

    /**
     * What The Registrar Module Is Told About a Registration
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
            // REAL CONTACTS, not an empty array. This key has been declared by
            // RegistrarInterface::register() since Phase 9 and every call site
            // passed `[]` until Phase 29 - so a driver was asked to register a
            // name with nobody on it, and a registry either refuses that or
            // fills the registrant slot with the API account holder, which makes
            // the OPERATOR the legal owner of the customer's domain.
            'contacts'    =>  (new DomainContact())->forRegistrar((int) ($domain['domain_id'] ?? 0)),
            'years'       =>  max(1, (new Tld())->yearsForCycle((string) ($domain['billing_cycle'] ?? 'annual'))),

            // Empty at registration, on purpose. A registrar defaults a new
            // name to its own nameservers, and inventing a set here would point
            // the domain at hosts this install has never been told about.
            'nameservers' =>  $hosts,
        ];
    }

    /**
     * Read The Expiry Date Out Of a Registrar Answer
     *
     * Parsed rather than trusted: the value comes from somebody else code and
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
     * Record a Failed Attempt
     * @param array $domain Domain Row
     * @param string $message What went wrong
     * @return array{success:bool,manual:bool,message:string}
     */
    private function failed(array $domain, string $message): array
    {
        $this->rememberDomain($domain, [
            'register_attempts' =>  $this->domainAttempts($domain, 'register') + 1,
            'last_error'        =>  $message,
            'last_tried_at'     =>  $this->now(),
        ]);

        (new Activity())->record(
            'domain.registration.failed',
            'Could not register ' . ($domain['domain'] ?? 'a domain') . '. ' . $message
        );

        return ['success' => false, 'manual' => false, 'message' => $message];
    }

    /**
     * Whether An Order Line Is a Registration Or a Transfer
     *
     * Anything that is not the literal string `transfer` is a registration -
     * NULL included, and a value nobody in this codebase writes included. A
     * line whose intent cannot be read must not become a transfer by default:
     * a transfer needs an auth code and a registration somewhere else, and one
     * started by mistake sits waiting for a code that will never come.
     * @param array $line Order Item Row
     * @return string
     */
    private function actionOf(array $line): string
    {
        return (string) ($line['domain_action'] ?? '') === 'transfer' ? 'transfer' : 'register';
    }

    /**
     * The TLD Of a Name, When No TLD Row Claims It
     * @param string $name Domain Name
     * @return string
     */
    private function tldOf(string $name): string
    {
        $at = strpos($name, '.');

        return $at === false ? '' : substr($name, $at);
    }
}
