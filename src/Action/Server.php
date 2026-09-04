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

use RuntimeException;
use Laika\Model\Model;
use Laika\Service\Vault;
use LBM\Model\ClientServiceModel;
use LBM\Model\ServerModel;
use LBM\Model\ServerGroupModel;
use LBM\Service\Status;
use Laika\Service\Uid;

/**
 * Provisioning servers and the groups they are allocated from.
 *
 * Credentials are encrypted at rest with Laika\Service\Vault rather than stored
 * in the clear: these are root-equivalent logins to real machines, and a
 * database backup that leaks them is a very different incident from one that
 * leaks a client list. They are decrypted only when something is about to
 * connect, and never handed to a template - the edit form shows a blank field
 * and leaves the stored value alone unless a new one is typed.
 *
 * Actually talking to cPanel or Plesk is a provisioning module's job. test()
 * here checks only that the host answers on the port, which is the question an
 * operator staring at a failed order actually needs answered first: is the box
 * up, or is it the credentials?
 */
class Server extends Action
{
    /** @var string Status Lookup Table */
    public const STATUSES = 'server_statuses';

    /** @var string[] Columns a Form May Write */
    public const FIELDS = [
        'group_relid', 'name', 'hostname', 'ip_address', 'module_name', 'port',
        'use_ssl', 'nameserver1', 'nameserver2', 'max_accounts', 'status_relid',
    ];

    /** @var string[] Columns Holding a Secret */
    public const SECRETS = ['username', 'password', 'access_key'];

    /** @var int Seconds To Wait For a Connection */
    public const TIMEOUT = 5;

    /** @var string[] How a Group Picks Its Next Server */
    public const FILL_TYPES = ['sequentially', 'by_server', 'least_full'];

    public function model(): Model
    {
        return new ServerModel();
    }

