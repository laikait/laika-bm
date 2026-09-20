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

namespace LBM\Action;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Throwable;
use Laika\Model\Model;
use Laika\Service\Uid;
use LBM\Model\ClientServiceModel;
use LBM\Model\ServiceUsageRecordModel;
use LBM\Module\Accounts;
use LBM\Module\Contracts\ChangesPackage;
use LBM\Module\Contracts\ChangesPassword;
use LBM\Module\Contracts\ChecksServer;
use LBM\Module\Contracts\ListsAccounts;
use LBM\Module\Contracts\ServerInterface;
use LBM\Module\Contracts\SingleSignOn;
use LBM\Module\Contracts\SyncsUsage;
use LBM\Support\ErrorLog;
use LBM\Support\ServesServices;
use LBM\Support\Status;

/**
 * The optional things a server module can do to a live account - Phase 53.
 *
 * ServerInterface is the lifecycle every module must support. Past that, panels
 * differ: most can change a package or a password and sign somebody in, some
 * report usage, some list their accounts. Each of those is its own small
 * contract in Module\Contracts, and a module implements the ones its panel
 * offers. capabilities() asks `instanceof`, and the screens show a button only
 * for what the module can actually do - so an operator never presses something
 * that was never going to work.
 *
 * Every call goes through call(), which does what Dunning::call() does for
 * suspend: build the driver for this service's server and product, catch
 * whatever the module throws, and write the provisioning log whichever way it
 * went. Unlike suspend, NO MODULE IS A FAILURE here - there is nothing to
 * record by hand when the operation was the whole point.
 *
 * Nothing here is billing. A package change moves the account on the panel and
 * the service to the new product; the service keeps its own `amount`, so what
 * it is invoiced does not change. Pricing a change is Phase 58's.
 */
class ServiceOperation extends Action
{
    use ServesServices;

    public function model(): Model
    {
        return new ClientServiceModel();
    }

    ####################################################################################
    /*================================= EXTERNAL API =================================*/
    ####################################################################################

    /**
     * What This Service's Module Can Do Beyond The Lifecycle
     * @param array $service Service Row
     * @return array{package:bool, password:bool, sso:bool, usage:bool}
     */
    public function capabilities(array $service): array
    {
        $driver = $this->driverOf($service)[0] ?? null;

        return [
            'package'  =>  $driver instanceof ChangesPackage,
            'password' =>  $driver instanceof ChangesPassword,
            'sso'      =>  $driver instanceof SingleSignOn,
            'usage'    =>  $driver instanceof SyncsUsage,
        ];
    }

    /**
     * Move The Account To Another Product's Package
     *
     * Only to a product on the same module: another module's package names mean
     * nothing to this panel.
     * @param array $service Service Row
     * @param int $productId The New Product
     * @return array{success:bool, message:string}
     */
    public function changePackage(array $service, int $productId): array
    {
        $current = (new Product())->find((int) ($service['product_relid'] ?? 0)) ?? [];
        $target = (new Product())->find($productId);

        if (!is_array($target)) {
            return $this->refuse('That product does not exist.');
        }

        if ((int) $target[(new Product())->model()->id] === (int) ($service['product_relid'] ?? 0)) {
            return $this->refuse('The service is already on that product.');
        }

        if (trim((string) ($target['module_name'] ?? '')) !== trim((string) ($current['module_name'] ?? ''))) {
            return $this->refuse('That product is set up by a different module, so this server cannot move the account to it.');
        }

        $result = $this->call($service, 'change_package', ChangesPackage::class,
            static fn(ChangesPackage $d, array $context): mixed => $d->changePackage($service, $context),
            ['product' => $target, 'previous' => $current],
            $target
        );

        if ($result['success']) {
            (new ClientServiceModel())
                ->where([(new ClientServiceModel())->id => (int) $service['service_id']])
                ->update(['product_relid' => $productId]);

            (new Activity())->record(
                'service.package',
                'Moved service #' . (int) $service['service_id'] . ' to ' . ($target['product_name'] ?? 'product #' . $productId) . '.'
            );
        }

        return $result;
    }

    /**
     * Set a New Password On The Panel, And Keep It
     *
     * Stored only after the panel accepted it - a password LBM holds that the
     * panel never took would be shown to the client as theirs, and would not work.
     * @param array $service Service Row
     * @param ?string $password Blank Generates One
     * @return array{success:bool, message:string}
     */
    public function changePassword(array $service, ?string $password = null): array
    {
        $password = trim((string) $password) === '' ? Accounts::password(16) : (string) $password;

        $result = $this->call($service, 'change_password', ChangesPassword::class,
            static fn(ChangesPassword $d, array $context): mixed => $d->changePassword($service, $password, $context)
        );

        if ($result['success']) {
            (new ClientService())->setCredential((int) $service['service_id'], $password);

            (new Activity())->record('service.password', 'Changed the password of service #' . (int) $service['service_id'] . '.');
        }

        return $result;
    }

