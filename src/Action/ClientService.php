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
use RuntimeException;
use Laika\Model\Model;
use Laika\Service\Vault;
use LBM\Model\BillingCycleModel;
use LBM\Model\ClientServiceAddonModel;
use LBM\Model\ClientServiceModel;
use LBM\Model\ProductModel;
use LBM\Service\Status;

/**
 * The things a client actually owns - a product, provisioned, with a renewal
 * date and a price.
 *
 * An order is what somebody asked for; a service is what they have. The two are
 * deliberately separate records: an order is a moment, and a service outlives
 * it - it renews, gets suspended, changes price, and is still the same service.
 *
 * Two things here are not what they first look like.
 *
 * `password` is the credential for whatever was provisioned - a control panel
 * login, usually - and is stored encrypted. It is never part of a listing, and
 * reading it is a deliberate call to credential(), so a screen cannot print one
 * by accident by looping over the columns it was handed.
 *
 * requestCancellation() does not cancel anything. See its docblock.
 */
class ClientService extends Action
{
    /** @var string Status Lookup Table */
    public const STATUSES = 'client_service_statuses';

    /** @var string[] Columns a Form May Write */
    public const FIELDS = [
        'client_relid', 'product_relid', 'server_relid', 'domain', 'username',
        'billing_cycle_relid', 'currency_relid', 'amount', 'next_due_date',
        'registration_date', 'termination_date', 'status_relid',
        'suspension_reason',
    ];

    /** @var string[] Columns That Store Null Rather Than An Empty String */
    private const NULLABLE = [
        'server_relid', 'domain', 'username', 'next_due_date',
        'registration_date', 'termination_date', 'suspension_reason',
    ];

    /** @var string[] Statuses That Mean The Service Is Over */
    private const FINISHED = ['terminated', 'cancelled'];

    /** @var string[] Columns Holding An Integer Foreign Key */
    private const NUMERIC = [
        'client_relid', 'product_relid', 'server_relid',
        'billing_cycle_relid', 'currency_relid', 'status_relid',
    ];

    public function model(): Model
    {
        return new ClientServiceModel();
    }

    protected function searchable(): array
    {
        return ['domain', 'username'];
    }

    protected function createdColumn(): ?string
    {
        return 'created_at';
    }

    protected function updatedColumn(): ?string
    {
        return 'updated_at';
    }

    ####################################################################################
    /*=================================== READING ====================================*/
    ####################################################################################

    /**
     * One Page Of a Client's Services, With The Product Each One Is
     *
     * The product name is joined rather than looked up per row: a client with
     * twenty services would otherwise cost twenty-one queries to list.
     * @param int $clientId Client ID
     * @param array $where Extra Conditions
     * @param ?int $limit Rows Per Page
     * @return array
     */
    public function browseForClient(int $clientId, array $where = [], ?int $limit = null): array
    {
        $services = new ClientServiceModel();
        $products = new ProductModel();

        $s = $services->table;
        $p = $products->table;

        $qualified = ["{$s}.client_relid" => $clientId];

        foreach ($where as $column => $value) {
            $key = str_contains((string) $column, '.') ? (string) $column : "{$s}.{$column}";
            $qualified[$key] = $value;
        }

        $counted = new ClientServiceModel();
        $this->conditions($counted, $qualified);

        // Only the listed model carries the join. products is one row per
        // service so it could not inflate this count, but keeping the counted
        // model join-free is the rule everywhere else, and the listing that
        // quietly did it differently is the one that breaks later.
        $listed = new ClientServiceModel();
        $listed->select([
            "{$s}.*",
            "{$p}.product_name AS product_name",
            "{$p}.product_slug AS product_slug",
        ])->join($p, "{$p}.{$products->id}", '=', "{$s}.product_relid");

        $this->conditions($listed, $qualified);

        return $this->paginate($listed, $counted, $limit, self::DESC);
    }

    /**
     * Find a Service, Scoped To Its Owner
     *
     * The client id is part of the lookup rather than a check afterwards, so
     * somebody else's service uid is not found rather than found and refused.
     * @param int|string $key Service ID Or Uid
     * @param int $clientId Client ID
     * @return ?array
     */
    public function forClientKey(int|string $key, int $clientId): ?array
    {
        $model = $this->model();

        $row = $this->key($model, $key)->where(['client_relid' => $clientId])->first();

        return is_array($row) ? $row : null;
    }

    /**
     * Every Service On One Client, Unpaginated
     *
     * For the small bounded reads - a select box of services to attach a ticket
     * to, or the dashboard panel that says what somebody has.
     * @param int $clientId Client ID
     * @param array $where Extra Conditions
     * @return array
     */
    public function forClient(int $clientId, array $where = []): array
    {
        return $this->all($where + ['client_relid' => $clientId], self::DESC);
    }

