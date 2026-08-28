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
use LBM\Model\EmailQueueModel;
use LBM\Mail\Templater;
use LBM\Service\Mailer;
use LBM\Service\Status;

/**
 * Outgoing email: queued here, sent by LBM\Job\SendEmailJob.
 *
 * Nothing sends inline on a web request. An SMTP handshake against a server
 * that is slow or down would hold the request open for the connection timeout,
 * so a customer clicking "pay invoice" would sit watching a spinner because the
 * receipt could not be delivered. Queueing makes the send somebody else's
 * problem and the retry automatic.
 *
 * Every message is stored fully rendered - subject, HTML and plain text - rather
 * than as a template id plus variables. A receipt sent last March should still
 * show what was actually sent, not what that template says today.
 */
class Mail extends Action
{
    /** @var string Status Lookup Table */
    public const STATUSES = 'email_queue_statuses';

    /** @var int How Many Times a Message Is Retried Before Being Given Up On */
    public const MAX_ATTEMPTS = 3;

    public function model(): Model
    {
        return new EmailQueueModel();
    }

    protected function searchable(): array
    {
        return ['to_email', 'subject'];
    }

    protected function createdColumn(): ?string
    {
        return 'queue_created_at';
    }

    ####################################################################################
    /*=================================== QUEUEING ===================================*/
    ####################################################################################

    /**
     * Render a Template And Queue It
     * @param string $slug Template Slug
     * @param string $to Recipient Address
     * @param array<string,mixed> $variables Placeholder Values
     * @param ?int $clientId Client ID This Message Concerns
     * @return int New Queue ID
     * @throws RuntimeException
     */
    public function queueTemplate(string $slug, string $to, array $variables = [], ?int $clientId = null): int
    {
        $rendered = (new Templater())->render($slug, $variables);

        return $this->queue([
            'to_email'       =>  $to,
            'subject'        =>  $rendered['subject'],
            'body_html'      =>  $rendered['html'],
            'body_plain'     =>  $rendered['plain'],
            'template_relid' =>  isset($rendered['template']['et_id'])
                ? (int) $rendered['template']['et_id']
                : null,
            'client_relid'   =>  $clientId,
        ]);
    }

    /**
     * Queue An Already-Rendered Message
     * @param array $message Message Fields
     * @return int New Queue ID
     * @throws RuntimeException
     */
    public function queue(array $message): int
    {
        $to = trim((string) ($message['to_email'] ?? ''));

        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException("[{$to}] is not a usable email address.");
        }

        $html = (string) ($message['body_html'] ?? '');

