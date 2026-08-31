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

namespace LBM\Controller\Client;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Throwable;
use Laika\Service\Request;
use LBM\Service\ClientService;
use LBM\Service\Support;

/**
 * Support, from the client's side.
 *
 * The one thing this controller must get right is internal notes. Staff write
 * them on the same ticket the client is reading, and they are frequently about
 * the client. Support::replies() filters them out in the query rather than in
 * the template - and this controller never passes the flag that would include
 * them - so there is no view here that could leak one by forgetting a check.
 *
 * The other is authorship. A reply from this area is recorded as the client's
 * whatever the form says, through replyByClient(), because a client-facing form
 * must not be able to name its own author.
 */
class TicketController extends ClientController
{
    protected function nav(): string
    {
        return 'tickets';
    }

    /**
     * The Client's Tickets
     * @return string
     */
    public function index(): string
    {
        $this->allow('ticket');

        $clientId = $this->owner();

        return $this->screen('tickets', 'Support', [
            'pager'    =>  Support::browseForClient($clientId),
            'statuses' =>  Support::statuses(),
            'open'     =>  Support::openCount($clientId),
        ]);
    }

    /**
     * Open a Ticket
     *
     * GET renders the form, POST raises it (instructions 16, 17).
     * @return ?string
     */
    public function create(): ?string
    {
        $this->allow('ticket', self::CREATE);

        $clientId = $this->owner();

        if (Request::isPost()) {
            $input = Request::inputs();

            $required = [
                'subject' =>  'Give your ticket a subject.',
                'message' =>  'Describe what you need help with.',
            ];

            if ($this->require($required, $input)) {
                // Not attempt(): that redirects to a route decided up front,
                // and where this should land depends on the ticket it just
                // created - which does not exist until the work has run.
                try {
                    $id = Support::openByClient([
                        'client_relid'     =>  $clientId,
                        'department_relid' =>  (int) ($input['department_relid'] ?? 0),
                        'service_relid'    =>  $this->service($input, $clientId),
                        'subject'          =>  (string) $input['subject'],
                        'priority_relid'   =>  (int) ($input['priority_relid'] ?? 0),
                    ], (string) $input['message'], $clientId);

                    $this->log('ticket.opened', 'Opened ticket: ' . $input['subject'] . '.');

                    $row = Support::find($id);

                    return $this->done(
                        'client.ticket',
                        'Your ticket has been raised.',
                        true,
                        ['ticket' => (string) $row['uid']]
                    );
                } catch (Throwable $e) {
                    // A refusal - no department set up, an empty message - is an
                    // answer to the person, so it goes on the form they are
                    // still looking at rather than onto an error page.
                    Request::addError('form', $e->getMessage());
                }
            }
        }

        return $this->screen('ticket-form', 'Open a ticket', [
            'departments' =>  $this->departmentChoices(),
            'priorities'  =>  $this->priorityChoices(),
            'services'    =>  $this->serviceChoices($clientId),
        ]);
    }

    /**
     * One Ticket And Its Thread
     * @param string $ticket Ticket Uid
     * @return string
     */
    public function show(string $ticket): string
    {
        $this->allow('ticket');

        $row = $this->ticket($ticket);

        return $this->screen('ticket', 'Ticket ' . $row['ticket_number'], [
            'ticket'     =>  $row,
            // Never the internal notes. The default is false and this call
            // deliberately does not override it.
            'replies'    =>  Support::replies((int) $row['ticket_id']),
            'department' =>  Support::department((int) $row['department_relid']),
            'closed'     =>  $this->isClosed($row),
        ]);
    }

    /**
     * Reply To a Ticket
     * @param string $ticket Ticket Uid
     * @return ?string
     */
    public function reply(string $ticket): ?string
    {
        $this->allow('ticket', self::CREATE);

        $row = $this->ticket($ticket);
        $clientId = $this->owner();
        $message = trim((string) Request::input('message', ''));

        return $this->attempt(
            function () use ($row, $message, $clientId): void {
                // replyByClient(), never reply() with an author type from the
                // form: a client-facing form must not be able to name its own
                // author, and this one cannot create an internal note either.
                Support::replyByClient((int) $row['ticket_id'], $message, $clientId);

                $this->log('ticket.replied', 'Replied to ticket ' . $row['ticket_number'] . '.');
            },
            'client.ticket',
            'Your reply has been added.',
            ['ticket' => $row['uid']]
        );
    }

    /**
     * Close a Ticket
     *
     * A client closing their own ticket is them saying the matter is finished,
     * which is worth having: it clears the queue of things nobody needs to
     * answer. Replying reopens it, so closing is never a way to lose a thread.
     * @param string $ticket Ticket Uid
     * @return ?string
     */
    public function close(string $ticket): ?string
    {
        $this->allow('ticket', self::UPDATE);

        $row = $this->ticket($ticket);

        return $this->attempt(
            function () use ($row): void {
                Support::close((int) $row['ticket_id']);

                $this->log('ticket.closed', 'Closed ticket ' . $row['ticket_number'] . '.');
            },
            'client.ticket',
            'Ticket closed. Replying to it will open it again.',
            ['ticket' => $row['uid']]
        );
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Resolve One Of The Client's Own Tickets, Or 404
     * @param string $uid Ticket Uid
     * @return array
     */
    private function ticket(string $uid): array
    {
        return $this->mine(
            static fn(int|string $key, int $clientId): ?array => Support::forClientKey($key, $clientId),
            $uid,
            'ticket'
        );
    }

    /**
     * Whether a Ticket Is Closed
     * @param array $ticket Ticket Row
     * @return bool
     */
    private function isClosed(array $ticket): bool
    {
        $closed = Support::statusId('closed');

        return $closed !== null && (int) $ticket['status_relid'] === $closed;
    }

    /**
     * The Service a New Ticket Is About, If It Names One
     *
     * Checked against the client's own services rather than trusted from the
     * form, so a submitted id belonging to somebody else becomes null instead
     * of attaching this ticket to a stranger's service.
     * @param array $input Submitted Data
     * @param int $clientId Client ID
     * @return ?int
     */
    private function service(array $input, int $clientId): ?int
    {
        $id = (int) ($input['service_relid'] ?? 0);

        if ($id === 0) {
            return null;
        }

        return ClientService::forClientKey($id, $clientId) === null ? null : $id;
    }

    /**
     * The Client's Services, As Select Choices
     * @param int $clientId Client ID
     * @return array<int,string>
     */
    private function serviceChoices(int $clientId): array
    {
        $choices = [];

        foreach (ClientService::forClient($clientId) as $row) {
            $choices[(int) $row['service_id']] = ClientService::label($row);
        }

        return $choices;
    }

    /**
     * The Departments a Client May Choose
     *
     * Visible ones only: a hidden department is one the operator routes into
     * themselves, and offering it here would let anybody drop a ticket straight
     * into a queue that is not meant to take them.
     * @return array<int,string>
     */
    private function departmentChoices(): array
    {
        $choices = [];

        foreach (Support::departments(true) as $row) {
            $choices[(int) $row['dep_id']] = (string) $row['dep_name'];
        }

        return $choices;
    }

    /**
     * Priority Choices
     * @return array<int,string>
     */
    private function priorityChoices(): array
    {
        $choices = [];

        foreach (Support::priorities() as $row) {
            $choices[(int) $row['id']] = status_label((string) $row['name']);
        }

        return $choices;
    }
}