    /**
     * How Many Services Are Live
     * @param ?int $clientId Client ID. Null counts every client's
     * @return int
     */
    public function activeCount(?int $clientId = null): int
    {
        $active = Status::idOf(self::STATUSES, 'active');

        if ($active === null) {
            return 0;
        }

        $where = ['status_relid' => $active];

        if ($clientId !== null) {
            $where['client_relid'] = $clientId;
        }

        return $this->count($where);
    }

    /**
     * Services Renewing Within a Number Of Days
     *
     * Anything terminated or cancelled is excluded - a service that ended does
     * not renew, whatever its next_due_date still says.
     * @param int $days How Far Ahead
     * @param ?int $clientId Client ID. Null covers every client
     * @return array
     */
    public function dueWithin(int $days = 30, ?int $clientId = null): array
    {
        $model = $this->model();

        if ($clientId !== null) {
            $model->where(['client_relid' => $clientId]);
        }

        $finished = $this->finishedStatusIds();

        if ($finished !== []) {
            $model->whereNotIn('status_relid', $finished);
        }

        return $model->notNull('next_due_date')
            ->between(
                'next_due_date',
                date('Y-m-d H:i:s'),
                date('Y-m-d H:i:s', time() + ($days * 86400))
            )
            ->order('next_due_date', self::ASC)
            ->get();
    }

    /**
     * The Addons Bought Alongside a Service
     * @param int $serviceId Service ID
     * @return array
     */
    public function addons(int $serviceId): array
    {
        $model = new ClientServiceAddonModel();

        return $model->where(['service_relid' => $serviceId])
            ->order($model->id, self::ASC)
            ->get();
    }

    /**
     * The Product a Service Is An Instance Of
     * @param array $service Service Row
     * @return ?array
     */
    public function product(array $service): ?array
    {
        $id = (int) ($service['product_relid'] ?? 0);

        return $id > 0 ? (new Product())->find($id) : null;
    }

    /**
     * A Service's Provisioning Credential, Decrypted
     *
     * Deliberately not part of any row this class returns. Reading it is an
     * explicit call, so a screen cannot print somebody's control-panel password
     * by looping over the columns it was handed.
     * @param array $service Service Row
     * @return ?string
     */
    public function credential(array $service): ?string
    {
        $stored = $service['password'] ?? null;

        if (!is_string($stored) || $stored === '') {
            return null;
        }

        // A credential that will not decrypt means the app key changed, which
        // is not a reason to stop the page rendering.
        try {
            $plain = Vault::decrypt($stored);
        } catch (Throwable) {
            return null;
        }

        return is_string($plain) && $plain !== '' ? $plain : null;
    }

    /**
     * Whether a Service Is Live
     * @param array $service Service Row
     * @return bool
     */
    public function isActive(array $service): bool
    {
        $active = Status::idOf(self::STATUSES, 'active');

        return $active !== null && (int) ($service['status_relid'] ?? 0) === $active;
    }

    /**
     * Whether a Service Has Ended
     *
     * Terminated or cancelled. A suspended service has not ended - it is still
     * billed, and it comes back the moment the invoice is paid.
     * @param array $service Service Row
     * @return bool
     */
    public function isFinished(array $service): bool
    {
        return in_array((int) ($service['status_relid'] ?? 0), $this->finishedStatusIds(), true);
    }