    /**
     * A One-Time Sign-In Link To The Panel
     *
     * Only an http(s) address is handed back: it is about to be a redirect, and
     * a module answering `javascript:` must not become one.
     * @param array $service Service Row
     * @param string $as client or staff
     * @return array{success:bool, message:string, url:?string}
     */
    public function singleSignOn(array $service, string $as): array
    {
        $url = null;

        $result = $this->call($service, 'single_sign_on', SingleSignOn::class,
            static function (SingleSignOn $d, array $context) use ($service, &$url): mixed {
                $answer = $d->singleSignOn($service, $context);
                $url = is_array($answer) ? ($answer['url'] ?? null) : null;

                return $answer;
            },
            ['as' => $as === 'staff' ? 'staff' : 'client']
        );

        if ($result['success'] && (!is_string($url) || !preg_match('#^https?://#i', $url))) {
            return ['success' => false, 'message' => 'The module did not return a usable sign-in address.', 'url' => null];
        }

        return $result + ['url' => $result['success'] ? $url : null];
    }

    /**
     * Ask The Panel What The Account Is Using, And Record It
     * @param array $service Service Row
     * @return array{success:bool, message:string, metrics:array<string,int>}
     */
    public function syncUsage(array $service): array
    {
        $metrics = [];

        $result = $this->call($service, 'sync_usage', SyncsUsage::class,
            static function (SyncsUsage $d, array $context) use ($service, &$metrics): mixed {
                $answer = $d->usage($service, $context);
                $metrics = is_array($answer['metrics'] ?? null) ? $answer['metrics'] : [];

                return $answer;
            }
        );

        if (!$result['success']) {
            return $result + ['metrics' => []];
        }

        $clean = [];
        $model = new ServiceUsageRecordModel();
        $now = $this->now();

        foreach ($metrics as $name => $quantity) {
            $name = strtolower(trim((string) $name));

            // A name that could not be a column label, or a quantity that is
            // not a whole number of anything, is the module's bug - dropped
            // rather than stored as something a bill might one day read.
            if (!preg_match('/^[a-z][a-z0-9_]{0,59}$/', $name) || !is_numeric($quantity) || (int) $quantity < 0) {
                continue;
            }

            $clean[$name] = (int) $quantity;

            $model->insert([
                $model->uid      =>  Uid::make(),
                'service_relid'  =>  (int) $service['service_id'],
                'metric_name'    =>  $name,
                'quantity'       =>  (int) $quantity,
                'recorded_at'    =>  $now,
            ]);
        }

        return $result + ['metrics' => $clean];
    }

    /**
     * The Latest Reading Of Each Metric For a Service
     * @param int $serviceId Service ID
     * @return array<string,array{quantity:int, recorded_at:string}>
     */
    public function latestUsage(int $serviceId): array
    {
        $model = new ServiceUsageRecordModel();

        $rows = $model->where(['service_relid' => $serviceId])
            ->order($model->id, self::DESC)
            ->limit(200)
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $name = (string) $row['metric_name'];

            $out[$name] ??= ['quantity' => (int) $row['quantity'], 'recorded_at' => (string) $row['recorded_at']];
        }

        ksort($out);

