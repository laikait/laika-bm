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

namespace LBM\Controller\Front;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Throwable;
use Laika\Service\Request;
use LBM\Service\Client;
use LBM\Service\Mail;
use LBM\Service\Support;
use LBM\Service\KnowledgeBase;

/**
 * The support landing page, and the public contact form.
 *
 * ------------------------------------------------------------------------
 * What a submission does
 * ------------------------------------------------------------------------
 * Both things, in this order:
 *
 *   1. Opens a support ticket in the chosen department, so the enquiry is a
 *      tracked record with a number, a status and a thread - the same object
 *      staff already work through, not a second kind of inbox.
 *   2. Queues an email to `app_email` carrying that ticket number, with the
 *      sender's address as reply-to.
 *
 * The ticket is written first because it is the durable half. If the mail
 * server is misconfigured the enquiry is still on the ticket list; if the
 * ticket cannot be raised the mail still reaches somebody. The visitor is only
 * told the form failed when *neither* landed - being told "we lost it" about a
 * message that is sitting in the ticket queue would be worse than saying
 * nothing.
 *
 * ------------------------------------------------------------------------
 * Who the ticket belongs to
 * ------------------------------------------------------------------------
 * `support_tickets.client_relid` is NOT NULL, so a ticket needs an account and
 * a stranger has none. A signed-in client gets the ticket on their own account
 * - proven identity, and it then appears in their own area. Everyone else gets
 * the shared stand-in account, which `Action\Client::enquiryAccountId()`
 * creates once and documents at length.
 *
 * Note what is deliberately *not* done: the sender's address is never matched
 * against an existing client. Nothing here has verified that address, and
 * filing a stranger's message into a real customer's ticket history because
 * they typed that customer's email would be a spoof this form handed out for
 * free.
 *
 * ------------------------------------------------------------------------
 * Departments
 * ------------------------------------------------------------------------
 * `dep_requires_login` finally means something here. A department marked as
 * needing an account is offered only when somebody is actually signed in;
 * anonymous visitors see the rest. When the visitor picks nothing usable the
 * ticket still lands, in `defaultDepartmentId()` - routing is the operator's
 * business and an unrouted enquiry helps nobody.
 *
 * The select is keyed by uid, not `dep_id`. Primary keys do not belong in a
 * form a stranger reads, and an unrecognised uid is simply not in the offered
 * map, so a tampered value falls through to the default rather than reaching
 * a hidden or login-only department.
 *
 * ------------------------------------------------------------------------
 * What this form will not do
 * ------------------------------------------------------------------------
 * It sends no acknowledgement to the address that was typed. Anyone can type
 * anyone's address, so an auto-reply would turn a public form into a way of
 * making this server mail strangers on request. The operator's copy is queued,
 * never sent inline - nothing in this application sends on a web request, and
 * a contact form that blocked on a slow SMTP server would time out in front of
 * the person least willing to wait.
 */
class SupportController extends FrontController
{
    /**
     * Which Top-Nav Item Is Current
     * @return string
     */
    protected function nav(): string
    {
        return 'support';
    }

    /**
     * The Support Landing Page
     * @return string
     */
    public function index(): string
    {
        return $this->screen('support', local('support_centre'), [
            'meta_description' =>  local('support_meta', app_name()),
            'featured'         =>  KnowledgeBase::featured(6),
            'categories'       =>  KnowledgeBase::categories(true),
        ]);
    }

