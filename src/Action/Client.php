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
use LBM\Model\ClientContactModel;
use LBM\Model\ClientNoteModel;
use LBM\Service\Money;
use LBM\Service\Status;
use LBM\Support\PasswordValidator;

/**
 * Clients - the people who are billed.
 *
 * A client's credentials live in the shared `passwords` table under
 * rel_type = 'client', not on the clients row, so staff, clients and client
 * contacts all authenticate through one code path.
 */
class Client extends Action
{
    /** @var string Status Lookup Table */
    public const STATUSES = 'client_statuses';

    /** @var string[] Columns a Form May Write */
    public const FIELDS = [
        'company_name', 'first_name', 'middle_name', 'last_name', 'email',
        'username', 'phone_cc', 'phone_number', 'street', 'city', 'state',
        'postcode', 'country_relid', 'currency_relid', 'status_relid',
        'tax_exempt', 'tax_id', 'is_restricted',
    ];

    /** @var string[] Columns That Store Null Rather Than An Empty String */
    private const NULLABLE = [
        'company_name', 'middle_name', 'username', 'phone_cc', 'phone_number',
        'street', 'city', 'state', 'postcode', 'country_relid',
        'currency_relid', 'tax_id',
    ];

    public function model(): Model
    {
        return new ClientModel();
    }

    protected function searchable(): array
    {
        return ['company_name', 'first_name', 'last_name', 'email', 'username'];
    }

    protected function createdColumn(): ?string
    {
        return 'client_created_at';
    }

    protected function updatedColumn(): ?string
    {
        return 'client_updated_at';
    }

    ####################################################################################
    /*================================= EXTERNAL API =================================*/
    ####################################################################################

