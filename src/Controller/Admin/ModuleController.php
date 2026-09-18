<?php
/**
 * Laika Bill Manager
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: Proprietary - see LICENSE
 * This file is part of Laika Bill Manager.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace LBM\Controller\Admin;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use RuntimeException;
use Laika\Service\Request;
use LBM\Service\Fraud;
use LBM\Service\Gateway;
use LBM\Service\Module;
use LBM\Service\Registrar;

/**
 * Switching modules on and off, and configuring them - Phase 46.
 *
 * There is no Modules screen any more. Each kind is listed on a settings screen
 * of its own - payment gateways, servers, registrars (with the lookup modules),
 * fraud and plugins - drawn through partials/module-list.twig, and every one of
 * them posts here to switch a module on or off and links here to configure one.
 * The two kinds with nowhere else to live, fraud and plugins, have their screens
 * here too.
 *
 * NO ROUTE HERE ADDS OR UPLOADS A MODULE. They arrive on disk with a release.
 *
 * ---------------------------------------------------------------------------
 * SWITCHING ON A GATEWAY OR A REGISTRAR MAKES ONE
 * ---------------------------------------------------------------------------
 * Enabling a gateway module creates its payment_gateways row the first time,
 * and enabling a registrar module its domain_registrars row: the forms that
 * used to set those up are gone. Disabling stops offering the row and KEEPS it -
 * transactions, TLDs and domains point at it.
 *
 * ---------------------------------------------------------------------------
 * CONFIGURE IS ONE PAGE, AND EACH KIND KEEPS ITS OWN PERMISSION
 * ---------------------------------------------------------------------------
 * A gateway's settings were always settings.*, a registrar's domain.update, the
 * rest module.* - 20.5's rule is that none of that moves. So the page's routes
 * carry no Permission pipeline, and demand() asks for what the KIND needs
 * (Module::CONFIGURE_ACCESS). A server module has no page: its fields belong to
 * each product.
 *
 * The switch itself is module.update for every kind, as it always was.
 */
class ModuleController extends AdminController
{
    /** @var array<string,string> The Label Of The Screen Each Kind Is Listed On */
    private const SCREEN_LABELS = [
        'gateways'   =>  'payment_gateways',
        'registrars' =>  'domain_registrars',
        'lookup'     =>  'domain_registrars',
        'fraud'      =>  'fraud_check',
        'plugins'    =>  'plugins',
    ];

    protected function nav(): string
    {
        return 'settings';
    }

    ####################################################################################
    /*=================================== SCREENS ====================================*/
    ####################################################################################

    /**
     * Fraud Modules, And Which One Screens Checkout - Phase 46
     * @return string
     */
    public function fraud(): string
    {
        return $this->screen('fraud', local('fraud_check'), [
            'modules' =>  $this->moduleList('fraud'),
            'path'    =>  'modules/fraud',
            'fraud'   =>  $this->fraudCard(),
        ]);
    }

    /**
     * Plugin Modules - Phase 46
     * @return string
     */
    public function plugins(): string
    {
        return $this->screen('plugins', local('plugins'), [
            'modules' =>  $this->moduleList('plugins'),
            'path'    =>  'modules/plugins',
        ]);
    }

    /**
     * Choose Which Fraud Module Screens Checkout - Phase 41
     *
     * Behind module.update, the same as the switch beside it: choosing a
     * module that holds orders is the same kind of decision as turning one on.
     * @return ?string
     */
    public function chooseFraud(): ?string
    {
        $posted = (string) Request::input('fraud_module', '');
        $before = Fraud::chosen() ?? 'none';

        return $this->attempt(
            function () use ($posted, $before): void {
                $after = Fraud::choose($posted);

                if ($after !== $before) {
                    $this->log('fraud.chosen', "Fraud check changed from {$before} to {$after}.");
                }
            },
            'staff.fraud',
            local('fraud_saved')
        );
    }

    ####################################################################################
    /*=================================== ACTIONS ====================================*/
    ####################################################################################

