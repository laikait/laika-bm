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
use LBM\Service\Gateway;
use LBM\Service\GatewayCallback;

/**
 * The ways this installation can take money.
 *
 * ------------------------------------------------------------------------
 * The screen is the gateway modules - Phase 46
 * ------------------------------------------------------------------------
 * Laid out like the Modules page was: each gateway module with its state,
 * Enable or Disable, and Configure once it is switched on and loaded. Enabling
 * one creates its payment_gateways row the first time, and disabling stops
 * offering it; ModuleController does both, because the switch is the same
 * button on every kind's screen. The "Set up" form, the separate offer switch
 * and Delete are gone - a gateway row is kept for good once made, because
 * transactions point at it.
 *
 * Each row still says the thing an operator most needs to know: whether
 * customers are being offered it, under what name, and - when its driver will
 * not build - why not. A gateway that looks ready and silently is not is the
 * worst screen available.
 *
 * ------------------------------------------------------------------------
 * No new permission group
 * ------------------------------------------------------------------------
 * Permission::GROUPS is granted to a role only when the role is CREATED, so a
 * new group is invisible on every installation that already exists and the fix
 * is a checkbox nobody knows to tick. Gateways are settings, so this screen is
 * behind settings.read and a gateway's Configure page behind settings.*, while
 * the switch - a module switch like every other - is module.update.
 */
class GatewayController extends AdminController
{
    /**
     * @return string Which nav entry is active
     */
    protected function nav(): string
    {
        return 'settings';
    }

    ####################################################################################
    /*=================================== SCREENS ====================================*/
    ####################################################################################

    /**
     * Every Gateway Module, And What Customers Are Offered
     * @return string
     */
    public function index(): string
    {
        $modules = $this->moduleList('gateways');

        foreach ($modules as $uid => $module) {
            $row = Gateway::forModule((string) $uid);

            if ($row === null) {
                continue;
            }

            $problem = !empty($module['loaded']) ? Gateway::problemWith($row) : null;
            $offered = ($row['is_active'] ?? 'no') === 'yes';

            // Phase 48: which kind of gateway - read from its class, so only for
            // a module that has loaded.
            $modules[$uid]['kind'] = !empty($module['loaded']) ? Gateway::kindOf($row) : null;

            if ($problem !== null) {
                $modules[$uid]['note'] = local('gateway_problem', $problem);
                $modules[$uid]['note_tone'] = 'bad';
            } else {
                $modules[$uid]['note'] = local(
                    $offered ? 'gateway_offered_as' : 'gateway_not_offered_as',
                    (string) $row['display_name']
                );
            }
        }

        return $this->screen('gateways', local('payment_gateways'), [
            'modules' =>  $modules,
            'path'    =>  'modules/gateways',
        ]);
    }

    /**
     * What Gateways Have Sent Us
     *
     * The diagnostic screen for everything Phase 22.3 built. A webhook that is
     * not arriving and a webhook that is arriving and being refused look
     * identical from the outside - unpaid invoices - and they need opposite
     * fixes, so the operator has to be able to see which is happening.
     *
     * Unverified callbacks are listed too, deliberately. One of those is
     * somebody attempting to mark an invoice paid, and it is the single most
     * useful thing on this page.
     * @return string
     */
    public function callbacks(): string
    {
        $gateways = [];

        foreach (Gateway::all([], 'ASC', 'display_name') as $row) {
            $gateways[(int) $row['gateway_id']] = (string) $row['display_name'];
        }

        $where = [];
        $outcome = trim((string) Request::input('outcome', ''));

        // Filtered against the action's own list rather than passed through:
        // `outcome` is an enum column, and a value outside it is a query that
        // matches nothing while looking like a working filter.
        if (in_array($outcome, GatewayCallback::outcomes(), true)) {
            $where['outcome'] = $outcome;
        }

        return $this->screen('gateway-callbacks', local('gateway_callbacks'), [
            'callbacks' =>  GatewayCallback::browseRecent($where),
            'gateways'  =>  $gateways,
            'outcomes'  =>  GatewayCallback::outcomes(),
            'outcome'   =>  $outcome,
        ]);
    }
}
