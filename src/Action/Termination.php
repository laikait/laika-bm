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
use LBM\Model\ClientServiceModel;
use LBM\Service\Status;
use LBM\Support\ServesServices;

/**
 * The end of a service's life.
 *
 * Until Phase 24 a service could be born, switched off and switched back on,
 * and never die. `ServerInterface::terminate()` was declared in Phase 9 and had
 * no caller anywhere; `ClientService::setStatus()` was only ever called with
 * `active` and `suspended`, so `FINISHED`, `isFinished()`, `finishedStatusIds()`
 * and `termination_date` were all unreachable code.
 *
 * That was not a cosmetic gap. `InvoiceGenerateJob` bills every service whose
 * status is `active`, so a customer who cancelled kept being invoiced for ever -
 * and once Phase 23 shipped, kept being sent "your service has been suspended
 * for non-payment" about a service they had asked you to cancel weeks earlier.
 *
 * ---------------------------------------------------------------------------
 * CANCELLING AND TERMINATING ARE DIFFERENT ACTS
 * ---------------------------------------------------------------------------
 * **Cancel** ends the commercial relationship: billing stops, the status
 * becomes `cancelled`, and no module is called. The account is still on the
 * server, with the customer's data in it.
 *
 * **Terminate** destroys it. The module's `terminate()` runs, the status
 * becomes `terminated`, and the server's account count is recomputed.
 *
 * Folding them into one verb is the mistake worth avoiding here, because the
 * two have opposite failure modes: a cancellation that did not stop the billing
 * is an invoice somebody has to refund, and a termination that ran too early is
 * a customer's data that no longer exists.
 *
 * ---------------------------------------------------------------------------
 * AUTOMATIC TERMINATION IS OFF BY DEFAULT
 * ---------------------------------------------------------------------------
 * `terminate_cancelled_days` defaults to 0, meaning never. Phase 23's reasoning
 * applies with more force: switching this on with a number in it destroys real
 * accounts on the first cron tick, and an install that has been running for a
 * year has cancelled services in it that nobody intended as a demolition order.
 * The operator sets the number, having read what it does.
 *
 * A SCHEDULED cancellation is different and carries no switch, because somebody
 * explicitly asked for it: honouring a request is not a policy.
 */
class Termination extends Action
{
    use ServesServices;

    /** @var string Service Status Lookup Table */
    public const SERVICE_STATUSES = 'client_service_statuses';

    /** @var int How Many Times a Failing Module Call Is Retried */
    public const MAX_ATTEMPTS = 3;

    /** @var int Most Services One Cron Tick Will Act On, Per Direction */
    public const BATCH = 20;

    /** @var string[] When a Cancellation May Take Effect */
    public const WHEN = ['immediately', 'end_of_term'];

    public function model(): Model
    {
        return new ClientServiceModel();
    }

    ####################################################################################
    /*=================================== THE SWEEP ==================================*/
    ####################################################################################

    /**
     * The Cron Entry Point
     *
     * Cancellations first, then terminations - in that order because a
     * cancellation that fires today may become a termination in thirty days,
     * and doing it the other way round would mean the newest cancellation
     * always waited an extra tick for no reason.
     * @return string What happened, for the cron log
     */
    public function run(): string
    {
        $cancelled = $this->runCancellations();
        $terminated = $this->runTerminations();

        return $cancelled . ' cancelled, ' . $terminated['done'] . ' terminated'
            . ($terminated['failed'] > 0 ? ', ' . $terminated['failed'] . ' failed' : '')
            . ($this->retainDays() > 0 ? '' : ' (auto-terminate off)');
    }

    /**
     * How Long a Cancelled Service Is Kept Before It Is Destroyed
     * @return int Days. Zero means never
     */
    public function retainDays(): int
    {
        $days = option_int('terminate_cancelled_days', 0);

        return $days > 0 ? $days : 0;
    }

    /**
     * Fire Every Cancellation Whose Date Has Arrived
     * @return int How many were cancelled
     */
    public function runCancellations(): int
    {
        $done = 0;

        foreach ($this->dueForCancellation() as $service) {
            if ($this->cancelNow($service, (string) ($service['cancel_reason'] ?? ''))['success']) {
                $done++;
            }
        }

        return $done;
    }

    /**
     * Destroy Every Cancelled Service Past Its Retention Window
     * @return array{done:int,failed:int}
     */
    public function runTerminations(): array
    {
        $done = 0;
        $failed = 0;

        foreach ($this->dueForTermination() as $service) {
            $result = $this->terminate($service, 'Retention period expired.');

            $result['success'] ? $done++ : $failed++;
        }

        return ['done' => $done, 'failed' => $failed];
    }