    protected function searchable(): array
    {
        return ['name', 'hostname', 'ip_address'];
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
    /*=================================== SERVERS ====================================*/
    ####################################################################################

    /**
     * Create a Server
     * @param array $input Submitted Data
     * @return int New Server ID
     */
    public function store(array $input): int
    {
        $data = $this->fields($input);

        $data['status_relid'] = (int) ($data['status_relid'] ?? 0)
            ?: (Status::idOf(self::STATUSES, 'active') ?? 1);

        // NOT NULL with no default, and nothing populates it yet.
        $data['ip_addresses'] = serialize([]);

        foreach (self::SECRETS as $secret) {
            $value = trim((string) ($input[$secret] ?? ''));
            $data[$secret] = $value === '' ? null : $this->encrypt($value);
        }

        return $this->create($data);
    }

    /**
     * Update a Server
     *
     * A blank credential field means "leave it alone", not "clear it". An edit
     * form cannot show the stored password, so treating an empty box as a
     * deliberate blanking would wipe the credentials of every server anybody
     * ever renamed.
     * @param int|string $key Server ID Or Uid
     * @param array $input Submitted Data
     * @return int Affected rows
     */
    public function modify(int|string $key, array $input): int
    {
        $data = $this->fields($input);

        foreach (self::SECRETS as $secret) {
            $value = trim((string) ($input[$secret] ?? ''));

            if ($value !== '') {
                $data[$secret] = $this->encrypt($value);
            }
        }

        return $this->update($key, $data);
    }

    /**
     * Read a Server Credential Back
     *
     * The only way out of the encrypted columns, and deliberately a method
     * rather than something a listing returns - a screen that never asks cannot
     * accidentally print one.
     * @param array $server Server Row
     * @param string $column One of SECRETS
     * @return ?string
     */
    public function credential(array $server, string $column): ?string
    {
        if (!in_array($column, self::SECRETS, true)) {
            return null;
        }

        $stored = $server[$column] ?? null;

        if (!is_string($stored) || $stored === '') {
            return null;
        }

        return $this->decrypt($stored);
    }

    /**
     * Check The Server Answers
     *
     * A TCP connect, not a login: it distinguishes "the machine is unreachable"
     * from "the machine is up but rejected us", which are different problems
     * with different fixes. A real credential check belongs to the provisioning
     * module for that server type.
     * @param int|string $key Server ID Or Uid
     * @return array{ok:bool,message:string,ms:int}
     */
    public function test(int|string $key): array
    {
        $server = $this->find($key);

        if ($server === null) {
            return ['ok' => false, 'message' => 'That server no longer exists.', 'ms' => 0];
        }

        $host = trim((string) ($server['hostname'] ?? ''));
        $host = $host !== '' ? $host : trim((string) ($server['ip_address'] ?? ''));
        $port = (int) ($server['port'] ?? 0) ?: 443;

        if ($host === '') {
            return ['ok' => false, 'message' => 'This server has no hostname or IP address.', 'ms' => 0];
        }

        $started = microtime(true);

        // Suppressed because a refused connection is a normal answer here, not
        // an exceptional one - $error carries what happened.
        $socket = @fsockopen($host, $port, $errno, $error, self::TIMEOUT);

        $ms = (int) round((microtime(true) - $started) * 1000);

        if ($socket === false) {
            return [
                'ok'      =>  false,
                'message' =>  "Could not reach {$host}:{$port} - " . ($error !== '' ? $error : "error {$errno}"),
                'ms'      =>  $ms,
            ];
        }

        fclose($socket);

        return ['ok' => true, 'message' => "Reached {$host}:{$port} in {$ms}ms.", 'ms' => $ms];
    }

    /**
     * Delete a Server
     *
     * Refuses while services are still allocated to it: those services would
     * lose the record of where they actually live, which is not recoverable
     * from anywhere else.
     * @param int|string $key Server ID Or Uid
     * @return int Affected rows
     * @throws RuntimeException
     */
    public function remove(int|string $key): int
    {
        $server = $this->find($key);

        if ($server === null) {
            return 0;
        }

        $id = (int) $server['server_id'];
        $services = (new ClientServiceModel())->where(['server_relid' => $id])->count();

        if ($services > 0) {
            throw new RuntimeException(
                "{$services} service(s) are hosted on this server. Move them before deleting it."
            );
        }

        return $this->delete($id);
    }

    /**
     * Recount One Server's Live Accounts
     *
     * `servers.active_accounts` has existed since Phase 0, is read by
     * `Provision::pickServer()` to refuse a full server and by `usage()` to draw
     * the capacity bar - and until Phase 24 **nothing had ever written it**. It
     * sat at 0 on every install for ever, so a max_accounts limit refused
     * nothing, every capacity bar read 0%, and provisioning piled every account
     * onto whichever server sorted first.
     *
     * COUNTED, not incremented. A counter nudged up on create and down on
     * terminate drifts the first time a row is deleted straight out of the
     * database, a job dies halfway, or - as here - a release ships where the
     * increment never existed. A COUNT over an indexed column is cheap and
     * cannot be wrong, and it repairs an existing install the first time
     * anything happens on that server rather than needing a migration.
     *
     * Finished services do not count. A terminated account is not on the server
     * any more; a suspended one still is.
     * @param int $serverId Server ID
     * @return int The count it wrote
     */
    public function recount(int $serverId): int
    {
        if ($serverId <= 0) {
            return 0;
        }

        $services = new ClientServiceModel();
        $services->where(['server_relid' => $serverId]);

        $finished = (new ClientService())->finishedStatusIds();

        if ($finished !== []) {
            $services->whereNotIn('status_relid', $finished);
        }

        $count = (int) $services->count();

        $model = new ServerModel();
        $model->where([$model->id => $serverId])->update(['active_accounts' => $count]);

        return $count;
    }

    /**
     * Recount Every Server
     *
     * Run daily rather than only on provisioning events, so an install that has
     * been carrying zeroes since Phase 0 becomes correct on the first cron tick
     * after an update instead of waiting for somebody to buy something.
     * @return int How many servers were counted
     */
    public function recountAll(): int
    {
        $model = new ServerModel();
        $done = 0;

        foreach ($model->order($model->id, self::ASC)->get() as $row) {
            $this->recount((int) $row['server_id']);
            $done++;
        }

        return $done;
    }

    /**
     * How Full a Server Is, As a Percentage
     * @param array $server Server Row
     * @return ?int Null when no account limit is set
     */
    public function usage(array $server): ?int
    {
        $max = (int) ($server['max_accounts'] ?? 0);

        if ($max <= 0) {
            return null;
        }

        $active = (int) ($server['active_accounts'] ?? 0);

        return (int) min(100, round($active / $max * 100));
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
    /*==================================== GROUPS ====================================*/
    ####################################################################################

    /**
     * Every Server Group
     * @return array
     */
    public function groups(): array
    {
        $model = new ServerGroupModel();

        return $model->order('group_name', self::ASC)->get();
    }

    /**
     * Find One Group
     * @param int|string $key Group ID Or Uid
     * @return ?array
     */
    public function group(int|string $key): ?array
    {
        $model = new ServerGroupModel();
        $row = $this->key($model, $key)->first();

        return is_array($row) ? $row : null;
    }

    /**
     * Create Or Update a Group
     * @param array $input Submitted Data
     * @param int|string|null $key Group ID Or Uid. Null creates
     * @return int The group ID
     */
    public function saveGroup(array $input, int|string|null $key = null): int
    {
        $model = new ServerGroupModel();

        $fill = (string) ($input['fill_type'] ?? 'sequentially');

        $data = [
            'group_name' =>  trim((string) ($input['group_name'] ?? '')),
            'fill_type'  =>  in_array($fill, self::FILL_TYPES, true) ? $fill : 'sequentially',
        ];

        if ($key !== null && $key !== '' && $key !== 0) {
            $group = $this->group($key);

            if ($group !== null) {
                $id = (int) $group['group_id'];

                $model->where([$model->id => $id])->update($data);

                return $id;
            }
        }

        $data[$model->uid] = Uid::make();
        $data['group_created_at'] = $this->now();

        return (int) $model->insert($data);
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * How a Group Picks Its Next Server
     *
     * A method rather than the constant, because a relay facade forwards method
     * calls and not constants.
     * @return string[]
     */
    public function fillTypes(): array
    {
        return self::FILL_TYPES;
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
            ['group_relid', 'nameserver1', 'nameserver2', 'max_accounts']
        );

        if (array_key_exists('use_ssl', $data)) {
            $data['use_ssl'] = $this->flag($data['use_ssl']);
        }

        if (array_key_exists('port', $data)) {
            $data['port'] = (int) $data['port'] ?: 2083;
        }

        if (array_key_exists('hostname', $data)) {
            $data['hostname'] = strtolower((string) $data['hostname']);
        }

        return $data;
    }

    /**
     * Encrypt a Credential
     * @param string $value Plain Value
     * @return string
     */
    private function encrypt(string $value): string
    {
        return Vault::encrypt($value);
    }

    /**
     * Decrypt a Credential
     *
     * A value that will not decrypt reads as absent rather than throwing: the
     * usual cause is the application key having been rotated, and a Servers
     * screen that fatals is a worse outcome than one that shows a blank field
     * and lets somebody re-enter the password.
     * @param string $value Stored Value
     * @return ?string
     */
    private function decrypt(string $value): ?string
    {
        try {
            $plain = Vault::decrypt($value);
        } catch (\Throwable) {
            return null;
        }

        return is_string($plain) && $plain !== '' ? $plain : null;
    }
}
