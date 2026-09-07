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
use LBM\Model\ClientModel;
use LBM\Model\DomainModel;
use LBM\Model\DomainNameserverModel;
use LBM\Service\Money;
use Laika\Service\Vault;
use Throwable;
use LBM\Service\Status;
use RuntimeException;
use Laika\Service\Uid;

/**
 * Domain registrations.
 *
 * Registering, renewing and transferring for real is a registrar module's job
 * and belongs to the provisioning phase. What lives here is the record: what
 * the client owns, when it expires, what it renews at, and which nameservers it
 * points to. Everything an operator needs to answer a question about a domain
 * without logging into the registrar.
 */
class Domain extends Action
{
    /** @var string Status Lookup Table */
    public const STATUSES = 'domain_statuses';

    /** @var string[] Columns a Form May Write */
    public const FIELDS = [
        'domain', 'tld', 'client_relid', 'registrar_relid', 'status_relid',
        'type', 'registration_date', 'expiry_date', 'next_due_date',
        'billing_cycle', 'currency_relid', 'amount', 'id_protection',
        'auto_renew', 'is_locked', 'epp_code',
    ];

    /** @var string[] How a Domain Came To Be Here */
    public const TYPES = ['register', 'transfer', 'existing'];

    /** @var string[] How Often It Renews */
    public const CYCLES = ['annual', 'biennial', 'triennial'];

    public function model(): Model
    {
        return new DomainModel();
    }