    /**
     * Switch a Module On Or Off, From Its Kind's Screen
     *
     * Switching ON, the kind's own row comes first: if it cannot be made - a
     * gateway module that names no driver class - the module is left off
     * rather than on with nothing behind it. Switching OFF, the module goes
     * first and the row is stopped after it.
     * @param string $module Module Uid
     * @return ?string
     */
    public function toggle(string $module): ?string
    {
        $row = $this->record(Module::find($module), 'module');
        $uid = (string) $row['uid'];
        $type = (string) $row['type'];
        $on = !Module::isEnabled($uid);

        return $this->attempt(
            function () use ($row, $uid, $type, $on): void {
                if ($on) {
                    $this->attach($type, $row, true);
                    Module::toggle($uid, true);
                } else {
                    Module::toggle($uid, false);
                    $this->attach($type, $row, false);
                }

                $this->log('module.toggled', ($on ? 'Enabled' : 'Disabled') . ' the ' . $row['name'] . ' module.');
            },
            Module::screenRoute($type),
            local($on ? 'module_enabled_named' : 'module_disabled_named', (string) $row['name'])
        );
    }

    /**
     * One Module's Settings - Every Kind But Servers
     *
     * Stored where that kind's settings always lived: a gateway's on its
     * payment_gateways row, a registrar's on its domain_registrars row, and a
     * lookup, fraud or plugin module's in module_settings.
     * @param string $module Module Uid
     * @return ?string
     */
    public function configure(string $module): ?string
    {
        $row = $this->record(Module::find($module), 'module');
        $access = $this->accessFor($row);

        $this->demand(Request::isPost() ? $access[1] : $access[0]);

        $uid = (string) $row['uid'];

        if (Request::isPost()) {
            // A gateway's save may go on to register its webhook, and the flash
            // has to say how that went - attempt() fixes its message up front.
            if ((string) $row['type'] === 'gateways') {
                return $this->saveGateway($row, $uid);
            }

            $input = Request::inputs();

            return $this->attempt(
                function () use ($row, $uid, $input): void {
                    match ((string) $row['type']) {
                        'registrars' =>  Registrar::configure($this->registrarId($row), $input),
                        default      =>  Module::configure($uid, $input),
                    };

                    // The names that changed are not logged, and certainly not
                    // the values: a secret has no business in an audit trail.
                    $this->log('module.configured', 'Updated the settings of the ' . $row['name'] . ' module.');
                },
                'staff.module.configure',
                local('module_settings_saved'),
                ['module' => $uid]
            );
        }

        return $this->screen(
            'module-configure',
            local('configure_module', (string) $row['name']),
            $this->configureVars($row, $access)
        );
    }

    /**
     * Try a Module's Saved Settings
     *
     * The module's own words come back on the screen, success or not; a test
     * that fails is an answer, so it is not written to the error log. Behind
     * the kind's save permission: it spends a call at somebody else's API.
     * @param string $module Module Uid
     * @return ?string
     */
    public function test(string $module): ?string
    {
        $row = $this->record(Module::find($module), 'module');
        $access = $this->accessFor($row);

        $this->demand($access[1]);

        $uid = (string) $row['uid'];

        $result = match ((string) $row['type']) {
            'gateways'   =>  $this->testGateway($row),
            'registrars' =>  $this->testRegistrar($row),
            default      =>  Module::testConnection($uid),
        };

        $this->log('module.tested', 'Tested the connection of the ' . $row['name'] . ' module.');

        return $this->done(
            'staff.module.configure',
            local($result['success'] ? 'connection_ok' : 'connection_failed', $result['message']),
            $result['success'],
            ['module' => $uid]
        );
    }

