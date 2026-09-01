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

namespace LBM\Controller\Admin;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Laika\Service\Request;
use LBM\Service\Client;
use LBM\Service\Staff;
use LBM\Service\Support;

/**
 * Support tickets.
 *
 * Staff see everything on a ticket, including internal notes - replies() is
 * called here with internal messages included, and it is the only place in the
 * application that does. The client area calls the same method without that
 * flag, so a note marked internal is filtered out at the query rather than in a
 * template that might forget.
 */
class TicketController extends AdminController
{
    protected function nav(): string
    {
        return 'tickets';
    }

    ####################################################################################
    /*=================================== TICKETS ====================================*/
    ####################################################################################

    /**
     * The Ticket List
     * @return string
     */
    public function index(): string
    {
        $page = Support::browseWithClients(
            $this->conditions([
                'status'     =>  'status_relid',
                'priority'   =>  'priority_relid',
                'department' =>  'department_relid',
                'staff'      =>  'assigned_staff_relid',
            ]),
            $this->search()
        );

        return $this->screen('tickets', 'Support', [
            'pager'       =>  $page,
            'statuses'    =>  Support::statuses(),
            'priorities'  =>  Support::priorities(),
            'departments' =>  Support::departments(),
            'open'        =>  Support::openCount(),
        ]);
    }

    /**
     * Open a Ticket On a Client's Behalf
     * @return ?string
     */
    public function create(): ?string
    {
        if (Request::isPost()) {
            $input = Request::inputs();
            $message = trim((string) ($input['message'] ?? ''));

            $this->require([
                'client_relid'     =>  local('choose_a_client_msg'),
                'department_relid' =>  local('choose_a_department_msg'),
                'subject'          =>  local('subject_required'),
            ], $input);

            if ($message === '') {
                Request::addError('message', local('ticket_needs_message'));
            }

            if (Request::errors() === []) {
                return $this->attempt(
                    function () use ($input, $message): void {
                        $id = Support::openByStaff($input, $message, $this->staffId());
                        $ticket = Support::find($id);

                        $this->log('ticket.opened', 'Opened ticket ' . $ticket['ticket_number']);
                    },
                    'staff.tickets',
                    local('ticket_opened')
                );
            }
        }

        return $this->screen('ticket-form', local('new_ticket'), [
            'clients'     =>  $this->clientChoices(),
            'departments' =>  $this->departmentChoices(),
            'priorities'  =>  $this->statusChoices(Support::priorities()),
        ]);
    }

    /**
     * One Ticket, With Its Whole Conversation
     * @param string $ticket Ticket Uid
     * @return string
     */
    public function show(string $ticket): string
    {
        $row = $this->record(Support::find($ticket), 'ticket');

        return $this->screen('ticket', local('ticket_titled', $row['ticket_number']), [
            'ticket'      =>  $row,
            'client'      =>  Client::find((int) $row['client_relid']),
            // Internal notes included - this is the staff view.
            'replies'     =>  Support::replies((int) $row['ticket_id'], true),
            'statuses'    =>  $this->statusChoices(Support::statuses()),
            'staffs'      =>  $this->staffChoices(),
            'department'  =>  Support::department((int) $row['department_relid']),
        ]);
    }

    /**
     * Reply To a Ticket
     * @param string $ticket Ticket Uid
     * @return ?string
     */
    public function reply(string $ticket): ?string
    {
        $row = $this->record(Support::find($ticket), 'ticket');
        $input = Request::inputs();

        $message = trim((string) ($input['message'] ?? ''));
        $internal = !empty($input['is_internal']) && $input['is_internal'] !== 'false';

        return $this->attempt(
            function () use ($row, $message, $internal): void {
                Support::replyByStaff(
                    (int) $row['ticket_id'],
                    $message,
                    $this->staffId(),
                    $internal
                );

                $this->log(
                    $internal ? 'ticket.note' : 'ticket.replied',
                    ($internal ? 'Left an internal note on ' : 'Replied to ')
                        . 'ticket ' . $row['ticket_number']
                );
            },
            'staff.ticket',
            local($internal ? 'internal_note_added' : 'reply_sent'),
            ['ticket' => $row['uid']]
        );
    }

