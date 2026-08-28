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
use LBM\Model\ClientModel;
use LBM\Model\SupportTicketModel;
use LBM\Model\SupportTicketReplyModel;
use LBM\Model\SupportDepartmentModel;
use LBM\Service\Status;
use LBM\Support\Uid;

/**
 * Support tickets, their replies and the departments they land in.
 *
 * A ticket and its first message are one thing to the person opening it, so
 * open() writes both - a ticket with no body is not a support request.
 *
 * Replies carry an author_type of client, staff or system rather than a single
 * user id, because the three come from different tables. `is_internal` marks a
 * staff note that the client must never see; every client-facing read here
 * filters those out at the query rather than in the template, so a view that
 * forgets to check cannot leak one.
 */
class Support extends Action
{
    /** @var string Status Lookup Table */
    public const STATUSES = 'support_ticket_statuses';

    /** @var string Priority Lookup Table */
    public const PRIORITIES = 'support_priorities';

    /** @var string Opened Or Replied To By The Client */
    public const CLIENT = 'client';

    /** @var string Opened Or Replied To By Staff */
    public const STAFF = 'staff';

    /** @var string Written By The Application Itself */
    public const SYSTEM = 'system';

    /** @var string[] Columns a Form May Write */
    public const FIELDS = [
        'client_relid', 'department_relid', 'service_relid', 'assigned_staff_relid',
        'subject', 'status_relid', 'priority_relid',
    ];

    public function model(): Model
    {
        return new SupportTicketModel();
    }

    protected function searchable(): array
    {
        return ['ticket_number', 'subject'];
    }

    protected function createdColumn(): ?string
    {
        return 'ticket_created_at';
    }

    protected function updatedColumn(): ?string
    {
        return 'ticket_updated_at';
    }

    ####################################################################################
    /*=================================== READING ====================================*/
    ####################################################################################

