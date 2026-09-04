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
use LBM\Service\ClientService;
use LBM\Service\Dunning;
use LBM\Service\Product;
use LBM\Service\Server;
use LBM\Service\Termination;

/**
 * What clients actually own, and whether it is switched on.
 *
 * This screen exists because of what runs behind it. Cron can now suspend a
 * service for non-payment and bring it back when the invoice settles, and a
 * feature that silently switches off a paying customer's hosting with nowhere
 * to go and look at it is not a feature - it is an incident waiting for a phone
 * call. So the listing answers the two questions an operator has when that call
 * comes: what is switched off, and why.
 *
 * ---------------------------------------------------------------------------
 * DELIBERATELY NOT A CRUD SCREEN
 * ---------------------------------------------------------------------------
 * There is no create and no edit. A service is created by provisioning, from an
 * order that was paid for, and a row typed in by hand here would have no order
 * behind it, no invoice items pointing at it and therefore no renewal - it
 * would look right and never bill. What staff can do is the two things the
 * automation does, by hand and with a reason: suspend and restore.
 */
class ServiceController extends AdminController
{
    protected function nav(): string
    {
        return 'services';
    }

    /**
     * The Service List
     * @return string
     */
    public function index(): string
    {
        $page = ClientService::browseWithClients(
            $this->conditions(['status' => 'status_relid']),
            $this->search()
        );

        return $this->screen('services', local('services'), [
            'pager'    =>  $page,
            'statuses' =>  ClientService::statuses(),

            // Shown as a banner when it is off, because the whole listing reads
            // differently depending on the answer. "Nothing is suspended" means
            // one thing when the sweep is running and quite another when it has
            // never run at all.
            'dunning'  =>  [
                'enabled' =>  Dunning::enabled(),
                'days'    =>  Dunning::graceDays(),
            ],
        ]);
    }

    /**
     * One Service
     * @param string $service Service Uid
     * @return string
     */
    public function show(string $service): string
    {
        $row = $this->record(ClientService::find($service), 'service');

        $serviceId = (int) $row['service_id'];

        return $this->screen('service', ClientService::label($row), [
            'service'    =>  $row,
            'client'     =>  Client::find((int) $row['client_relid']),
            'product'    =>  Product::find((int) $row['product_relid']),
            'server'     =>  $row['server_relid']
                ? Server::find((int) $row['server_relid'])
                : null,

            // The invoice holding it down, if there is one. This is the answer
            // to "why is this off", and it is a link rather than a number so
            // the next click is the one staff actually want.
            'delinquent' =>  Dunning::delinquent($serviceId),
            'automatic'  =>  Dunning::isSuspendedByUs($row),
            'dunning'    =>  [
                'enabled' =>  Dunning::enabled(),
                'days'    =>  Dunning::graceDays(),
            ],
            'held'       =>  Dunning::onHold($row),
            'failure'    =>  Dunning::lastFailure($row),

            // When it is due to end, if it is. A date in the past reads as not
            // scheduled - see Termination::scheduledFor() - so the screen never
            // offers to call off a cancellation that is about to fire anyway.
            'ends_at'    =>  Termination::scheduledFor($row),
            'finished'   =>  ClientService::isFinished($row),
            'terminated' =>  (int) $row['status_relid'] === (int) ClientService::statusId('terminated'),
            'retain'     =>  Termination::retainDays(),

            // Decided here rather than in Twig. The template would otherwise
            // have to compare a status id against a lookup table, which is the
            // kind of thing that reads fine and is wrong the day somebody adds
            // a status.
            'suspended'  =>  (int) $row['status_relid'] === (int) ClientService::statusId('suspended'),
        ]);
    }

    /**
     * Switch a Service Off By Hand
     * @param string $service Service Uid
     * @return ?string
     */
    public function suspend(string $service): ?string
    {
        $row = $this->record(ClientService::find($service), 'service');

        $reason = trim((string) Request::input('reason', ''));

        if ($reason === '') {
            return $this->done(
                'staff.service',
                local('service_reason_required'),
                false,
                ['service' => $row['uid']]
            );
        }

        // BY_STAFF, which is what keeps cron's hands off it: the restore sweep
        // only ever brings back what the sweep itself suspended, so a service
        // switched off here for abuse does not come back because an unrelated
        // invoice got settled. The literal comes from the action rather than
        // being retyped, since a relay forwards methods and not constants.
        $result = Dunning::suspendService($row, $reason, 'staff');

        if (!$result['success']) {
            return $this->done(
                'staff.service',
                local('service_suspend_failed', $result['message']),
                false,
                ['service' => $row['uid']]
            );
        }

        $this->log('service.suspended', 'Suspended service #' . (int) $row['service_id'] . ': ' . $reason);

        return $this->done('staff.service', local('service_suspended'), true, ['service' => $row['uid']]);
    }

