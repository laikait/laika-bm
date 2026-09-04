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
use LBM\Model\ClientServiceModel;
use LBM\Model\ServerModel;
use LBM\Module\Contracts\ServerInterface;
use LBM\Module\ModuleManager;

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
}
