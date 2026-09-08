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

namespace LBM\Support;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Throwable;
use Laika\Service\Uid;
use LBM\Model\ClientServiceModel;
use LBM\Model\ModuleModel;
use LBM\Model\ProvisioningLogModel;
use LBM\Model\ServerModel;
use LBM\Module\Contracts\ServerInterface;
use LBM\Module\ModuleManager;
use LBM\Pipeline\Auth;
use LBM\Support\Status;

/**
 * The four things every action that drives a provisioning module needs.
 *
 * `Provision` creates services, `Dunning` suspends and restores them, and
 * `Termination` destroys them. All three have to find the server a service sits
 * on, build the module that serves it, and keep a note on the service row of
 * what they tried and what went wrong. Those were written twice, identically,
 * and a third copy is where a reader stops being able to tell whether the
 * differences between them are deliberate.
 *
 * Extracted with both harnesses green, and re-run afterwards for exactly that
 * reason: a refactor is only safe when something else is asserting the
 * behaviour did not move.
 *
 * ---------------------------------------------------------------------------
 * THIS IS THE ONLY PLACE THE MODULE NAME IS RESOLVED
 * ---------------------------------------------------------------------------
 * `servers.module_name` is the module's DIRECTORY name, matched against the uid
 * `ModuleManager` builds as `<type>-<directory>` - so a server running `cpanel`
 * is served by `modules/servers/cpanel`. Deliberately not matched on the
 * manifest's `name`, which is a display string an operator may edit ("cPanel &
 * WHM"): a link that breaks when somebody tidies a label breaks silently,
 * months later, on somebody else's install.
 */
trait ServesServices
{
    /**
     * Build The Provisioning Driver For a Server
     *
     * Every check here exists because a class name out of the database is
     * untrusted input, whatever wrote it.
     * @param array $server Server Row
     * @return ?ServerInterface Null when there is no usable module
     */
    protected function driverFor(array $server): ?ServerInterface
    {
        $module = trim((string) ($server['module_name'] ?? ''));

        if ($module === '') {
            return null;
        }

        $wanted = 'servers-' . strtolower($module);
        $class = '';

        foreach (ModuleManager::loaded() as $uid => $meta) {
            if (($meta['type'] ?? '') !== 'servers' || strtolower((string) $uid) !== $wanted) {
                continue;
            }

            $class = trim((string) ($meta['class'] ?? ''));
            break;
        }

        if ($class === '' || !class_exists($class) || !is_subclass_of($class, ServerInterface::class)) {
            return null;
        }

        try {
            $driver = new $class();
        } catch (Throwable) {
            return null;
        }

        return $driver instanceof ServerInterface ? $driver : null;
    }

    /**
     * One Server Row
     * @param int $serverId Server ID
     * @return ?array
     */
    protected function serverRow(int $serverId): ?array
    {
        if ($serverId <= 0) {
            return null;
        }

        $row = (new ServerModel())->where(['server_id' => $serverId])->first();

        return is_array($row) ? $row : null;
    }

    /**
     * Merge Something Into a Service's module_data
     *
     * serialize()d by hand. Casts run on READ only, so handing update() the
     * array itself stores the word "Array" - the trap
     * `Transaction::recordGatewayData()` documents and Phase 22.1 walked into.
     * @param array $service Service Row
     * @param array $add What to merge in
     * @return void
     */
    protected function remember(array $service, array $add): void
    {
        $current = $service['module_data'] ?? null;
        $current = is_array($current) ? $current : [];

        $model = new ClientServiceModel();

        $model->where([$model->id => (int) ($service['service_id'] ?? 0)])
            ->update(['module_data' => serialize(array_merge($current, $add))]);
    }

    /**
     * How Many Times One Direction Has Been Tried
     *
     * The verb names the counter, so provisioning, suspending, restoring and
     * terminating each get their own and one failing direction cannot exhaust
     * another's retries.
     * @param array $service Service Row
     * @param string $verb provision, suspend, restore or terminate
     * @return int
     */
    protected function attempts(array $service, string $verb): int
    {
        $data = $service['module_data'] ?? null;

        return is_array($data) ? (int) ($data[$verb . '_attempts'] ?? 0) : 0;
    }