    /**
     * Switch a Service Back On By Hand
     * @param string $service Service Uid
     * @return ?string
     */
    public function unsuspend(string $service): ?string
    {
        $row = $this->record(ClientService::find($service), 'service');

        // Held, so the sweep leaves it alone for another grace period. Without
        // that, an operator giving a customer until Friday would watch cron
        // switch it off again inside five minutes - and the operator, not the
        // software, is who the customer would blame.
        $result = Dunning::restoreService($row, true);

        if (!$result['success']) {
            return $this->done(
                'staff.service',
                local('service_restore_failed', $result['message']),
                false,
                ['service' => $row['uid']]
            );
        }

        $this->log('service.restored', 'Restored service #' . (int) $row['service_id'] . '.');

        return $this->done('staff.service', local('service_restored'), true, ['service' => $row['uid']]);
    }

    /**
     * End The Billing
     *
     * Cancelling does not destroy anything - the account stays on the server
     * with the customer's data in it. `terminate()` is the button for that, and
     * it is deliberately a different button behind a different permission.
     * @param string $service Service Uid
     * @return ?string
     */
    public function cancel(string $service): ?string
    {
        $row = $this->record(ClientService::find($service), 'service');

        $when = (string) Request::input('when', 'end_of_term');
        $reason = trim((string) Request::input('reason', ''));

        if (!in_array($when, ['immediately', 'end_of_term'], true)) {
            $when = 'end_of_term';
        }

        $result = Termination::schedule($row, $when, $reason);

        if (!$result['success']) {
            return $this->done(
                'staff.service',
                local('service_cancel_failed', $result['message']),
                false,
                ['service' => $row['uid']]
            );
        }

        $this->log(
            'service.cancelled',
            'Cancelled service #' . (int) $row['service_id'] . ' (' . $when . ')'
                . ($reason !== '' ? ': ' . $reason : '.')
        );

        return $this->done(
            'staff.service',
            local($when === 'immediately' ? 'service_cancelled' : 'service_cancel_scheduled'),
            true,
            ['service' => $row['uid']]
        );
    }

    /**
     * Call Off a Cancellation That Has Not Fired Yet
     * @param string $service Service Uid
     * @return ?string
     */
    public function uncancel(string $service): ?string
    {
        $row = $this->record(ClientService::find($service), 'service');

        $result = Termination::unschedule($row);

        if (!$result['success']) {
            return $this->done(
                'staff.service',
                $result['message'],
                false,
                ['service' => $row['uid']]
            );
        }

        $this->log(
            'service.cancellation_cancelled',
            'Called off the scheduled cancellation of service #' . (int) $row['service_id'] . '.'
        );

        return $this->done('staff.service', local('service_uncancelled'), true, ['service' => $row['uid']]);
    }

    /**
     * Destroy The Account
     *
     * The only irreversible action in the admin panel that reaches somebody
     * else's data, so it asks for the reason in writing and is gated on
     * order.delete rather than order.update.
     * @param string $service Service Uid
     * @return ?string
     */
    public function terminate(string $service): ?string
    {
        $row = $this->record(ClientService::find($service), 'service');

        $reason = trim((string) Request::input('reason', ''));

        // Typed, not ticked. A checkbox is muscle memory by the third time;
        // typing the domain is the last moment somebody realises they have the
        // wrong service open, and this is the one action with no way back.
        $typed = trim((string) Request::input('confirm', ''));
        $expected = trim((string) ($row['domain'] ?? '')) ?: (string) $row['uid'];

        if (strcasecmp($typed, $expected) !== 0) {
            return $this->done(
                'staff.service',
                local('service_terminate_confirm', $expected),
                false,
                ['service' => $row['uid']]
            );
        }

        $result = Termination::terminate($row, $reason);

        if (!$result['success']) {
            return $this->done(
                'staff.service',
                local('service_terminate_failed', $result['message']),
                false,
                ['service' => $row['uid']]
            );
        }

        $this->log(
            'service.terminated',
            'Terminated service #' . (int) $row['service_id'] . ($reason !== '' ? ': ' . $reason : '.')
        );

        return $this->done('staff.service', local('service_terminated'), true, ['service' => $row['uid']]);
    }
}