    /**
     * Change a Ticket's Status Or Assignee
     * @param string $ticket Ticket Uid
     * @return ?string
     */
    public function status(string $ticket): ?string
    {
        $row = $this->record(Support::find($ticket), 'ticket');
        $input = Request::inputs();

        $status = $input['status_relid'] ?? null;

        if ($status !== null && $status !== '') {
            Support::setStatus((int) $row['ticket_id'], (int) $status);
        }

        // An empty assignee is a deliberate unassign, not a missing field, so
        // it is only acted on when the key was actually submitted.
        if (array_key_exists('assigned_staff_relid', $input)) {
            $assignee = trim((string) $input['assigned_staff_relid']);

            Support::assign((int) $row['ticket_id'], $assignee !== '' ? (int) $assignee : null);
        }

        $this->log('ticket.updated', 'Updated ticket ' . $row['ticket_number']);

        return $this->done('staff.ticket', local('ticket_updated'), true, ['ticket' => $row['uid']]);
    }

    /**
     * Delete a Ticket
     * @param string $ticket Ticket Uid
     * @return ?string
     */
    public function delete(string $ticket): ?string
    {
        $row = $this->record(Support::find($ticket), 'ticket');
        $number = (string) $row['ticket_number'];

        Support::remove((int) $row['ticket_id']);

        $this->log('ticket.deleted', "Deleted ticket {$number} and its replies.");

        return $this->done('staff.tickets', local('deleted_ticket', $number));
    }

    ####################################################################################
    /*================================= DEPARTMENTS ==================================*/
    ####################################################################################

    /**
     * Support Departments
     * @return string
     */
    public function departments(): string
    {
        return $this->screen('ticket-departments', local('support_departments'), [
            'departments' =>  Support::departments(),
            'counts'      =>  $this->departmentCounts(),
        ]);
    }

    /**
     * Create Or Update a Department
     * @return ?string
     */
    public function departmentSave(): ?string
    {
        $input = Request::inputs();
        $name = trim((string) ($input['dep_name'] ?? ''));

        if ($name === '') {
            return $this->done('staff.ticket.departments', local('department_needs_name'), false);
        }

        $key = trim((string) ($input['department'] ?? ''));

        return $this->attempt(
            function () use ($input, $key, $name): void {
                Support::saveDepartment($input, $key !== '' ? $key : null);

                $this->log(
                    'ticket.department.saved',
                    ($key !== '' ? 'Updated' : 'Added') . " support department {$name}."
                );
            },
            'staff.ticket.departments',
            local($key !== '' ? 'department_updated' : 'department_added')
        );
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * How Many Tickets Sit In Each Department
     * @return array<int,int>
     */
    private function departmentCounts(): array
    {
        $counts = [];

        foreach (Support::departments() as $department) {
            $id = (int) $department['dep_id'];
            $counts[$id] = Support::count(['department_relid' => $id]);
        }

        return $counts;
    }

    /**
     * Department Choices
     * @return array<int,string>
     */
    private function departmentChoices(): array
    {
        $choices = [];

        foreach (Support::departments(true) as $department) {
            $choices[(int) $department['dep_id']] = (string) $department['dep_name'];
        }

        return $choices;
    }

    /**
     * Client Choices
     * @return array<int,string>
     */
    private function clientChoices(): array
    {
        $choices = [];

        foreach (Client::all([], 'ASC', 'first_name') as $row) {
            $label = trim((string) ($row['company_name'] ?? '')) !== ''
                ? $row['company_name'] . ' (' . $row['first_name'] . ' ' . $row['last_name'] . ')'
                : $row['first_name'] . ' ' . $row['last_name'];

            $choices[(int) $row['cid']] = $label;
        }

        return $choices;
    }

    /**
     * Staff Choices, For Assigning a Ticket
     * @return array<int,string>
     */
    private function staffChoices(): array
    {
        $choices = [];

        foreach (Staff::all([], 'ASC', 'first_name') as $row) {
            $choices[(int) $row['sid']] = $row['first_name'] . ' ' . $row['last_name'];
        }

        return $choices;
    }
}