    ################################################################################
    /*========================== THE PROVISIONING LOG ============================*/
    ################################################################################
    //
    // `provisioning_logs` and `provisioning_results` have existed since Phase 0
    // with a schema, a model, three seeded result rows - and NO WRITER ANYWHERE.
    // The fifth such pair this project has found, and the most obviously
    // purpose-built of them: service, action, request, response, result is
    // exactly the shape of a module call.
    //
    // What it answers is "what happened to this customer's service", which is a
    // different question from `error_logs`' "why is this module broken". A call
    // that fails writes both.

    /**
     * Record One Module Call
     *
     * ---------------------------------------------------------------------
     * BOTH PAYLOADS ARE WHITELISTS, AND THAT IS THE WHOLE CARE HERE
     * ---------------------------------------------------------------------
     * Dumping the context would put `servers.password` into a log table, and
     * dumping the result would put THE NEW ACCOUNT'S PASSWORD there - the value
     * `Provision` takes such trouble to hand straight to `setCredential()` so
     * that it is encrypted and never seen again.
     *
     * A blacklist gets this wrong the first time a contract gains a key. A
     * whitelist gets it wrong never, because a key nobody listed is a key that
     * is not copied.
     *
     * @param array $service Service Row
     * @param string $action create, suspend, unsuspend or terminate
     * @param array $server Server Row
     * @param bool $success Whether the module reported success
     * @param string $message What it said
     * @param array $response The module's own return value
     * @return void
     */
    protected function logProvisioning(
        array $service,
        string $action,
        array $server,
        bool $success,
        string $message = '',
        array $response = []
    ): void {
        try {
            $staff = null;

            try {
                $user = Auth::user(ADMIN);
                $staff = $user === null ? null : (((int) ($user['sid'] ?? 0)) ?: null);
            } catch (Throwable) {
                // No session driver - a cron run or a queue worker. `system` is
                // then the honest answer rather than a missing one.
            }

            (new ProvisioningLogModel())->insert([
                'uid'            =>  Uid::make(),
                'service_relid'  =>  (int) ($service['service_id'] ?? 0),
                'creator_type'   =>  $staff === null ? 'system' : 'staff',
                'creator_relid'  =>  $staff,
                'action'         =>  $action,
                'result_relid'   =>  (new Status())->idOf(
                    'provisioning_results',
                    $success ? 'success' : 'failure'
                ) ?? 1,

                // serialize()d by hand. Casts run on READ only, so handing
                // insert() the array itself stores the word "Array" - the trap
                // Transaction::recordGatewayData() documents and 22.1 walked
                // into, which took the gateways screen down for every visitor.
                'request_data'   =>  serialize([
                    'server_id' =>  (int) ($server['server_id'] ?? 0),
                    'server'    =>  (string) ($server['name'] ?? ''),
                    'module'    =>  (string) ($server['module_name'] ?? ''),
                    'product'   =>  (int) ($service['product_relid'] ?? 0),
                    'domain'    =>  (string) ($service['domain'] ?? ''),
                ]),

                'response_data'  =>  serialize([
                    'success'  =>  $success,
                    'message'  =>  mb_substr($message, 0, 2000),

                    // The account name is safe and is the single most useful
                    // thing here - it is what an operator types into the control
                    // panel to look at what was made. `password` is deliberately
                    // absent, and must stay absent.
                    'username' =>  (string) ($response['username'] ?? ''),
                ]),

                'log_created_at' =>  date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable) {
            // A log that cannot be written must not undo a service that was.
            // The same rule ErrorLog is built on, and it matters more here:
            // throwing would take down a cron run that had already created
            // accounts on somebody else's server.
        }
    }

    /**
     * The `modules` Row Id For a Server's Module, If There Is One
     *
     * So an error log entry can name the module that caused it. Same resolution
     * rule as `driverFor()` - the DIRECTORY name, never the manifest's display
     * string, for the reason the class docblock gives.
     * @param array $server Server Row
     * @return ?int
     */
    protected function moduleIdFor(array $server): ?int
    {
        $module = trim((string) ($server['module_name'] ?? ''));

        if ($module === '') {
            return null;
        }

        try {
            $row = (new ModuleModel())
                ->where(['uid' => 'servers-' . strtolower($module)])
                ->first();

            return is_array($row) ? (((int) ($row['module_id'] ?? 0)) ?: null) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
