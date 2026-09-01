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

use Laika\Service\Request;
use LBM\Service\ClientService;
use LBM\Service\Server;

/**
 * The products a client has.
 *
 * Read-only, with one exception: a client may ask for a service to be cancelled.
 * That request does not change the service - see the note on cancel().
 *
 * The provisioning credential is shown on the detail screen and nowhere else,
 * because it is the one thing here somebody genuinely needs and cannot get any
 * other way. It is fetched by an explicit call rather than being part of the
 * row, so the list screen cannot leak one by rendering a column it did not mean
 * to.
 */
class ServiceController extends ClientController
{
    protected function nav(): string
    {
        return 'services';
    }

    /**
     * The Client's Services
     * @return string
     */
    public function index(): string
    {
        $this->allow('service');

        return $this->screen('services', local('my_services'), [
            'pager'    =>  ClientService::browseForClient(
                $this->owner(),
                $this->conditions(['status' => 'status_relid'])
            ),
            'statuses' =>  ClientService::statuses(),
            'cycles'   =>  ClientService::cycleNames(),
        ]);
    }

    /**
     * One Service
     * @param string $service Service Uid
     * @return string
     */
    public function show(string $service): string
    {
        $this->allow('service');

        $row = $this->mine(
            static fn(int|string $key, int $clientId): ?array => ClientService::forClientKey($key, $clientId),
            $service,
            'service'
        );

        $product = ClientService::product($row);

        return $this->screen('service', ClientService::label($row), [
            'service'    =>  $row,
            'product'    =>  $product,
            'addons'     =>  ClientService::addons((int) $row['service_id']),
            'cycles'     =>  ClientService::cycleNames(),
            'credential' =>  ClientService::credential($row),
            'active'     =>  ClientService::isActive($row),
            'finished'   =>  ClientService::isFinished($row),

            // The hostname only - never the server's own credentials, which are
            // the operator's and have nothing to do with the client.
            'hostname'   =>  $this->hostname($row),
        ]);
    }

    /**
     * Ask For a Service To Be Cancelled
     *
     * This does not cancel the service, and the screen says so. Cancelling is
     * the operator's decision - there may be a notice period, an unpaid invoice
     * or data to hand back - and a client who could set the status himself
     * would stop his own billing the moment he clicked.
     *
     * What happens instead is a support ticket against the service, so the
     * request lands in a queue that gets read and the client has a thread to
     * follow rather than a message that vanished.
     * @param string $service Service Uid
     * @return ?string
     */
    public function cancel(string $service): ?string
    {
        $this->allow('service', self::UPDATE);

        $row = $this->mine(
            static fn(int|string $key, int $clientId): ?array => ClientService::forClientKey($key, $clientId),
            $service,
            'service'
        );

        $input = Request::inputs();
        $clientId = $this->owner();

        $reason = trim((string) ($input['reason'] ?? ''));
        $when = ($input['when'] ?? '') === 'immediately' ? 'immediately' : 'end_of_term';

        return $this->attempt(
            function () use ($row, $clientId, $reason, $when): void {
                ClientService::requestCancellation($row, $clientId, $reason, $when);

                $this->log(
                    'service.cancellation.requested',
                    'Asked for ' . ClientService::label($row) . ' to be cancelled ' .
                    ($when === 'immediately' ? 'immediately' : 'at the end of the term') . '.'
                );
            },
            'client.service',
            local('cancellation_raised'),
            ['service' => $row['uid']]
        );
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * The Hostname a Service Sits On
     *
     * Null when it is not on a server this install knows about, which is the
     * normal case for anything not provisioned through a module.
     * @param array $service Service Row
     * @return ?string
     */
    private function hostname(array $service): ?string
    {
        $id = (int) ($service['server_relid'] ?? 0);

        if ($id === 0) {
            return null;
        }

        $server = Server::find($id);

        if ($server === null) {
            return null;
        }

        $hostname = trim((string) ($server['hostname'] ?? ''));

        return $hostname === '' ? null : $hostname;
    }
}
