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

use LBM\Service\ClientService;
use LBM\Service\Domain;
use LBM\Service\Invoice;
use LBM\Service\Support;

/**
 * What a client sees on the way in.
 *
 * Deliberately short. Four things a person actually wants to know - what do I
 * owe, what do I have, is anything expiring, has support answered - and a way
 * to get to each. Not a wall of charts: the operator has a dashboard for the
 * business, and this is somebody checking on their own account.
 *
 * Every panel is gated the same way the sidebar is, so a sub-login who was not
 * given invoices does not see the amount outstanding on a page they were told
 * they may open.
 */
class DashboardController extends ClientController
{
    /** @var int How Far Ahead "Renewing Soon" Looks */
    private const HORIZON = 30;

    /** @var int How Many Rows Each Panel Shows */
    private const ROWS = 5;

    protected function nav(): string
    {
        return 'dashboard';
    }

    /**
     * The Dashboard
     * @return string
     */
    public function index(): string
    {
        $clientId = $this->owner();

        $invoices = client_can('invoice.read');
        $services = client_can('service.read');
        $domains  = client_can('domain.read');
        $tickets  = client_can('ticket.read');

        return $this->screen('dashboard', 'Dashboard', [
            'may'          =>  [
                'invoices' =>  $invoices,
                'services' =>  $services,
                'domains'  =>  $domains,
                'tickets'  =>  $tickets,
            ],

            'outstanding'  =>  $invoices ? Invoice::outstandingFor($clientId) : null,
            'unpaid'       =>  $invoices ? $this->unpaid($clientId) : [],

            'active'       =>  $services ? ClientService::activeCount($clientId) : 0,
            'renewing'     =>  $services
                ? array_slice(ClientService::dueWithin(self::HORIZON, $clientId), 0, self::ROWS)
                : [],

            'expiring'     =>  $domains ? $this->expiring($clientId) : [],

            'open_tickets' =>  $tickets ? Support::openCount($clientId) : 0,
            'recent'       =>  $tickets
                ? array_slice(Support::browseForClient($clientId, self::ROWS)['rows'], 0, self::ROWS)
                : [],

            'horizon'      =>  self::HORIZON,
        ]);
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * The Invoices Still Owed Something, With What Is Left On Each
     *
     * The balance is not a column - it is the total less what has been paid and
     * credited - so it is worked out here rather than in the view, where the
     * same arithmetic would have to be got right a second time.
     * @param int $clientId Client ID
     * @return array
     */
    private function unpaid(int $clientId): array
    {
        $settled = Invoice::settledStatusIds();
        $rows = [];

        foreach (Invoice::browseForClient($clientId, [], self::ROWS)['rows'] as $row) {
            if (in_array((int) $row['status_relid'], $settled, true)) {
                continue;
            }

            $row['balance'] = Invoice::balance($row);
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Domains Expiring Soon
     *
     * Filtered from the client's own domains rather than through the global
     * expiringWithin(), which is not scoped to an owner.
     * @param int $clientId Client ID
     * @return array
     */
    private function expiring(int $clientId): array
    {
        $rows = [];

        foreach (Domain::browseForClient($clientId, self::ROWS)['rows'] as $row) {
            $days = Domain::daysToExpiry($row);

            if ($days === null || $days > self::HORIZON) {
                continue;
            }

            $row['days_left'] = $days;
            $rows[] = $row;
        }

        return $rows;
    }
}