    /**
     * The Status Ids That Mean The Service Is Over
     * @return int[]
     */
    public function finishedStatusIds(): array
    {
        $ids = [];

        foreach (self::FINISHED as $name) {
            $id = Status::idOf(self::STATUSES, $name);

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    ####################################################################################
    /*=================================== WRITING ====================================*/
    ####################################################################################

    /**
     * Create a Service
     * @param array $input Submitted Data
     * @param ?string $credential Provisioning Password
     * @return int New Service ID
     */
    public function store(array $input, ?string $credential = null): int
    {
        $data = $this->fields($input);

        $data['status_relid'] = (int) ($data['status_relid'] ?? 0)
            ?: (Status::idOf(self::STATUSES, 'pending') ?? 1);

        if ($credential !== null && $credential !== '') {
            $data['password'] = Vault::encrypt($credential);
        }

        return $this->create($data);
    }

    /**
     * Update a Service
     * @param int|string $key Service ID Or Uid
     * @param array $input Submitted Data
     * @return int Affected rows
     */
    public function modify(int|string $key, array $input): int
    {
        return $this->update($key, $this->fields($input));
    }

    /**
     * Set a Service's Provisioning Credential
     *
     * Blank means "leave it alone", not "clear it" - an edit form that shows no
     * password and posts an empty one must not wipe a working credential.
     * @param int|string $key Service ID Or Uid
     * @param ?string $credential Plain Credential
     * @return int Affected rows
     */
    public function setCredential(int|string $key, ?string $credential): int
    {
        if ($credential === null || $credential === '') {
            return 0;
        }

        return $this->update($key, ['password' => Vault::encrypt($credential)]);
    }

    /**
     * Move a Service To Another Status
     * @param int|string $key Service ID Or Uid
     * @param string $name Status Name
     * @param ?string $reason Suspension Reason
     * @return int Affected rows
     * @throws RuntimeException
     */
    public function setStatus(int|string $key, string $name, ?string $reason = null): int
    {
        $status = Status::idOf(self::STATUSES, $name);

        if ($status === null) {
            throw new RuntimeException('There is no service status called ' . $name . '.');
        }

        // The reason is only meaningful while suspended. Leaving a stale one
        // behind would have a live service still explaining why it was off.
        $data = [
            'status_relid'      =>  $status,
            'suspension_reason' =>  $name === 'suspended' ? $reason : null,
        ];

        if (in_array($name, self::FINISHED, true)) {
            $data['termination_date'] = $this->now();
        }

        return $this->update($key, $data);
    }

    /**
     * Ask For a Service To Be Cancelled
     *
     * This does not cancel anything, and that is deliberate. Cancelling is the
     * operator's decision: there may be a notice period, an unpaid invoice, or
     * data to hand back first - and a client who could set the status himself
     * would stop his own billing the moment he clicked. What a client can do is
     * ask, in a way staff will actually see.
     *
     * So the request becomes a support ticket against the service, in whichever
     * department the operator made default. It lands in the queue that gets
     * read, it carries a status of its own, and the client gets a thread to
     * follow rather than a message that disappeared.
     * @param array $service Service Row
     * @param int $clientId Client ID
     * @param string $reason Why They Are Cancelling
     * @param string $when immediately or end_of_term
     * @return int New Ticket ID
     * @throws RuntimeException
     */
    public function requestCancellation(
        array $service,
        int $clientId,
        string $reason,
        string $when = 'end_of_term'
    ): int {
        if ($this->isFinished($service)) {
            throw new RuntimeException('That service has already ended.');
        }

        $support = new Support();
        $department = $support->defaultDepartmentId();

        if ($department === null) {
            throw new RuntimeException(
                'Cancellation requests cannot be raised: no support department has been set up.'
            );
        }

        $label = $when === 'immediately' ? 'immediately' : 'at the end of the current term';
        $name = $this->label($service);

        $reason = trim($reason);

        $message = 'Please cancel ' . $name . ' ' . $label . '.' . "\n\n"
            . ($reason === '' ? 'No reason was given.' : $reason);

        return $support->openByClient([
            'client_relid'     =>  $clientId,
            'department_relid' =>  $department,
            'service_relid'    =>  (int) $service['service_id'],
            'subject'          =>  'Cancellation request: ' . $name,
        ], $message, $clientId);
    }

    ####################################################################################
    /*=================================== LOOKUPS ====================================*/
    ####################################################################################

    /**
     * What To Call a Service On Screen
     *
     * The domain when it has one, because that is what the client recognises;
     * the product name otherwise. Never the primary key on its own - a person
     * does not know their service by its row id.
     * @param array $service Service Row
     * @return string
     */
    public function label(array $service): string
    {
        $domain = trim((string) ($service['domain'] ?? ''));

        if ($domain !== '') {
            return $domain;
        }

        $product = trim((string) ($service['product_name'] ?? ''));

        if ($product === '') {
            $row = $this->product($service);
            $product = trim((string) ($row['product_name'] ?? ''));
        }

        return $product !== '' ? $product : ('Service #' . (int) ($service['service_id'] ?? 0));
    }

    /**
     * The Status Lookup Table
     *
     * A method rather than the constant, because a relay facade forwards method
     * calls and not constants.
     * @return string
     */
    public function statusTable(): string
    {
        return self::STATUSES;
    }

    /**
     * A Status Id By Name
     * @param string $name Status Name
     * @return ?int
     */
    public function statusId(string $name): ?int
    {
        return Status::idOf(self::STATUSES, $name);
    }

    /**
     * Every Service Status
     * @return array
     */
    public function statuses(): array
    {
        return Status::all(self::STATUSES);
    }

    /**
     * Every Billing Cycle
     * @return array
     */
    public function cycles(): array
    {
        $model = new BillingCycleModel();

        return $model->order($model->id, self::ASC)->get();
    }

    /**
     * Billing Cycle Names, Keyed By Id
     * @return array<int,string>
     */
    public function cycleNames(): array
    {
        $names = [];

        foreach ($this->cycles() as $row) {
            $names[(int) $row['billing_cycle_id']] = (string) $row['billing_cycle_name'];
        }

        return $names;
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Reduce Submitted Input To Writable Columns
     * @param array $input Submitted Data
     * @return array
     */
    private function fields(array $input): array
    {
        $data = $this->nullable($this->only($input, self::FIELDS), self::NULLABLE);

        foreach (self::NUMERIC as $column) {
            if (array_key_exists($column, $data) && $data[$column] !== null) {
                $data[$column] = (int) $data[$column];
            }
        }

        return $data;
    }
}