    protected function searchable(): array
    {
        return ['domain'];
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
    /*================================= EXTERNAL API =================================*/
    ####################################################################################

    /**
     * One Page Of Domains, With The Client Who Owns Them
     * @param array $where Conditions
     * @param ?string $search Search Term
     * @param ?int $limit Rows Per Page
     * @return array
     */
    public function browseWithClients(array $where = [], ?string $search = null, ?int $limit = null): array
    {
        $domains = new DomainModel();
        $clients = new ClientModel();

        $d = $domains->table;
        $c = $clients->table;

        $qualified = [];

        foreach ($where as $column => $value) {
            $key = str_contains((string) $column, '.') ? (string) $column : "{$d}.{$column}";
            $qualified[$key] = $value;
        }

        $counted = new DomainModel();
        $this->conditions($counted, $qualified);

        $listed = new DomainModel();
        $listed->select([
            "{$d}.*",
            "{$c}.first_name AS client_first_name",
            "{$c}.last_name AS client_last_name",
            "{$c}.company_name AS client_company_name",
        ])->join($c, "{$c}.{$clients->id}", '=', "{$d}.client_relid");

        $this->conditions($listed, $qualified);

        if ($search !== null && trim($search) !== '') {
            $term = '%' . trim($search) . '%';
            $columns = [
                "{$d}.domain"       =>  $term,
                "{$c}.first_name"   =>  $term,
                "{$c}.last_name"    =>  $term,
                "{$c}.company_name" =>  $term,
            ];

            $counted->join($c, "{$c}.{$clients->id}", '=', "{$d}.client_relid")
                ->whereGroup(static function (Model $group) use ($columns): void {
                    $group->where($columns, 'LIKE', 'OR');
                });

            $listed->whereGroup(static function (Model $group) use ($columns): void {
                $group->where($columns, 'LIKE', 'OR');
            });
        }

        return $this->paginate($listed, $counted, $limit, self::DESC);
    }

    /**
     * Domains Owned By One Client
     * @param int $clientId Client ID
     * @param ?int $limit Rows Per Page
     * @return array
     */
    public function browseForClient(int $clientId, ?int $limit = null): array
    {
        return $this->browse(['client_relid' => $clientId], null, $limit, self::DESC);
    }

    /**
     * Find a Domain, Scoped To Its Owner
     * @param int|string $key Domain ID Or Uid
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
     * Create a Domain Record
     *
     * The first writer this table has ever had. Until Phase 27.1 `domains` was
     * a table three screens read and nothing filled, which is why an operator
     * could open Domains on a working install and find it permanently empty.
     *
     * A new domain starts `pending`: the row says the customer has bought the
     * name, and the registrar module has not yet been told. `Registration`
     * moves it to `active` on the registry answer, and a domain whose ending
     * has no registrar module stays pending for whoever registers names by
     * hand - the same three states a service has.
     * @param array $input Submitted Data
     * @return int The new domain id
     * @throws RuntimeException
     */
    public function store(array $input): int
    {
        $data = $this->fields($input);

        $name = (string) ($data['domain'] ?? '');

        if ($name === '') {
            throw new RuntimeException('A domain record needs a name.');
        }

        if ((int) ($data['client_relid'] ?? 0) <= 0) {
            throw new RuntimeException('A domain record needs an owner.');
        }

        // `domain` is UNIQUE. Checked here so the caller gets a sentence it can
        // put in front of somebody, rather than a driver exception out of a
        // cron tick - and because the row that already holds the name is the
        // thing the caller needs to look at next.
        if ($this->byName($name) !== null) {
            throw new RuntimeException('That domain is already recorded here.');
        }

        $data['status_relid'] ??= $this->statusId('pending') ?? 1;
        $data['type'] ??= 'register';

        return $this->create($data);
    }

    /**
     * One Domain By Its Name
     *
     * The `domain` column is UNIQUE across the whole install, so this answers
     * "does anybody already have this" - a different question from "does this
     * client have it", and the checkout path needs the first one.
     * @param string $domain Domain Name
     * @return ?array
     */
    public function byName(string $domain): ?array
    {
        $domain = strtolower(trim($domain));

        if ($domain === '') {
            return null;
        }

        $row = $this->model()->where(['domain' => $domain])->first();

        return is_array($row) ? $row : null;
    }

    /**
     * Move a Domain To a Named Status
     * @param int $domainId Domain ID
     * @param string $status Status Name
     * @return int Affected rows
     */
    public function setStatus(int $domainId, string $status): int
    {
        $id = $this->statusId($status);

        return $id === null ? 0 : $this->update($domainId, ['status_relid' => $id]);
    }

    /**
     * Update a Domain
     * @param int|string $key Domain ID Or Uid
     * @param array $input Submitted Data
     * @return int Affected rows
     */
    public function modify(int|string $key, array $input): int
    {
        return $this->update($key, $this->fields($input));
    }

    /**
     * Store An Auth Code, Encrypted
     *
     * `domains.epp_code` has said "encrypted" in the schema since Phase 0 and
     * nothing ever wrote it - 27.1 noted that and left it. This is the writer,
     * and it is the same Vault the server and service passwords go through.
     *
     * An auth code is a bearer credential: whoever holds it can move the name
     * away. It is written once, never rendered back to anybody, and cleared
     * the moment the transfer completes - it is spent by then, and a spent
     * credential kept is a credential that can leak.
     * @param int $domainId Domain ID
     * @param ?string $code The Code, Or Null To Clear It
     * @return int Affected rows
     */
    public function setEppCode(int $domainId, ?string $code): int
    {
        $code = $code === null ? null : trim($code);

        return $this->update($domainId, [
            'epp_code' =>  $code === null || $code === '' ? null : Vault::encrypt($code),
        ]);
    }

    /**
     * Read An Auth Code Back
     *
     * Null when there is none AND when there is one that will not decrypt,
     * which happens if the app key changed. ClientService::credential() makes
     * the same choice for the same reason: a credential that cannot be read is
     * not a reason to stop a page rendering, and here it is not a reason to
     * stop a cron tick either - the transfer simply waits for a new code.
     * @param array $domain Domain Row
     * @return ?string
     */
    public function eppCode(array $domain): ?string
    {
        $stored = $domain['epp_code'] ?? null;

        if (!is_string($stored) || $stored === '') {
            return null;
        }

        try {
            $plain = Vault::decrypt($stored);
        } catch (Throwable) {
            return null;
        }

        return is_string($plain) && $plain !== '' ? $plain : null;
    }

    /**
     * The Nameservers a Domain Points To
     * @param int $domainId Domain ID
     * @return array
     */
    public function nameservers(int $domainId): array
    {
        $model = new DomainNameserverModel();

        return $model->where(['domain_relid' => $domainId])
            ->order($model->id, self::ASC)
            ->get();
    }

    /**
     * Replace a Domain's Nameservers
     *
     * Replace rather than reconcile: nameservers are a short ordered list the
     * form posts whole, and their order is meaningful - ns1 is not ns2. The
     * table has no sort column, so order is insertion order and rewriting the
     * set is the only way to preserve it. Matching submitted rows against
     * stored ones would only be a way to get that subtly wrong.
     * @param int $domainId Domain ID
     * @param string[] $hosts Nameserver Hostnames
     * @return int How many were stored
     */
    public function setNameservers(int $domainId, array $hosts): int
    {
        $clean = [];

        foreach ($hosts as $host) {
            $host = strtolower(trim((string) $host));

            if ($host !== '' && !in_array($host, $clean, true)) {
                $clean[] = $host;
            }
        }

        (new DomainNameserverModel())->transaction(
            function (DomainNameserverModel $m) use ($domainId, $clean): void {
                $m->where(['domain_relid' => $domainId])->delete();

                foreach ($clean as $host) {
                    $m->insert([
                        $m->uid         =>  Uid::make(),
                        'domain_relid'  =>  $domainId,
                        'hostname'      =>  $host,
                        'ns_created_at' =>  $this->now(),
                    ]);
                }
            }
        );

        return count($clean);
    }

    /**
     * Whether a Domain Has Passed Its Expiry Date
     * @param array $domain Domain Row
     * @return bool
     */
    public function isExpired(array $domain): bool
    {
        $expiry = $domain['expiry_date'] ?? null;

        if ($expiry === null || $expiry === '') {
            return false;
        }

        return strtotime((string) $expiry) < time();
    }

    /**
     * How Many Days Until a Domain Expires
     * @param array $domain Domain Row
     * @return ?int Null when no expiry is recorded. Negative once past
     */
    public function daysToExpiry(array $domain): ?int
    {
        $expiry = $domain['expiry_date'] ?? null;

        if ($expiry === null || $expiry === '') {
            return null;
        }

        $days = (strtotime(date('Y-m-d', (int) strtotime((string) $expiry))) - strtotime(date('Y-m-d'))) / 86400;

        return (int) round($days);
    }

    /**
     * Domains Expiring Inside a Window
     * @param int $days How Far Ahead
     * @return array
     */
    public function expiringWithin(int $days = 30): array
    {
        $model = $this->model();

        return $model->notNull('expiry_date')
            ->between('expiry_date', date('Y-m-d H:i:s'), date('Y-m-d H:i:s', strtotime("+{$days} days")))
            ->order('expiry_date', self::ASC)
            ->get();
    }

    /**
     * The Status Lookup Table This Resource Uses
     *
     * A method rather than the STATUSES constant, because a relay facade
     * forwards method calls and not constants - so a controller reaching this
     * through LBM\Service\* has no way to read the constant directly.
     * @return string
     */
    public function statusTable(): string
    {
        return self::STATUSES;
    }

    /**
     * The Id Of One Named Status
     * @param string $name Status Name. Example: 'active'
     * @return ?int Null when no status of that name exists
     */
    public function statusId(string $name): ?int
    {
        return Status::idOf(self::STATUSES, $name);
    }

    /**
     * The Status Choices a Form Offers
     * @return array
     */
    public function statuses(): array
    {
        return Status::all(self::STATUSES);
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * How a Domain Came To Be Here
     *
     * A method rather than the constant, because a relay facade forwards method
     * calls and not constants.
     * @return string[]
     */
    public function types(): array
    {
        return self::TYPES;
    }

    /**
     * How Often a Domain Renews
     *
     * A method rather than the constant, because a relay facade forwards method
     * calls and not constants.
     * @return string[]
     */
    public function cycles(): array
    {
        return self::CYCLES;
    }

    /**
     * Reduce Submitted Input To Writable Columns
     * @param array $input Submitted Data
     * @return array
     */
    private function fields(array $input): array
    {
        $data = $this->nullable(
            $this->only($input, self::FIELDS),
            ['registration_date', 'expiry_date', 'next_due_date', 'epp_code']
        );

        foreach (['id_protection', 'auto_renew', 'is_locked'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $data[$flag] = $this->flag($data[$flag]);
            }
        }

        if (isset($data['type']) && !in_array($data['type'], self::TYPES, true)) {
            unset($data['type']);
        }

        if (isset($data['billing_cycle']) && !in_array($data['billing_cycle'], self::CYCLES, true)) {
            unset($data['billing_cycle']);
        }

        if (array_key_exists('domain', $data)) {
            $data['domain'] = strtolower((string) $data['domain']);
        }

        if (array_key_exists('amount', $data)) {
            $data['amount'] = Money::round((string) $data['amount']);
        }

        return $data;
    }
}