    /**
     * Register a Gateway's Webhook With Its Provider, Now - Phase 48
     *
     * Behind the gateway's save permission, like Test: it creates something in
     * the operator's own account at the provider. Only gateways have one.
     * @param string $module Module Uid
     * @return ?string
     */
    public function webhook(string $module): ?string
    {
        $row = $this->record(Module::find($module), 'module');

        if ((string) $row['type'] !== 'gateways') {
            $this->record(null, 'module');
        }

        $this->demand($this->accessFor($row)[1]);

        $uid = (string) $row['uid'];
        $gateway = Gateway::forModule($uid);

        $result = $gateway === null
            ? ['success' => false, 'message' => local('module_not_set_up')]
            : (Gateway::registerWebhook((int) $gateway['gateway_id'], true)
                ?? ['success' => false, 'message' => local('module_not_set_up')]);

        $this->log('gateway.webhook', ($result['success'] ? 'Registered' : 'Could not register')
            . ' the webhook of the ' . $row['name'] . ' module.');

        return $this->done(
            'staff.module.configure',
            local($result['success'] ? 'webhook_registered' : 'webhook_not_registered', $result['message']),
            $result['success'],
            ['module' => $uid]
        );
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Save a Gateway's Settings, Then Register Its Webhook If It Needs One
     *
     * Saved first, and kept whatever the provider says next: a key the operator
     * typed is theirs even when the provider refuses the address. The flash
     * then says both, in the provider's words - a webhook that is not registered
     * is a gateway that takes money and never records it.
     * @param array $row Module
     * @param string $uid Module Uid
     * @return null
     */
    private function saveGateway(array $row, string $uid): null
    {
        $params = ['module' => $uid];

        try {
            $id = $this->gatewayId($row);
            Gateway::saveSettings($id, Request::inputs());
        } catch (RuntimeException $e) {
            return $this->done('staff.module.configure', $e->getMessage(), false, $params);
        }

        // The names that changed are not logged, and certainly not the values:
        // a secret has no business in an audit trail.
        $this->log('module.configured', 'Updated the settings of the ' . $row['name'] . ' module.');

        $webhook = Gateway::registerWebhook($id);

        if ($webhook === null) {
            return $this->done('staff.module.configure', local('module_settings_saved'), true, $params);
        }

        $this->log('gateway.webhook', ($webhook['success'] ? 'Registered' : 'Could not register')
            . ' the webhook of the ' . $row['name'] . ' module.');

        return $this->done(
            'staff.module.configure',
            local($webhook['success'] ? 'settings_saved_webhook_registered' : 'settings_saved_webhook_failed', $webhook['message']),
            $webhook['success'],
            $params
        );
    }

    /**
     * Who May Read And Save This Module's Configure Page
     *
     * A server module has none: its fields belong to each product, and asking
     * for its page is a 404 rather than a second place to save them.
     * @param array $module Module
     * @return array{0:string,1:string}
     */
    private function accessFor(array $module): array
    {
        $access = Module::configureAccess((string) $module['type']);

        if ($access === null) {
            $this->record(null, 'module');
        }

        return $access;
    }

    /**
     * The Kind's Own Row, Switched On Or Off With Its Module
     * @param string $type Module Kind
     * @param array $module Module
     * @param bool $on
     * @return void
     */
    private function attach(string $type, array $module, bool $on): void
    {
        match ($type) {
            'gateways'   =>  Gateway::attach((string) $module['uid'], $on),
            'registrars' =>  Registrar::attach((string) $module['directory'], $on),
            default      =>  null,
        };
    }

    /**
     * The Gateway Row a Gateway Module Is Configured In
     *
     * Made here only for a module that is switched on and has none - one
     * enabled before Phase 46, when enabling it made nothing.
     * @param array $module Module
     * @return int
     * @throws RuntimeException
     */
    private function gatewayId(array $module): int
    {
        $row = Gateway::forModule((string) $module['uid']);

        if ($row !== null) {
            return (int) $row['gateway_id'];
        }

        if (!Module::isLoaded((string) $module['uid'])) {
            throw new RuntimeException(local('switch_on_to_configure'));
        }

        return (int) Gateway::attach((string) $module['uid'], true);
    }

    /**
     * The Registrar Row a Registrar Module Serves
     * @param array $module Module
     * @return int
     * @throws RuntimeException
     */
    private function registrarId(array $module): int
    {
        $row = Registrar::forModule((string) $module['directory']);

        if ($row !== null) {
            return (int) $row['dr_id'];
        }

        if (!Module::isLoaded((string) $module['uid'])) {
            throw new RuntimeException(local('switch_on_to_configure'));
        }

        return (int) Registrar::attach((string) $module['directory'], true);
    }

    /**
     * Test a Gateway Module's Saved Settings
     * @param array $module Module
     * @return array{success: bool, message: string}
     */
    private function testGateway(array $module): array
    {
        $row = Gateway::forModule((string) $module['uid']);

        return $row === null
            ? ['success' => false, 'message' => local('module_not_set_up')]
            : Gateway::testConnection((int) $row['gateway_id']);
    }

    /**
     * Test a Registrar Module's Saved Settings
     * @param array $module Module
     * @return array{success: bool, message: string}
     */
    private function testRegistrar(array $module): array
    {
        $row = Registrar::forModule((string) $module['directory']);

        return $row === null
            ? ['success' => false, 'message' => local('module_not_set_up')]
            : Registrar::testConnection($row);
    }

    /**
     * What The Configure Page Is Handed
     *
     * The declared fields go through ModuleSettings::forForm() for every kind -
     * a secret arrives as a `saved` flag, never as what is stored. A gateway or
     * registrar module that has no row yet is drawn from its class alone.
     * @param array $module Module
     * @param array{0:string,1:string} $access
     * @return array<string,mixed>
     */
    private function configureVars(array $module, array $access): array
    {
        $uid = (string) $module['uid'];
        $type = (string) $module['type'];

        $vars = [
            'module'       =>  $module,
            'kind'         =>  $type,
            'loaded'       =>  Module::isLoaded($uid),
            'can_update'   =>  staff_has_access($access[1]),
            'screen'       =>  Module::screenRoute($type),
            'screen_label' =>  local(self::SCREEN_LABELS[$type] ?? 'settings'),
            'gateway'      =>  null,
            'registrar'    =>  null,
        ];

        if ($type === 'gateways') {
            $row = Gateway::forModule($uid);
            $form = Gateway::formFor($row ?? ['module_class' => (string) ($module['class'] ?? '')]);

            $vars['gateway'] = [
                'display_name' =>  (string) ($row['display_name'] ?? $module['name']),
                'offered'      =>  ($row['is_active'] ?? 'no') === 'yes',

                // The URL the operator pastes into their processor's dashboard,
                // derived from THIS installation's base URL and route table - a
                // webhook pointed at the wrong path fails silently until
                // somebody notices unpaid invoices.
                'webhook'      =>  $row === null ? null : named('webhook.gateway', ['gateway' => (string) $row['gateway_slug']]),
                'problem'      =>  $row === null || !$vars['loaded'] ? null : Gateway::problemWith($row),

                // Phase 48: a driver that registers its own webhook gets a
                // button, and a line saying whether this mode has one.
                'registers'     =>  $row !== null && $vars['loaded'] && Gateway::registersWebhook($row),
                'webhook_ready' =>  $row !== null && $vars['loaded'] ? Gateway::webhookReady($row) : null,
            ];
        } elseif ($type === 'registrars') {
            $row = Registrar::forModule((string) $module['directory']);
            $shown = $row ?? ['module_name' => (string) $module['directory'], 'credentials' => [], 'test_mode' => 'no'];
            $form = Registrar::formFor($shown);

            $vars['registrar'] = [
                'name'        =>  (string) ($row['name'] ?? $module['name']),
                'declared'    =>  $form['declared'],
                'credentials' =>  Registrar::credentialNames($shown),
                'is_default'  =>  ($row['is_default'] ?? 'no') === 'yes',
                'state'       =>  Registrar::state(array_merge($shown, ['is_active' => 'yes'])),
            ];
        } else {
            $form = Module::formFor($uid);
        }

        $vars['fields'] = $form['fields'];
        $vars['mode'] = $form['mode'];

        return $vars;
    }

    /**
     * What The Fraud Check Card Shows
     *
     * The chosen module stays in the dropdown when it has left the disk -
     * Phase 35's select trap: a <select> whose value is not among its
     * options posts another one, and saving would quietly change the choice.
     * @return array{value: string, name: ?string, state: string, choices: array<string,string>}
     */
    private function fraudCard(): array
    {
        $chosen = Fraud::chosen();

        $choices = ['none' => local('fraud_none')];

        foreach (Fraud::modules() as $directory => $module) {
            $choices[(string) $directory] = $module['enabled']
                ? $module['name']
                : local('module_named_off', $module['name']);
        }

        if ($chosen !== null && Fraud::installedModule($chosen) === null) {
            $choices[$chosen] = local('module_named_missing', $chosen);
        }

        return [
            'value'   =>  $chosen === null ? 'none' : (Fraud::installedModule($chosen) ?? $chosen),
            'name'    =>  Fraud::name(),
            'state'   =>  Fraud::state(),
            'choices' =>  $choices,
        ];
    }
}