        return $out;
    }

    /**
     * Sync Every Active Service Whose Module Reports Usage - For Cron
     *
     * One panel being down must not stop the rest, so each service is its own
     * attempt and the count of failures is what comes back.
     * @return array{done:int, failed:int}
     */
    public function syncAllUsage(): array
    {
        $active = (new Status())->idOf(Provision::SERVICE_STATUSES, 'active');

        if ($active === null) {
            return ['done' => 0, 'failed' => 0];
        }

        $done = 0;
        $failed = 0;

        foreach ($this->model()->where(['status_relid' => $active])->get() as $service) {
            if ((int) ($service['server_relid'] ?? 0) <= 0 || !$this->capabilities($service)['usage']) {
                continue;
            }

            $this->syncUsage($service)['success'] ? $done++ : $failed++;
        }

        return ['done' => $done, 'failed' => $failed];
    }

    /**
     * Whether a Server's Module Can List Its Accounts
     *
     * Builds the driver and asks instanceof - no network - so the servers list
     * can offer the link only where it will work.
     * @param array $server Server Row
     * @return bool
     */
    public function listsAccounts(array $server): bool
    {
        return $this->driverFor($server) instanceof ListsAccounts;
    }

    /**
     * Ask The Server's Module Whether Its Credentials Work - Phase 54
     *
     * Null when the module cannot say (it does not implement ChecksServer), so
     * the caller keeps its own port check as the whole answer.
     * @param array $server Server Row
     * @return ?array{success:bool, message:string}
     */
    public function checkServer(array $server): ?array
    {
        $driver = $this->driverFor($server);

        if (!$driver instanceof ChecksServer) {
            return null;
        }

        try {
            $answer = $driver->checkServer($server);
        } catch (Throwable $e) {
            ErrorLog::record($e, 'module', $this->moduleIdFor($server));

            return ['success' => false, 'message' => 'The module raised an error: ' . $e->getMessage()];
        }

        return [
            'success' => is_array($answer) && ($answer['success'] ?? false) === true,
            'message' => trim((string) ($answer['message'] ?? '')) ?: 'The module gave no answer.',
        ];
    }

    /**
     * Every Account The Panel Has, For The Server Screen
     * @param array $server Server Row
     * @return array{supported:bool, success:bool, accounts:array, message:string}
     */
    public function accounts(array $server): array
    {
        $driver = $this->driverFor($server);

        if (!$driver instanceof ListsAccounts) {
            return ['supported' => false, 'success' => false, 'accounts' => [], 'message' => ''];
        }

        try {
            $answer = $driver->accounts($server);
        } catch (Throwable $e) {
            ErrorLog::record($e, 'module', $this->moduleIdFor($server));

            return ['supported' => true, 'success' => false, 'accounts' => [], 'message' => 'The module raised an error: ' . $e->getMessage()];
        }

        if (!is_array($answer) || ($answer['success'] ?? false) !== true) {
            $why = trim((string) ($answer['message'] ?? '')) ?: 'The module reported a failure.';

            return ['supported' => true, 'success' => false, 'accounts' => [], 'message' => $why];
        }

        $accounts = [];

        foreach ((array) ($answer['accounts'] ?? []) as $account) {
            if (!is_array($account) || trim((string) ($account['username'] ?? '')) === '') {
                continue;
            }

            $accounts[] = [
                'username'  =>  (string) $account['username'],
                'domain'    =>  isset($account['domain']) ? (string) $account['domain'] : null,
                'package'   =>  isset($account['package']) ? (string) $account['package'] : null,
                'suspended' =>  isset($account['suspended']) ? (bool) $account['suspended'] : null,
            ];
        }

        return ['supported' => true, 'success' => true, 'accounts' => $accounts, 'message' => ''];
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Run One Optional Operation Against a Service's Module
     * @param array $service Service Row
     * @param string $verb The provisioning_logs action
     * @param class-string $contract What the module must implement
     * @param callable(object, array): mixed $work The call itself
     * @param array $extra Merged into the context
     * @param ?array $product The product to build the driver for; the service's own by default
     * @return array{success:bool, message:string}
     */
    private function call(
        array $service,
        string $verb,
        string $contract,
        callable $work,
        array $extra = [],
        ?array $product = null
    ): array {
        [$driver, $server, $ownProduct] = $this->driverOf($service, $product);

        if ($server === null) {
            return $this->refuse('This service is not on a server.');
        }

        if (!$driver instanceof $contract) {
            return $this->refuse('The module for ' . ($server['name'] ?? 'this server') . ' cannot do that.');
        }

        $context = $extra + [
            'server'  =>  $server,
            'client'  =>  (new Client())->find((int) ($service['client_relid'] ?? 0)) ?? [],
            'product' =>  $ownProduct,
            'options' =>  [],
        ];

        try {
            $result = $work($driver, $context);
        } catch (Throwable $e) {
            $this->logProvisioning($service, $verb, $server, false, $e->getMessage());
            ErrorLog::record($e, 'module', $this->moduleIdFor($server));

            return ['success' => false, 'message' => 'The module raised an error: ' . $e->getMessage()];
        }

        if (!is_array($result) || ($result['success'] ?? false) !== true) {
            $why = trim((string) ($result['message'] ?? '')) ?: 'The module reported a failure.';

            // The URL of a sign-on is a live key to the panel: it goes to the
            // browser and nowhere else, never into the log's stored response.
            $this->logProvisioning($service, $verb, $server, false, $why, $this->loggable((array) $result));
            ErrorLog::note($why, 'module', $this->moduleIdFor($server));

            return ['success' => false, 'message' => $why];
        }

        $message = (string) ($result['message'] ?? '');
        $this->logProvisioning($service, $verb, $server, true, $message, $this->loggable($result));

        return ['success' => true, 'message' => $message !== '' ? $message : 'Done.'];
    }

    /**
     * The Driver, Server And Product For a Service
     * @param array $service Service Row
     * @param ?array $product Build for this product instead of the service's own
     * @return array{0:?ServerInterface, 1:?array, 2:array}
     */
    private function driverOf(array $service, ?array $product = null): array
    {
        $server = $this->serverRow((int) ($service['server_relid'] ?? 0));
        $own = (new Product())->find((int) ($service['product_relid'] ?? 0)) ?? [];

        if ($server === null) {
            return [null, null, $own];
        }

        return [$this->driverFor($server, $product ?? $own), $server, $own];
    }

    /**
     * A Module's Answer With Anything Secret Taken Out
     * @param array $result
     * @return array
     */
    private function loggable(array $result): array
    {
        unset($result['url'], $result['password']);

        return $result;
    }

    /**
     * A Refusal That Never Reached The Module
     * @param string $why
     * @return array{success:bool, message:string}
     */
    private function refuse(string $why): array
    {
        return ['success' => false, 'message' => $why];
    }
}