    /**
     * The Contact Form
     * @return ?string
     */
    public function contact(): ?string
    {
        if (Request::isPost()) {
            return $this->send();
        }

        return $this->form(local('contact_meta', app_name()));
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Validate a Submission, Then Raise a Ticket And Queue The Operator's Copy
     * @return ?string
     */
    private function send(): ?string
    {
        $input = Request::inputs();
        $offered = $this->offeredDepartments();

        if (!$this->valid($input, $offered)) {
            // Re-render rather than redirect, so what they typed is still in
            // the boxes. This is the one place the area does not redirect after
            // a POST, and it is the right trade: losing a paragraph somebody
            // just wrote is worse than a resubmit warning on a form that has
            // not written anything yet.
            return $this->form(null, $offered);
        }

        $name = trim((string) ($input['name'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        $subject = trim((string) ($input['subject'] ?? ''));
        $message = trim((string) ($input['message'] ?? ''));

        $ticket = $this->raise($subject, $this->thread($name, $email, $message), $input, $offered);
        $mailed = $this->notify($name, $email, $subject, $message, $ticket);

        if ($ticket === null && !$mailed) {
            // Nothing was recorded anywhere. The two messages are not
            // interchangeable: one is a site that was never finished being set
            // up, the other is a fault that may well have cleared by the time
            // they try again.
            return $this->done(
                'front.contact',
                option('app_email', '') === '' ? local('contact_unavailable') : local('contact_failed'),
                false
            );
        }

        return $this->done('front.contact', $ticket === null
            ? local('contact_sent')
            : local('contact_sent_ticket', (string) $ticket['ticket_number']));
    }

    /**
     * Render The Contact Form
     * @param ?string $meta Meta Description, Or Null To Omit It
     * @param ?array $offered Departments Already Resolved, Or Null To Look Them Up
     * @return string
     */
    private function form(?string $meta = null, ?array $offered = null): string
    {
        $offered = $offered ?? $this->offeredDepartments();

        return $this->screen('contact', local('contact_us'), [
            'meta_description' =>  $meta,
            'departments'      =>  array_map(
                static fn (array $row): string => (string) $row['dep_name'],
                $offered
            ),
        ]);
    }

    /**
     * Check What Was Submitted
     * @param array $input Submitted Data
     * @param array<string,array> $offered Departments This Visitor May Choose
     * @return bool True when the submission may be acted on
     */
    private function valid(array $input, array $offered): bool
    {
        $ok = $this->require([
            'name'    =>  local('name_required'),
            'email'   =>  local('email_required'),
            'subject' =>  local('subject_required'),
            'message' =>  local('message_required'),
        ], $input);

        $this->requireEmail('email', $input);

        // Only enforced when there is something to choose from. An operator who
        // has opened no department to the public gets a form with no select on
        // it, and a form cannot require a field it never rendered.
        if ($offered !== [] && !isset($offered[trim((string) ($input['department'] ?? ''))])) {
            Request::addError('department', local('department_required'));

            return false;
        }

        return $ok && Request::errors() === [];
    }

    /**
     * Open The Ticket
     *
     * Returns null rather than throwing when the ticket cannot be raised - no
     * department exists at all, or the stand-in account could not be created.
     * The caller still has the email to fall back on, and a visitor should not
     * be shown a stack trace because an operator deleted every department.
     * @param string $subject Subject
     * @param string $message The First Message
     * @param array $input Submitted Data
     * @param array<string,array> $offered Departments This Visitor May Choose
     * @return ?array The new ticket row, or null when none was raised
     */
    private function raise(string $subject, string $message, array $input, array $offered): ?array
    {
        $client = current_client();
        $clientId = $client !== null ? (int) $client['cid'] : Client::enquiryAccountId();
        $department = $this->departmentId($input, $offered);

        if ($clientId === 0 || $department === null) {
            return null;
        }

        try {
            $id = Support::openByClient([
                'client_relid'     =>  $clientId,
                'department_relid' =>  $department,
                'subject'          =>  $subject,
            ], $message, $clientId);

            return Support::find($id);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Queue The Operator's Copy
     * @param string $name Sender Name
     * @param string $email Sender Address
     * @param string $subject Subject
     * @param string $message Message
     * @param ?array $ticket The Ticket That Was Raised, If Any
     * @return bool True when a message was queued
     */
    private function notify(string $name, string $email, string $subject, string $message, ?array $ticket): bool
    {
        // Where the operator reads their post. app_email is the address the
        // installer asked for; mail_from is the envelope sender and is often a
        // no-reply, so it is the wrong place to deliver an enquiry.
        $to = (string) option('app_email', '');

        if ($to === '') {
            return false;
        }

        try {
            Mail::queue([
                'to_email'  =>  $to,
                'reply_to'  =>  $email,
                'subject'   =>  $ticket === null
                    ? local('contact_subject', $subject)
                    : local('contact_subject_ticket', (string) $ticket['ticket_number'], $subject),
                'body_html' =>  $this->body($name, $email, $subject, $message, $ticket),
            ]);

            return true;
        } catch (Throwable) {
            // What went wrong is a mail configuration problem, which is the
            // operator's to find in the log rather than the sender's to
            // decipher. The ticket, if there is one, is unaffected.
            return false;
        }
    }

    /**
     * Which Department The Ticket Lands In
     * @param array $input Submitted Data
     * @param array<string,array> $offered Departments This Visitor May Choose
     * @return ?int Null when the install has no department at all
     */
    private function departmentId(array $input, array $offered): ?int
    {
        $uid = trim((string) ($input['department'] ?? ''));

        if ($uid !== '' && isset($offered[$uid])) {
            return (int) $offered[$uid]['dep_id'];
        }

        return Support::defaultDepartmentId();
    }

    /**
     * Departments This Visitor May Write To, Keyed By Uid
     *
     * Active and not hidden, always. Departments marked as needing an account
     * are included only for somebody who has one and is signed in - which is
     * what `dep_requires_login` has always claimed to mean and, until the form
     * could raise a ticket at all, could not actually deliver.
     * @return array<string,array>
     */
    private function offeredDepartments(): array
    {
        $signedIn = current_client() !== null;
        $offered = [];

        foreach (Support::departments(true) as $department) {
            if (!$signedIn && ($department['dep_requires_login'] ?? 'yes') === 'yes') {
                continue;
            }

            $offered[(string) $department['uid']] = $department;
        }

        return $offered;
    }

    /**
     * The Ticket's First Message
     *
     * Plain text with real newlines. The ticket views print `reply.message`
     * through Twig's autoescaping inside `white-space: pre-wrap`, so markup
     * here would be shown rather than rendered and is simply noise.
     *
     * The sender's name and address are part of the message because there is
     * nowhere else on a ticket to put them: the row itself points at the
     * stand-in account, so without this the operator opens an enquiry with no
     * way of telling who wrote it or where a reply should go.
     * @param string $name Sender Name
     * @param string $email Sender Address
     * @param string $message Message
     * @return string
     */
    private function thread(string $name, string $email, string $message): string
    {
        return local('sent_from_contact_form') . "\n"
            . local('from') . ': ' . $name . ' <' . $email . ">\n\n"
            . $message;
    }

    /**
     * The Email Body For An Enquiry
     *
     * Every value is escaped. This is the one place in the application where a
     * complete stranger's text is composed into HTML, and it lands in the
     * operator's mail client - the reader least able to tell markup they asked
     * for from markup somebody sent them.
     * @param string $name Sender Name
     * @param string $email Sender Address
     * @param string $subject Subject
     * @param string $message Message
     * @param ?array $ticket The Ticket That Was Raised, If Any
     * @return string
     */
    private function body(string $name, string $email, string $subject, string $message, ?array $ticket): string
    {
        $safe = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        $html = '<p><strong>' . $safe(local('from')) . ':</strong> '
            . $safe($name) . ' &lt;' . $safe($email) . '&gt;</p>'
            . '<p><strong>' . $safe(local('subject')) . ':</strong> ' . $safe($subject) . '</p>';

        if ($ticket !== null) {
            $html .= '<p><strong>' . $safe(local('ticket')) . ':</strong> '
                . $safe((string) $ticket['ticket_number']) . '</p>';
        }

        return $html . '<hr>' . '<p>' . nl2br($safe($message)) . '</p>';
    }
}