        return $this->create([
            'client_relid'   =>  $message['client_relid'] ?? null,
            'template_relid' =>  isset($message['template_relid'])
                ? (int) $message['template_relid']
                : null,
            'to_email'       =>  $to,
            'from_name'      =>  (string) ($message['from_name'] ?? option('mail_from_name', '')),
            'from_email'     =>  (string) ($message['from_email'] ?? option('mail_from', '')),
            'reply_to'       =>  $message['reply_to'] ?? null,
            'subject'        =>  (string) ($message['subject'] ?? ''),
            'body_html'      =>  $html,
            'body_plain'     =>  (string) ($message['body_plain'] ?? strip_tags($html)),
            'status_relid'   =>  Status::idOf(self::STATUSES, 'queued') ?? 1,
            'attempts'       =>  0,
        ]);
    }

    /**
     * Queue The Test Message From The Mail Settings Screen
     *
     * Queued rather than sent directly, deliberately: the point of the test is
     * to prove the path a real notification takes, and a test that bypassed the
     * queue would pass while every actual email sat unsent.
     * @param string $to Recipient Address
     * @return int New Queue ID
     */
    public function queueTest(string $to): int
    {
        $rendered = (new Templater())->renderText(
            'Test message from {{app_name}}',
            '<p>This is a test message from <strong>{{app_name}}</strong>.</p>'
            . '<p>If you are reading it, the mail settings are working.</p>'
            . '<p>Sent {{date}}.</p>'
        );

        return $this->queue([
            'to_email'   =>  $to,
            'subject'    =>  $rendered['subject'],
            'body_html'  =>  $rendered['html'],
            'body_plain' =>  $rendered['plain'],
        ]);
    }

    ####################################################################################
    /*==================================== SENDING ===================================*/
    ####################################################################################

    /**
     * Messages Waiting To Go Out
     * @param int $limit How Many To Take
     * @return array
     */
    public function pending(int $limit = 25): array
    {
        $queued = Status::idOf(self::STATUSES, 'queued');
        $failed = Status::idOf(self::STATUSES, 'failed');

        $model = $this->model();

        // Failed messages are retried until MAX_ATTEMPTS, which is what makes a
        // transient SMTP outage recover on its own rather than needing somebody
        // to notice and re-send by hand.
        $statuses = array_values(array_filter([$queued, $failed]));

        if ($statuses !== []) {
            $model->whereIn('status_relid', $statuses);
        }

        return $model->where(['attempts' => self::MAX_ATTEMPTS], '<')
            ->order($model->id, self::ASC)
            ->limit($limit)
            ->get();
    }

    /**
     * Send One Queued Message
     *
     * The attempt counter is incremented before the send, not after. A send that
     * kills the worker outright - a fatal in the SMTP library, a killed process -
     * would otherwise leave the row exactly as it was and the next worker would
     * try the same poisonous message forever.
     * @param array $row Queue Row
     * @return bool Whether it went out
     */
    public function send(array $row): bool
    {
        $id = (int) $row['queue_id'];

        $this->update($id, ['attempts' => ((int) ($row['attempts'] ?? 0)) + 1]);

        try {
            $mailer = Mailer::mailer();
            $mailer->reset();

            $from = (string) ($row['from_email'] ?? '');

            if ($from !== '') {
                $mailer->from($from, (string) ($row['from_name'] ?? ''));
            }

            $replyTo = (string) ($row['reply_to'] ?? '');

            if ($replyTo !== '') {
                $mailer->replyTo($replyTo);
            }

            $sent = $mailer->to((string) $row['to_email'])
                ->subject((string) $row['subject'])
                ->html((string) $row['body_html'], (string) ($row['body_plain'] ?? ''))
                ->send();

            if ($sent) {
                $this->markSent($id);

                return true;
            }

            $this->markFailed($id, $mailer->lastError());

            return false;
        } catch (Throwable $e) {
            $this->markFailed($id, $e->getMessage());

            return false;
        }
    }

    /**
     * Mark a Message Delivered
     * @param int $queueId Queue ID
     * @return int Affected rows
     */
    public function markSent(int $queueId): int
    {
        $data = [
            'sent_at'       =>  $this->now(),
            'error_message' =>  null,
        ];

        $status = Status::idOf(self::STATUSES, 'sent') ?? Status::idOf(self::STATUSES, 'completed');

        if ($status !== null) {
            $data['status_relid'] = $status;
        }

        return $this->update($queueId, $data);
    }

    /**
     * Mark a Message Failed, With Why
     * @param int $queueId Queue ID
     * @param string $error Error Message
     * @return int Affected rows
     */
    public function markFailed(int $queueId, string $error): int
    {
        $data = ['error_message' => $error !== '' ? $error : 'The mailer gave no reason.'];

        $status = Status::idOf(self::STATUSES, 'failed');

        if ($status !== null) {
            $data['status_relid'] = $status;
        }

        return $this->update($queueId, $data);
    }

    /**
     * Put a Failed Message Back In The Queue
     *
     * Resets the attempt counter, because a person choosing to retry has usually
     * just fixed whatever was wrong.
     * @param int|string $key Queue ID Or Uid
     * @return int Affected rows
     */
    public function retry(int|string $key): int
    {
        $data = ['attempts' => 0, 'error_message' => null];

        $status = Status::idOf(self::STATUSES, 'queued');

        if ($status !== null) {
            $data['status_relid'] = $status;
        }

        return $this->update($key, $data);
    }

    /**
     * Delete Sent Messages Older Than a Cutoff
     *
     * The queue holds a full copy of every message body, so it is the fastest
     * growing table in the application. Only delivered messages are pruned -
     * a failed one is still evidence of something that needs looking at.
     * @param int $days Age In Days
     * @return int Deleted rows
     */
    public function prune(int $days): int
    {
        if ($days < 1) {
            return 0;
        }

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        return $this->model()
            ->notNull('sent_at')
            ->where(['sent_at' => $cutoff], '<')
            ->delete();
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
}