    /**
     * Services Whose Scheduled Cancellation Has Come Round
     *
     * An indexed column comparison, not a scan-and-unserialise. This is why the
     * date is a column and not a key in `module_data` - see
     * src/Migration/M202609050100AddServiceCancellation.php.
     * @return array
     */
    public function dueForCancellation(): array
    {
        $model = $this->model();

        $model->notNull('cancel_at')->where(['cancel_at' => $this->now()], '<=');

        $finished = (new ClientService())->finishedStatusIds();

        if ($finished !== []) {
            $model->whereNotIn('status_relid', $finished);
        }

        return $model->order($model->id, self::ASC)->limit(self::BATCH)->get();
    }

    /**
     * Cancelled Services Old Enough To Destroy
     *
     * Empty whenever the operator has not set a retention period, which is the
     * shipped default. `termination_date` is what setStatus() stamped when the
     * service was cancelled, so the clock starts at the cancellation and not at
     * the request.
     * @return array
     */
    public function dueForTermination(): array
    {
        $days = $this->retainDays();

        if ($days === 0) {
            return [];
        }

        $cancelled = Status::idOf(self::SERVICE_STATUSES, 'cancelled');

        if ($cancelled === null) {
            return [];
        }

        $cutoff = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));

        $model = $this->model();

        $rows = $model->where(['status_relid' => $cancelled])
            ->notNull('termination_date')
            ->where(['termination_date' => $cutoff], '<=')
            ->order($model->id, self::ASC)
            ->limit(self::BATCH * 5)
            ->get();

        $out = [];

        foreach ($rows as $row) {
            if ($this->attempts($row, 'terminate') >= self::MAX_ATTEMPTS) {
                continue;
            }

            $out[] = $row;

            if (count($out) >= self::BATCH) {
                break;
            }
        }

        return $out;
    }

    ####################################################################################
    /*=================================== THE ACTS ===================================*/
    ####################################################################################

    /**
     * Arrange For a Service To End
     *
     * `immediately` cancels here and now. `end_of_term` writes the date the
     * customer has already paid up to and leaves cron to fire it, which is what
     * "at the end of the current term" means to somebody who has paid for a
     * month and is three days into it.
     * @param array $service Service Row
     * @param string $when One of self::WHEN
     * @param string $reason Why. Kept on the row and shown to staff
     * @return array{success:bool,message:string,at:?string}
     * @throws RuntimeException
     */
    public function schedule(array $service, string $when, string $reason = ''): array
    {
        if (!in_array($when, self::WHEN, true)) {
            throw new RuntimeException('A cancellation is either immediately or end_of_term, not ' . $when . '.');
        }

        $services = new ClientService();

        if ($services->isFinished($service)) {
            return ['success' => false, 'message' => 'That service has already ended.', 'at' => null];
        }

        if ($when === 'immediately') {
            $result = $this->cancelNow($service, $reason);

            return $result + ['at' => $this->now()];
        }

        // The end of the term is the date they have paid up to. A service with
        // no next due date is a one-off that renews nothing, so there is no
        // future term to wait for and it ends now.
        $due = trim((string) ($service['next_due_date'] ?? ''));

        if ($due === '' || strtotime($due) <= strtotime($this->now())) {
            $result = $this->cancelNow($service, $reason);

            return $result + ['at' => $this->now()];
        }

        $services->modify((int) $service['service_id'], [
            'cancel_at'     =>  $due,
            'cancel_reason' =>  mb_substr(trim($reason), 0, 255),
        ]);

        (new Activity())->record(
            'service.cancellation_scheduled',
            'Service #' . (int) $service['service_id'] . ' will be cancelled on ' . $due . '.'
        );

        $this->notify($service, 'service-cancelled', [
            'ends_on' =>  format_date($due),
            'reason'  =>  trim($reason),
        ]);

        return ['success' => true, 'message' => 'Scheduled.', 'at' => $due];
    }

    /**
     * Call Off a Cancellation That Has Not Fired Yet
     *
     * The customer changed their mind, which happens far more often than the
     * cancellation screens suggest. Only reachable while the date is still in
     * the future - once it has fired the service is `cancelled`, and bringing
     * that back is a new order rather than an undo.
     * @param array $service Service Row
     * @return array{success:bool,message:string}
     */
    public function unschedule(array $service): array
    {
        if (!$this->isScheduled($service)) {
            return ['success' => false, 'message' => 'No cancellation is scheduled for that service.'];
        }

        (new ClientService())->modify((int) $service['service_id'], [
            'cancel_at'     =>  null,
            'cancel_reason' =>  null,
        ]);

        (new Activity())->record(
            'service.cancellation_cancelled',
            'The scheduled cancellation of service #' . (int) $service['service_id'] . ' was called off.'
        );

        return ['success' => true, 'message' => 'The cancellation has been called off.'];
    }

    /**
     * End The Commercial Relationship Now
     *
     * No module call, deliberately. Cancelling means the billing stops, not that
     * the account is destroyed - the customer's data is still there, and an
     * operator who wants it gone presses the other button. `setStatus()` stamps
     * `termination_date`, which is what the retention window is counted from.
     * @param array $service Service Row
     * @param string $reason Why
     * @return array{success:bool,message:string}
     */
    public function cancelNow(array $service, string $reason = ''): array
    {
        $serviceId = (int) ($service['service_id'] ?? 0);
        $services = new ClientService();

        if ($services->isFinished($service)) {
            return ['success' => false, 'message' => 'That service has already ended.'];
        }

        $services->setStatus($serviceId, 'cancelled');

        // Cleared, because it has happened. A date left in the past would make
        // the sweep pick the row up on every tick for ever, and make the screen
        // say a cancellation is scheduled for a service that already ended.
        $services->modify($serviceId, [
            'cancel_at'     =>  null,
            'cancel_reason' =>  mb_substr(trim($reason), 0, 255) ?: null,
        ]);

        (new Activity())->record(
            'service.cancelled',
            'Cancelled service #' . $serviceId . ($reason !== '' ? ': ' . $reason : '.')
        );

        $this->recountFor($service);

        $this->notify($service, 'service-cancelled', [
            'ends_on' =>  format_date($this->now()),
            'reason'  =>  trim($reason),
        ]);

        return ['success' => true, 'message' => 'Cancelled.'];
    }

    /**
     * Destroy The Account
     *
     * The one that cannot be undone, and the only place in the product that
     * calls `ServerInterface::terminate()`.
     *
     * The module call comes first and the status second, exactly as in Dunning:
     * a service recorded as terminated whose account is still running is an
     * account nobody will ever look at again, still consuming a slot on a server
     * whose capacity says it is free.
     * @param array $service Service Row
     * @param string $reason Why
     * @return array{success:bool,message:string}
     */
    public function terminate(array $service, string $reason = ''): array
    {
        $serviceId = (int) ($service['service_id'] ?? 0);
        $services = new ClientService();

        $terminated = Status::idOf(self::SERVICE_STATUSES, 'terminated');

        if ($terminated !== null && (int) ($service['status_relid'] ?? 0) === $terminated) {
            return ['success' => false, 'message' => 'That service has already been terminated.'];
        }

        $result = $this->call($service, $reason);

        if (!$result['success']) {
            return $this->failed($service, 'terminate', $result['message']);
        }

        $this->remember($service, [
            'terminated_at'      =>  $this->now(),
            'terminate_attempts' =>  0,
            'last_error'         =>  null,
        ]);

        $services->setStatus($serviceId, 'terminated');

        $services->modify($serviceId, [
            'cancel_at'     =>  null,
            'cancel_reason' =>  mb_substr(trim($reason), 0, 255) ?: null,
        ]);

        (new Activity())->record(
            'service.terminated',
            'Terminated service #' . $serviceId . ($reason !== '' ? ': ' . $reason : '.')
        );

        $this->recountFor($service);

        $this->notify($service, 'service-terminated', ['reason' => trim($reason)]);

        return ['success' => true, 'message' => 'Terminated.'];
    }

    ####################################################################################
    /*================================== THE VERDICT =================================*/
    ####################################################################################

    /**
     * Whether a Cancellation Is Waiting To Fire
     * @param array $service Service Row
     * @return bool
     */
    public function isScheduled(array $service): bool
    {
        return $this->scheduledFor($service) !== null;
    }

    /**
     * When a Service Is Due To End, If It Is
     *
     * A date in the past reads as not scheduled: it means the sweep has not run
     * yet, and the screen should not offer to call off a cancellation that is
     * about to happen anyway.
     * @param array $service Service Row
     * @return ?string
     */
    public function scheduledFor(array $service): ?string
    {
        $at = trim((string) ($service['cancel_at'] ?? ''));

        if ($at === '') {
            return null;
        }

        return (new ClientService())->isFinished($service) ? null : $at;
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Ask The Module To Destroy The Account
     *
     * A service with no server, or a server whose module is missing or switched
     * off, reports SUCCESS with no call made - the same rule Dunning follows.
     * There is genuinely nothing to call, and refusing to record the termination
     * would leave an operator who sets accounts up by hand unable to close one
     * down.
     * @param array $service Service Row
     * @param string $reason Why
     * @return array{success:bool,message:string}
     */
    private function call(array $service, string $reason): array
    {
        $server = $this->serverRow((int) ($service['server_relid'] ?? 0));

        if ($server === null) {
            return ['success' => true, 'message' => 'No server; recorded without a module call.'];
        }

        $driver = $this->driverFor($server);

        if ($driver === null) {
            return ['success' => true, 'message' => 'No module; recorded without a module call.'];
        }

        try {
            $result = $driver->terminate($service, [
                'server'  =>  $server,
                'client'  =>  (new Client())->find((int) ($service['client_relid'] ?? 0)) ?? [],
                'product' =>  (new Product())->find((int) ($service['product_relid'] ?? 0)) ?? [],
                'options' =>  ['reason' => $reason],
            ]);
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'The module raised an error: ' . $e->getMessage()];
        }

        if (!is_array($result) || ($result['success'] ?? false) !== true) {
            return [
                'success' =>  false,
                'message' =>  trim((string) ($result['message'] ?? '')) ?: 'The module reported a failure.',
            ];
        }

        return ['success' => true, 'message' => (string) ($result['message'] ?? 'Done.')];
    }

    /**
     * Put The Server's Account Count Right
     *
     * Recomputed rather than decremented. See `Server::recount()` - the column
     * had never been written by anything before Phase 24, so an increment would
     * be counting up from a number that is wrong on every install in existence.
     * @param array $service Service Row
     * @return void
     */
    private function recountFor(array $service): void
    {
        $serverId = (int) ($service['server_relid'] ?? 0);

        if ($serverId > 0) {
            (new Server())->recount($serverId);
        }
    }

    /**
     * Record a Failed Module Call
     *
     * The status is deliberately left alone. See terminate().
     * @param array $service Service Row
     * @param string $verb terminate
     * @param string $why What went wrong
     * @return array{success:bool,message:string}
     */
    private function failed(array $service, string $verb, string $why): array
    {
        $attempts = $this->attempts($service, $verb) + 1;

        $this->remember($service, [
            $verb . '_attempts' =>  $attempts,
            'last_error'        =>  mb_substr($why, 0, 500),
            'last_attempt_at'   =>  $this->now(),
        ]);

        (new Activity())->record(
            'service.' . $verb . '_failed',
            'Could not ' . $verb . ' service #' . (int) ($service['service_id'] ?? 0)
                . ' (attempt ' . $attempts . '): ' . $why
        );

        return ['success' => false, 'message' => $why];
    }

    /**
     * Tell The Client
     *
     * Queued, never sent inline, and every failure is logged and swallowed - a
     * mail server that is refusing connections must not stop a cancellation
     * being recorded.
     *
     * client_area is not passed: Templater::withDefaults() supplies it from
     * app_host, which is the only source that works from a cron run.
     * @param array $service Service Row
     * @param string $slug Template Slug
     * @param array $extra Extra Placeholders
     * @return void
     */
    private function notify(array $service, string $slug, array $extra = []): void
    {
        $client = (new Client())->find((int) ($service['client_relid'] ?? 0));

        if (!is_array($client)) {
            return;
        }

        $email = trim((string) ($client['email'] ?? ''));

        if ($email === '') {
            return;
        }

        $product = (new Product())->find((int) ($service['product_relid'] ?? 0));

        try {
            (new Mail())->queueTemplate($slug, $email, $extra + [
                'first_name'   =>  (string) ($client['first_name'] ?? ''),
                'last_name'    =>  (string) ($client['last_name'] ?? ''),
                'service_name' =>  (string) ($product['product_name'] ?? 'your service'),
                'domain'       =>  (string) ($service['domain'] ?? ''),
            ], (int) ($service['client_relid'] ?? 0));
        } catch (Throwable $e) {
            (new Activity())->record(
                'service.notify_failed',
                'Could not queue the ' . $slug . ' message for service #'
                    . (int) ($service['service_id'] ?? 0) . ': ' . $e->getMessage()
            );
        }
    }
}
