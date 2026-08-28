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
use LBM\Model\ClientContactModel;
use LBM\Support\PasswordValidator;

/**
 * Client contacts - the sub-logins under a client account.
 *
 * A contact belongs to exactly one client and can only ever see that client's
 * records. What they may do with them is the `permissions` JSON column, in the
 * same shape as a staff role but with its own much shorter list of areas: a
 * contact is not staff and has no role.
 *
 * Credentials go to the shared `passwords` table under rel_type = 'contact'. A
 * contact with no username has no panel access at all - they exist only to
 * receive invoice or support email - which is why the column is nullable and
 * why a blank one is stored as NULL rather than ''.
 */
class ClientContact extends Action
{
    /** @var string[] Areas a Contact Can Be Granted */
    public const GROUPS = ['invoice', 'ticket', 'domain', 'service', 'profile'];

    /** @var string[] Columns a Form May Write */
    public const FIELDS = [
        'first_name', 'middle_name', 'last_name', 'email', 'username',
        'phone_cc', 'phone_number', 'street', 'city', 'state', 'postcode',
        'country_relid', 'status_relid', 'is_primary',
    ];

    /** @var string[] Columns That Store Null Rather Than An Empty String */
    private const NULLABLE = [
        'middle_name', 'username', 'phone_cc', 'phone_number', 'street',
        'city', 'state', 'postcode', 'country_relid',
    ];

    public function model(): Model
    {
        return new ClientContactModel();
    }

    protected function searchable(): array
    {
        return ['first_name', 'last_name', 'email', 'username'];
    }

    protected function createdColumn(): ?string
    {
        return 'cc_created_at';
    }

    protected function updatedColumn(): ?string
    {
        return 'cc_updated_at';
    }

    ####################################################################################
    /*================================= EXTERNAL API =================================*/
    ####################################################################################

    /**
     * Every Contact Under a Client
     * @param int $clientId Client ID
     * @return array
     */
    public function forClient(int $clientId): array
    {
        return $this->all(['client_relid' => $clientId], self::ASC);
    }

    /**
     * Find a Contact, Scoped To Their Client
     *
     * The client id is part of the lookup rather than checked afterwards, so a
     * uid belonging to somebody else's contact is simply not found. There is no
     * branch that could forget to compare.
     * @param int|string $key Contact ID Or Uid
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
     * Find a Contact By Their Login Identifier
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
     * Create a Contact
     * @param int $clientId Client ID
     * @param array $input Submitted Data
     * @param ?string $password Plain Password
     * @return int New Contact ID
     */
    public function store(int $clientId, array $input, ?string $password = null): int
    {
        $data = $this->fields($input);
        $data['client_relid'] = $clientId;

        // The model casts `permissions` to json on read, but casts never run on
        // write - Model::insert() binds the value straight into the statement.
        // An array here would be an "Array to string conversion", not JSON.
        $data['permissions'] = $this->encode($this->permissions($input));

        $id = 0;

        $this->model()->transaction(function (ClientContactModel $m) use ($data, $password, &$id): void {
            $id = (int) $m->insert($this->stamp($data, true));

            if ($password !== null && $password !== '') {
                (new PasswordValidator())->put($id, PasswordValidator::CONTACT, $password);
            }
        });

        if (($data['is_primary'] ?? 'no') === 'yes' && $id > 0) {
            $this->makePrimary($id, $clientId);
        }

        return $id;
    }

    /**
     * Update a Contact
     * @param int|string $key Contact ID Or Uid
     * @param array $input Submitted Data
     * @return int Affected rows
     */
    public function modify(int|string $key, array $input): int
    {
        $data = $this->fields($input);
        $data['permissions'] = $this->encode($this->permissions($input));

        return $this->update($key, $data);
    }

    /**
     * Set a Contact's Password
     * @param int $contactId Contact ID
     * @param string $password Plain Password
     * @return void
     */
    public function setPassword(int $contactId, string $password): void
    {
        (new PasswordValidator())->put($contactId, PasswordValidator::CONTACT, $password);
    }

    /**
     * Make One Contact The Primary One
     *
     * Demotes the others in the same transaction - "primary" that two rows can
     * claim at once is not primary.
     * @param int $contactId Contact ID
     * @param int $clientId Client ID
     * @return void
     */
    public function makePrimary(int $contactId, int $clientId): void
    {
        $this->model()->transaction(function (ClientContactModel $m) use ($contactId, $clientId): void {
            $m->where(['client_relid' => $clientId])
                ->whereNot([$m->id => $contactId])
                ->update(['is_primary' => 'no', 'cc_updated_at' => $this->now()]);

            $m->where([$m->id => $contactId])
                ->update(['is_primary' => 'yes', 'cc_updated_at' => $this->now()]);
        });
    }

    /**
     * Whether a Contact Is Granted An Access
     * @param array $contact Contact Row
     * @param string $access Access Name. Example: 'invoice.read'
     * @return bool
     */
    public function allows(array $contact, string $access): bool
    {
        if (!str_contains($access, '.')) {
            return false;
        }

        [$group, $action] = explode('.', strtolower($access), 2);

        $permissions = $contact['permissions'] ?? [];

        if (is_string($permissions)) {
            // Reached through a path that did not go via the model's casts -
            // a join, for instance, where the cast key does not match.
            $permissions = json_decode($permissions, true) ?: [];
        }

        return !empty($permissions[$group][$action]);
    }

    /**
     * Whether An Email Address Is Already Used By Another Contact
     * @param string $email Email Address
     * @param ?int $ignore Contact ID To Exclude, When Editing
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
     * The Areas a Contact Can Be Granted
     *
     * A method rather than the constant, because a relay facade forwards method
     * calls and not constants.
     * @return string[]
     */
    public function groups(): array
    {
        return self::GROUPS;
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

        if (array_key_exists('is_primary', $data)) {
            $data['is_primary'] = $this->flag($data['is_primary']);
        }

        if (array_key_exists('email', $data)) {
            $data['email'] = strtolower((string) $data['email']);
        }

        return $data;
    }

    /**
     * Normalise Submitted Permission Checkboxes
     *
     * Every known pair is written explicitly as 1 or 0. An unchecked box is
     * absent from the POST body, so omitting the pair would leave whatever was
     * there before and a revoked permission would survive the save.
     * @param array $input Submitted Data
     * @return array
     */
    private function permissions(array $input): array
    {
        $submitted = $input['permissions'] ?? [];
        $submitted = is_array($submitted) ? $submitted : [];

        $permissions = [];

        foreach (self::GROUPS as $group) {
            foreach (['read', 'create', 'update', 'delete'] as $action) {
                $permissions[$group][$action] = empty($submitted[$group][$action]) ? 0 : 1;
            }
        }

        return $permissions;
    }

    /**
     * Encode a Permission Set For The json Column
     * @param array $permissions Permission Set
     * @return string
     */
    private function encode(array $permissions): string
    {
        return json_encode($permissions, JSON_THROW_ON_ERROR);
    }
}