    /**
     * Find a Client By Their Login Identifier
     *
     * Username or email, in one query. Both columns are indexed, and a client
     * with no username signs in with their email.
     * @param string $identifier Username Or Email
     * @return ?array
     */
    public function findByLogin(string $identifier): ?array
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            return null;
        }

        $row = $this->model()
            ->where(['username' => $identifier, 'email' => $identifier], '=', 'OR')
            ->first();

        return is_array($row) ? $row : null;
    }

    /**
     * Whether An Email Address Is Already Taken
     * @param string $email Email Address
     * @param ?int $ignore Client ID To Exclude, When Editing
     * @return bool
     */
    public function emailTaken(string $email, ?int $ignore = null): bool
    {
        $model = $this->model();
        $model->where(['email' => trim($email)]);

        if ($ignore !== null) {
            $model->whereNot([$model->id => $ignore]);
        }

        return $model->count() > 0;
    }

    /**
     * Whether a Username Is Already Taken
     * @param string $username Username
     * @param ?int $ignore Client ID To Exclude, When Editing
     * @return bool
     */
    public function usernameTaken(string $username, ?int $ignore = null): bool
    {
        $username = trim($username);

        // No username is a legitimate state - the client signs in by email -
        // and "nobody has set one" must not read as a collision.
        if ($username === '') {
            return false;
        }

        $model = $this->model();
        $model->where(['username' => $username]);

        if ($ignore !== null) {
            $model->whereNot([$model->id => $ignore]);
        }

        return $model->count() > 0;
    }

    /**
     * Create a Client, With Their Password
     *
     * Both writes are one transaction: a client row with no credential could
     * not sign in, and a credential with no client would be orphaned.
     * @param array $input Submitted Data
     * @param ?string $password Plain Password. Null leaves the account without one
     * @return int New Client ID
     */
    public function store(array $input, ?string $password = null): int
    {
        $data = $this->fields($input);
        $data['status_relid'] = (int) ($data['status_relid'] ?? 0)
            ?: (Status::idOf(self::STATUSES, 'active') ?? 1);

        $id = 0;

        $this->model()->transaction(function (ClientModel $m) use ($data, $password, &$id): void {
            $id = (int) $m->insert($this->stamp($data, true));

            if ($password !== null && $password !== '') {
                (new PasswordValidator())->put($id, PasswordValidator::CLIENT, $password);
            }
        });

        return $id;
    }

    /**
     * Update a Client
     * @param int|string $key Client ID Or Uid
     * @param array $input Submitted Data
     * @return int Affected rows
     */
    public function modify(int|string $key, array $input): int
    {
        return $this->update($key, $this->fields($input));
    }

    /**
     * Set a Client's Password
     * @param int $clientId Client ID
     * @param string $password Plain Password
     * @return void
     */
    public function setPassword(int $clientId, string $password): void
    {
        (new PasswordValidator())->put($clientId, PasswordValidator::CLIENT, $password);
    }

    /**
     * Delete a Client And Everything Hanging Off Them
     *
     * Contacts and notes are meaningless without their client, so they go with
     * it. Invoices, orders and transactions are deliberately left alone - they
     * are financial records, and a billing system that loses them when somebody
     * tidies up a client list is not one you can audit.
     * @param int|string $key Client ID Or Uid
     * @return int Affected rows
     */
    public function remove(int|string $key): int
    {
        $client = $this->find($key);

        if ($client === null) {
            return 0;
        }

        $id = (int) $client['cid'];
        $affected = 0;

        $this->model()->transaction(function (ClientModel $m) use ($id, &$affected): void {
            (new ClientContactModel())->where(['client_relid' => $id])->delete();
            (new ClientNoteModel())->where(['client_relid' => $id])->delete();

            $affected = $m->where([$m->id => $id])->delete();
        });

        return $affected;
    }

    /**
     * Adjust a Client's Credit Balance
     *
     * Read, add, write - not increment() - because the balance is a decimal and
     * the arithmetic goes through bcmath. Adding a float to a money column is
     * how a ledger ends up 0.01 out and nobody can say when.
     * @param int $clientId Client ID
     * @param int|float|string $amount Amount. Negative to deduct
     * @return string The new balance
     */
    public function adjustCredit(int $clientId, int|float|string $amount): string
    {
        $balance = '0';

        $this->model()->transaction(function (ClientModel $m) use ($clientId, $amount, &$balance): void {
            $row = $m->select('credit_balance')->where([$m->id => $clientId])->first();

            $current = (string) ($row['credit_balance'] ?? '0');
            $balance = Money::add($current, (string) $amount);

            $m->where([$m->id => $clientId])->update([
                'credit_balance'      =>  $balance,
                'client_updated_at'   =>  $this->now(),
            ]);
        });

        return $balance;
    }

    /**
     * Record a Successful Sign-In
     * @param int $clientId Client ID
     * @param string $ip IP Address
     * @return void
     */
    public function touchLogin(int $clientId, string $ip): void
    {
        $model = $this->model();

        $model->where([$model->id => $clientId])->update([
            'last_login_at' =>  $this->now(),
            'last_login_ip' =>  $ip,
        ]);
    }

    /**
     * Mark a Client's Email Address Verified
     * @param int $clientId Client ID
     * @return int Affected rows
     */
    public function verifyEmail(int $clientId): int
    {
        return $this->update($clientId, ['email_verified_at' => $this->now()]);
    }

    /**
     * Whether a Client May Sign In
     *
     * Restricted is a deliberate block placed by staff; an inactive status is
     * the account not being open for business. Either one stops a login.
     * @param array $client Client Row
     * @return bool
     */
    public function canSignIn(array $client): bool
    {
        if (($client['is_restricted'] ?? 'no') === 'yes') {
            return false;
        }

        return Status::name(self::STATUSES, $client['status_relid'] ?? null) === 'active';
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
     * Reduce Submitted Input To Writable Columns
     * @param array $input Submitted Data
     * @return array
     */
    private function fields(array $input): array
    {
        $data = $this->nullable($this->only($input, self::FIELDS), self::NULLABLE);

        foreach (['tax_exempt', 'is_restricted'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $data[$flag] = $this->flag($data[$flag]);
            }
        }

        if (array_key_exists('email', $data)) {
            $data['email'] = strtolower((string) $data['email']);
        }

        return $data;
    }
}
