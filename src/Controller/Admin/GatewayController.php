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

use RuntimeException;
use Laika\Core\Exceptions\HttpException;
use Laika\Service\Request;
use LBM\Service\Gateway;
use LBM\Service\GatewayCallback;

/**
 * Setting up the ways this installation can take money.
 *
 * ------------------------------------------------------------------------
 * Two states, and the screen has to show both
 * ------------------------------------------------------------------------
 * A gateway module on disk is not a gateway an operator can use. It has to be
 * enabled (the modules screen), then configured here, then switched on. Each of
 * those is a separate decision and skipping the distinction produces the worst
 * possible screen: one where a gateway looks ready and silently is not.
 *
 * So this lists two groups - what is configured, and what is installed but not
 * configured yet - and for anything configured it says outright whether its
 * driver actually builds.
 *
 * ------------------------------------------------------------------------
 * No new permission group
 * ------------------------------------------------------------------------
 * Permission::GROUPS is granted to a role only when the role is CREATED, so a
 * new group is invisible on every installation that already exists and the fix
 * is a checkbox nobody knows to tick. Gateways are settings, so they sit behind
 * settings.read, with everything that changes them behind settings.update -
 * the same decision 20.5 took for the utilities screens, and for the same
 * reason.
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
     * Every Gateway, Configured Or Not
     * @return string
     */
    public function index(): string
    {
        $configured = [];

        foreach (Gateway::all([], 'ASC', 'display_name') as $row) {
            $configured[] = [
                'row'      =>  $row,
                'problem'  =>  Gateway::problemWith($row),
                'settings' =>  Gateway::settings($row),

                // The URL the operator has to paste into their processor's
                // dashboard. Shown rather than documented, because it is
                // derived from THIS installation's base URL and route table -
                // an operator behind a reverse proxy or in a subdirectory has
                // a different one, and a webhook pointed at the wrong path
                // fails silently until somebody notices unpaid invoices.
                'webhook'  =>  named('webhook.gateway', ['gateway' => (string) $row['gateway_slug']]),
            ];
        }

        return $this->screen('gateways', local('payment_gateways'), [
            'configured'   =>  $configured,
            'unconfigured' =>  Gateway::unconfigured(),
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

    ####################################################################################
    /*=================================== ACTIONS ====================================*/
    ####################################################################################

    /**
     * Create The Configuration Row For an Installed Driver
     *
     * The class is not taken from the form. It is looked up among the drivers
     * enabled modules actually declare, keyed by module uid - otherwise this
     * endpoint would let anybody who can reach it name the class the application
     * instantiates on every payment.
     * @return ?string
     */
    public function configure(): ?string
    {
        return $this->attempt(
            function (): void {
                $uid = (string) Request::input('module', '');
                $drivers = Gateway::drivers();

                if (!isset($drivers[$uid])) {
                    throw new RuntimeException(local('gateway_module_unknown'));
                }

                $name = trim((string) Request::input('display_name', ''));
                $slug = trim((string) Request::input('gateway_slug', ''));

                if ($name === '' || $slug === '') {
                    throw new RuntimeException(local('gateway_needs_name_and_slug'));
                }

                $id = Gateway::add([
                    'gateway_name' =>  $slug,
                    'gateway_slug' =>  $slug,
                    'display_name' =>  $name,
                    'module_class' =>  $drivers[$uid],

                    // Configured is not the same as switched on. The operator
                    // fills in the settings first, then activates deliberately.
                    'is_active'    =>  'no',
                ]);

                $this->log('gateway.configured', "Configured the [{$name}] payment gateway.");

                unset($id);
            },
            'staff.gateways',
            local('gateway_configured')
        );
    }

    /**
     * Save a Gateway's Settings
     *
     * Whatever the form carried, minus the fields that are not settings. LBM
     * does not know what an individual gateway needs, so it does not pretend to
     * validate it - a wrong API key is reported by the gateway, at the only
     * moment anybody can actually find out.
     * @param string $gateway Gateway Uid
     * @return ?string
     */
    public function settings(string $gateway): ?string
    {
        $row = $this->gateway($gateway);

        return $this->attempt(
            function () use ($row): void {
                $settings = Request::inputs();

                foreach (['csrf', 'csrf_token', 'token', '_token', 'gateway'] as $drop) {
                    unset($settings[$drop]);
                }

                Gateway::putSettings((int) $row['gateway_id'], $settings);

                $this->log(
                    'gateway.settings.updated',
                    'Updated the settings for the [' . $row['display_name'] . '] payment gateway.'
                );
            },
            'staff.gateways',
            local('gateway_settings_saved')
        );
    }

    /**
     * Switch a Gateway On Or Off For Customers
     * @param string $gateway Gateway Uid
     * @return ?string
     */
    public function toggle(string $gateway): ?string
    {
        $row = $this->gateway($gateway);
        $active = ($row['is_active'] ?? 'no') !== 'yes';

        return $this->attempt(
            function () use ($row, $active): void {
                // activate() refuses to switch on a gateway whose driver will
                // not build, which is what keeps a dead button off the checkout.
                Gateway::activate((int) $row['gateway_id'], $active);

                $this->log(
                    'gateway.toggled',
                    ($active ? 'Switched on' : 'Switched off')
                        . ' the [' . $row['display_name'] . '] payment gateway.'
                );
            },
            'staff.gateways',
            $active ? local('gateway_switched_on') : local('gateway_switched_off')
        );
    }

    /**
     * Forget a Gateway's Configuration
     *
     * The module stays on disk; only the settings row goes. Existing
     * transactions keep pointing at the id, which is why nothing cascades.
     * @param string $gateway Gateway Uid
     * @return ?string
     */
    public function delete(string $gateway): ?string
    {
        $row = $this->gateway($gateway);

        return $this->attempt(
            function () use ($row): void {
                Gateway::delete((int) $row['gateway_id']);

                $this->log(
                    'gateway.deleted',
                    'Removed the configuration for the [' . $row['display_name'] . '] payment gateway.'
                );
            },
            'staff.gateways',
            local('gateway_removed')
        );
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * One Gateway By Uid, Or a Refusal
     * @param string $uid Gateway Uid
     * @return array
     */
    private function gateway(string $uid): array
    {
        $row = Gateway::find($uid);

        if ($row === null) {
            throw new HttpException(404, local('gateway_not_found'));
        }

        return $row;
    }
}
