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
use LBM\Service\Mail;
use LBM\Service\Support;
use LBM\Service\KnowledgeBase;

/**
 * The support landing page, and the public contact form.
 *
 * ------------------------------------------------------------------------
 * Why the contact form sends an email instead of opening a ticket
 * ------------------------------------------------------------------------
 * `support_departments` carries `dep_requires_login`, which reads like an
 * invitation to let a stranger raise a ticket. The ticket table cannot hold
 * one: `support_tickets.client_relid` is NOT NULL, so every ticket belongs to
 * an account, and there is no account here.
 *
 * The options were to make that column nullable - a change to a core billing
 * table, reaching every ticket query and every "my tickets" listing, for one
 * public form - or to let an anonymous enquiry be what it actually is: a
 * message to the operator. It queues as email to `app_email`, with the sender's
 * address as reply-to, so answering is a reply in a mail client.
 *
 * A visitor who wants a tracked ticket signs in and uses /panel/tickets/new,
 * which already exists and already knows who they are. `dep_requires_login` is
 * still honoured below - a department marked as needing an account is not
 * offered here - so the column keeps its meaning rather than being ignored.
 *
 * Queued, never sent inline: nothing in this application sends on a web
 * request, and a contact form that blocked on a slow SMTP server would time out
 * in front of the person least willing to wait.
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

        return $this->screen('contact', local('contact_us'), [
            'meta_description' =>  local('contact_meta', app_name()),
            'departments'      =>  $this->publicDepartments(),
        ]);
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Validate And Queue a Contact Enquiry
     * @return ?string
     */
    private function send(): ?string
    {
        $input = Request::inputs();

        $ok = $this->require([
            'name'    =>  local('name_required'),
            'email'   =>  local('email_required'),
            'subject' =>  local('subject_required'),
            'message' =>  local('message_required'),
        ], $input);

        $this->requireEmail('email', $input);

        // Re-render rather than redirect, so what they typed is still in the
        // boxes. This is the one place the area does not redirect after a POST,
        // and it is the right trade: losing a paragraph somebody just wrote is
        // worse than a resubmit warning on a form that only sends mail.
        if (!$ok || Request::errors() !== []) {
            return $this->screen('contact', local('contact_us'), [
                'departments' =>  $this->publicDepartments(),
            ]);
        }

        $name = trim((string) ($input['name'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        $subject = trim((string) ($input['subject'] ?? ''));
        $message = trim((string) ($input['message'] ?? ''));

        // Where the operator reads their post. app_email is the address the
        // installer asked for; mail_from is the envelope sender and is often a
        // no-reply, so it is the wrong place to deliver an enquiry.
        $to = (string) option('app_email', '');

        if ($to === '') {
            return $this->done('front.contact', local('contact_unavailable'), false);
        }

        try {
            Mail::queue([
                'to_email'   =>  $to,
                'reply_to'   =>  $email,
                'subject'    =>  local('contact_subject', $subject),
                'body_html'  =>  $this->body($name, $email, $subject, $message),
            ]);
        } catch (Throwable) {
            // The visitor gets a plain apology rather than the exception. What
            // went wrong is a mail configuration problem, which is the
            // operator's to see in the log, not the sender's to decipher.
            return $this->done('front.contact', local('contact_failed'), false);
        }

        return $this->done('front.contact', local('contact_sent'));
    }

    /**
     * Departments a Stranger May Write To
     *
     * Active, not hidden, and not marked as needing an account. The last is
     * what keeps `dep_requires_login` meaningful: a billing department that
     * must know who is asking should not appear on a form that cannot say.
     * @return array<string,string>
     */
    private function publicDepartments(): array
    {
        $choices = [];

        foreach (Support::departments(true) as $department) {
            if (($department['dep_requires_login'] ?? 'yes') === 'yes') {
                continue;
            }

            $choices[(string) $department['dep_name']] = (string) $department['dep_name'];
        }

        return $choices;
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
     * @return string
     */
    private function body(string $name, string $email, string $subject, string $message): string
    {
        $safe = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        return '<p><strong>' . $safe(local('from')) . ':</strong> '
            . $safe($name) . ' &lt;' . $safe($email) . '&gt;</p>'
            . '<p><strong>' . $safe(local('subject')) . ':</strong> ' . $safe($subject) . '</p>'
            . '<hr>'
            . '<p>' . nl2br($safe($message)) . '</p>';
    }
}