    /**
     * One Page Of Tickets, With The Client Who Opened Them
     * @param array $where Conditions
     * @param ?string $search Search Term
     * @param ?int $limit Rows Per Page
     * @return array
     */
    public function browseWithClients(array $where = [], ?string $search = null, ?int $limit = null): array
    {
        $tickets = new SupportTicketModel();
        $clients = new ClientModel();

        $t = $tickets->table;
        $c = $clients->table;

        $qualified = [];

        foreach ($where as $column => $value) {
            $key = str_contains((string) $column, '.') ? (string) $column : "{$t}.{$column}";
            $qualified[$key] = $value;
        }

        $counted = new SupportTicketModel();
        $this->conditions($counted, $qualified);

        $listed = new SupportTicketModel();
        $listed->select([
            "{$t}.*",
            "{$c}.first_name AS client_first_name",
            "{$c}.last_name AS client_last_name",
            "{$c}.company_name AS client_company_name",
        ])->join($c, "{$c}.{$clients->id}", '=', "{$t}.client_relid");

        $this->conditions($listed, $qualified);

        if ($search !== null && trim($search) !== '') {
            $term = '%' . trim($search) . '%';
            $columns = [
                "{$t}.ticket_number" =>  $term,
                "{$t}.subject"       =>  $term,
                "{$c}.first_name"    =>  $term,
                "{$c}.last_name"     =>  $term,
            ];

            $counted->join($c, "{$c}.{$clients->id}", '=', "{$t}.client_relid")
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
     * Find a Ticket, Scoped To Its Client
     * @param int|string $key Ticket ID Or Uid
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
     * Tickets Opened By One Client
     * @param int $clientId Client ID
     * @param ?int $limit Rows Per Page
     * @return array
     */
    public function browseForClient(int $clientId, ?int $limit = null): array
    {
        return $this->browse(['client_relid' => $clientId], null, $limit, self::DESC);
    }

    /**
     * Every Reply On a Ticket
     * @param int $ticketId Ticket ID
     * @param bool $includeInternal Whether Staff-Only Notes Are Included
     * @return array
     */
    public function replies(int $ticketId, bool $includeInternal = false): array
    {
        $model = new SupportTicketReplyModel();
        $model->where(['ticket_relid' => $ticketId]);

        // Filtered in the query, not in the view. A staff note that leaks into
        // the client area is the one bug in this class that would actually cost
        // somebody something.
        if (!$includeInternal) {
            $model->where(['is_internal' => 'no']);
        }

        return $model->order($model->id, self::ASC)->get();
    }

    /**
     * How Many Tickets Are Currently Open
     * @param ?int $clientId Client ID. Null counts every client's
     * @return int
     */
    public function openCount(?int $clientId = null): int
    {
        $closed = Status::idOf(self::STATUSES, 'closed');

        $model = $this->model();

        if ($clientId !== null) {
            $model->where(['client_relid' => $clientId]);
        }

        if ($closed !== null) {
            $model->whereNot(['status_relid' => $closed]);
        }

        return $model->count();
    }

    ####################################################################################
    /*=================================== WRITING ====================================*/
    ####################################################################################

    /**
     * Open a Ticket, With Its First Message
     * @param array $input Submitted Data
     * @param string $message The First Message
     * @param string $authorType client, staff or system
     * @param ?int $authorId Author ID. Null for system
     * @return int New Ticket ID
     * @throws RuntimeException
     */
    public function open(array $input, string $message, string $authorType = self::CLIENT, ?int $authorId = null): int
    {
        $message = trim($message);

        if ($message === '') {
            throw new RuntimeException('A ticket needs a message.');
        }

        $data = $this->fields($input);

        $data['status_relid'] = (int) ($data['status_relid'] ?? 0)
            ?: (Status::idOf(self::STATUSES, 'open') ?? 1);

        $data['priority_relid'] = (int) ($data['priority_relid'] ?? 0)
            ?: (Status::idOf(self::PRIORITIES, 'medium') ?? 1);

        $data['opened_by'] = $this->authorType($authorType);
        $data['last_reply_at'] = $this->now();

        $uid = Uid::make();
        $data['uid'] = $uid;
        $data['ticket_number'] = $uid;

        $id = 0;

        $this->model()->transaction(function (SupportTicketModel $m) use ($data, $message, $authorType, $authorId, &$id): void {
            $id = (int) $m->insert($this->stamp($data, true));

            $this->insertReply($id, $message, $authorType, $authorId, false);

            $m->where([$m->id => $id])->update(['ticket_number' => $this->number($id)]);
        });

        return $id;
    }

    /**
     * Add a Reply To a Ticket
     *
     * Answering a ticket reopens it. A client replying to something staff marked
     * answered has, by definition, not had their question answered.
     * @param int $ticketId Ticket ID
     * @param string $message Message
     * @param string $authorType client, staff or system
     * @param ?int $authorId Author ID
     * @param bool $internal Whether This Is a Staff-Only Note
     * @return int New Reply ID
     * @throws RuntimeException
     */
    public function reply(
        int $ticketId,
        string $message,
        string $authorType = self::CLIENT,
        ?int $authorId = null,
        bool $internal = false
    ): int {
        $message = trim($message);

        if ($message === '') {
            throw new RuntimeException('A reply needs a message.');
        }

        $id = $this->insertReply($ticketId, $message, $authorType, $authorId, $internal);

        $data = [
            'last_reply_at'      =>  $this->now(),
            'ticket_updated_at'  =>  $this->now(),
        ];

        // An internal note is staff talking among themselves - it should not
        // move the ticket out of whatever state the client left it in.
        if (!$internal) {
            $status = $this->authorType($authorType) === self::CLIENT
                ? Status::idOf(self::STATUSES, 'open')
                : Status::idOf(self::STATUSES, 'answered');

            if ($status !== null) {
                $data['status_relid'] = $status;
            }
        }

        $model = $this->model();
        $model->where([$model->id => $ticketId])->update($data);

        return $id;
    }

    /**
     * Move a Ticket To Another Status
     * @param int|string $key Ticket ID Or Uid
     * @param int $statusId Status ID
     * @return int Affected rows
     */
    public function setStatus(int|string $key, int $statusId): int
    {
        return $this->update($key, ['status_relid' => $statusId]);
    }

    /**
     * Close a Ticket
     * @param int|string $key Ticket ID Or Uid
     * @return int Affected rows
     */
    public function close(int|string $key): int
    {
        $status = Status::idOf(self::STATUSES, 'closed');

        return $status === null ? 0 : $this->update($key, ['status_relid' => $status]);
    }

    /**
     * Assign a Ticket To a Staff Member
     * @param int|string $key Ticket ID Or Uid
     * @param ?int $staffId Staff ID. Null unassigns
     * @return int Affected rows
     */
    public function assign(int|string $key, ?int $staffId): int
    {
        return $this->update($key, ['assigned_staff_relid' => $staffId]);
    }

    /**
     * Delete a Ticket And Its Replies
     * @param int|string $key Ticket ID Or Uid
     * @return int Affected rows
     */
    public function remove(int|string $key): int
    {
        $ticket = $this->find($key);

        if ($ticket === null) {
            return 0;
        }

        $id = (int) $ticket['ticket_id'];
        $affected = 0;

        $this->model()->transaction(function (SupportTicketModel $m) use ($id, &$affected): void {
            (new SupportTicketReplyModel())->where(['ticket_relid' => $id])->delete();

            $affected = $m->where([$m->id => $id])->delete();
        });

        return $affected;
    }

    ####################################################################################
    /*================================= DEPARTMENTS ==================================*/
    ####################################################################################

    /**
     * Every Department
     * @param bool $visibleOnly Only Departments a Client May Choose
     * @return array
     */
    public function departments(bool $visibleOnly = false): array
    {
        $model = new SupportDepartmentModel();

        if ($visibleOnly) {
            $model->where(['dep_is_active' => 'yes', 'dep_hidden' => 'no']);
        }

        return $model->order('dep_name', self::ASC)->get();
    }

    /**
     * The Department a Ticket Lands In When Nobody Chose One
     *
     * The first department a client is allowed to see, by name. There is no
     * "is default" column, and adding one would be another setting an operator
     * has to maintain for a case that only comes up when nobody picked - a
     * cancellation request, a system-raised ticket. First-by-name is stable,
     * and every install seeds at least one department.
     *
     * Null when there are none at all, which callers have to handle: a ticket
     * raised into a department that does not exist is a row nobody can see.
     * @return ?int
     */
    public function defaultDepartmentId(): ?int
    {
        $departments = $this->departments(true);

        if ($departments === []) {
            // Nothing client-visible. Any department at all is still better
            // than losing the request.
            $departments = $this->departments(false);
        }

        $first = $departments[0] ?? null;

        return $first === null ? null : ((int) $first['dep_id'] ?: null);
    }

    /**
     * Find One Department
     * @param int|string $key Department ID Or Uid
     * @return ?array
     */
    public function department(int|string $key): ?array
    {
        $model = new SupportDepartmentModel();
        $row = $this->key($model, $key)->first();

        return is_array($row) ? $row : null;
    }

    /**
     * Create Or Update a Department
     * @param array $input Submitted Data
     * @param int|string|null $key Department ID Or Uid. Null creates
     * @return int The department ID
     */
    public function saveDepartment(array $input, int|string|null $key = null): int
    {
        $model = new SupportDepartmentModel();

        $data = [
            'dep_name'            =>  trim((string) ($input['dep_name'] ?? '')),
            'dep_email'           =>  trim((string) ($input['dep_email'] ?? '')) ?: null,
            'dep_description'     =>  trim((string) ($input['dep_description'] ?? '')),
            'dep_requires_login'  =>  $this->flag($input['dep_requires_login'] ?? 'no'),
            'dep_hidden'          =>  $this->flag($input['dep_hidden'] ?? 'no'),
            'dep_auto_close_days' =>  (int) ($input['dep_auto_close_days'] ?? 7),
            'dep_is_active'       =>  $this->flag($input['dep_is_active'] ?? 'no'),
        ];

        if ($key !== null && $key !== '' && $key !== 0) {
            $department = $this->department($key);

            if ($department !== null) {
                $id = (int) $department['dep_id'];
                $data['dep_updated_at'] = $this->now();

                $model->where([$model->id => $id])->update($data);

                return $id;
            }
        }

        $data[$model->uid] = Uid::make();
        $data['dep_created_at'] = $this->now();
        $data['dep_updated_at'] = $this->now();

        return (int) $model->insert($data);
    }

    /**
     * Delete a Department
     *
     * Refuses while tickets still point at it - those tickets would show no
     * department at all rather than a sensible fallback.
     * @param int|string $key Department ID Or Uid
     * @return int Affected rows
     * @throws RuntimeException
     */
    public function removeDepartment(int|string $key): int
    {
        $department = $this->department($key);

        if ($department === null) {
            return 0;
        }

        $id = (int) $department['dep_id'];
        $tickets = $this->count(['department_relid' => $id]);

        if ($tickets > 0) {
            throw new RuntimeException(
                "{$tickets} ticket(s) belong to this department. Deactivate it instead of deleting it."
            );
        }

        $model = new SupportDepartmentModel();

        return $model->where([$model->id => $id])->delete();
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

    /**
     * The Priority Choices a Form Offers
     * @return array
     */
    public function priorities(): array
    {
        return Status::all(self::PRIORITIES);
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Open a Ticket On a Client's Behalf, As Staff
     *
     * A named wrapper rather than making callers pass self::STAFF: a relay
     * facade forwards method calls and not constants, so a controller reaching
     * this through LBM\Service\Support cannot name the author type itself.
     * @param array $input Submitted Data
     * @param string $message The First Message
     * @param ?int $staffId Author Staff ID
     * @return int New Ticket ID
     */
    public function openByStaff(array $input, string $message, ?int $staffId): int
    {
        return $this->open($input, $message, self::STAFF, $staffId);
    }

    /**
     * Open a Ticket As The Client
     * @param array $input Submitted Data
     * @param string $message The First Message
     * @param ?int $clientId Author Client ID
     * @return int New Ticket ID
     */
    public function openByClient(array $input, string $message, ?int $clientId): int
    {
        return $this->open($input, $message, self::CLIENT, $clientId);
    }

    /**
     * Reply To a Ticket As Staff
     * @param int $ticketId Ticket ID
     * @param string $message Message
     * @param ?int $staffId Author Staff ID
     * @param bool $internal Whether This Is a Staff-Only Note
     * @return int New Reply ID
     */
    public function replyByStaff(int $ticketId, string $message, ?int $staffId, bool $internal = false): int
    {
        return $this->reply($ticketId, $message, self::STAFF, $staffId, $internal);
    }

    /**
     * Reply To a Ticket As The Client
     *
     * Never internal - an internal note is a staff-only thing by definition, and
     * this path cannot create one whatever it is asked for.
     * @param int $ticketId Ticket ID
     * @param string $message Message
     * @param ?int $clientId Author Client ID
     * @return int New Reply ID
     */
    public function replyByClient(int $ticketId, string $message, ?int $clientId): int
    {
        return $this->reply($ticketId, $message, self::CLIENT, $clientId, false);
    }

    /**
     * Insert One Reply
     * @param int $ticketId Ticket ID
     * @param string $message Message
     * @param string $authorType client, staff or system
     * @param ?int $authorId Author ID
     * @param bool $internal Whether This Is a Staff-Only Note
     * @return int New Reply ID
     */
    private function insertReply(
        int $ticketId,
        string $message,
        string $authorType,
        ?int $authorId,
        bool $internal
    ): int {
        $model = new SupportTicketReplyModel();

        return (int) $model->insert([
            $model->uid         =>  Uid::make(),
            'ticket_relid'      =>  $ticketId,
            'author_type'       =>  $this->authorType($authorType),
            'author_relid'      =>  $authorId,
            'message'           =>  $message,
            'is_internal'       =>  $internal ? 'yes' : 'no',
            'reply_created_at'  =>  $this->now(),
        ]);
    }

    /**
     * Constrain An Author Type To What The Column Accepts
     *
     * The column is an enum; an unrecognised value is a truncated-data error on
     * MySQL and a constraint failure elsewhere. System is the safe default -
     * attributing a message to nobody is better than attributing it wrongly.
     * @param string $type Author Type
     * @return string
     */
    private function authorType(string $type): string
    {
        $type = strtolower(trim($type));

        return in_array($type, [self::CLIENT, self::STAFF, self::SYSTEM], true) ? $type : self::SYSTEM;
    }

    /**
     * Build a Ticket Number From a Primary Key
     * @param int $ticketId Ticket ID
     * @return string
     */
    private function number(int $ticketId): string
    {
        $prefix = option('ticket_prefix', 'TKT-') ?? 'TKT-';

        return $prefix . str_pad((string) $ticketId, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Reduce Submitted Input To Writable Columns
     * @param array $input Submitted Data
     * @return array
     */
    private function fields(array $input): array
    {
        return $this->nullable(
            $this->only($input, self::FIELDS),
            ['service_relid', 'assigned_staff_relid']
        );
    }
}
