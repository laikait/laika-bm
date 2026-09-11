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
use LBM\Action\Registrar;
use LBM\Model\DomainModel;
use LBM\Model\DomainRegistrarModel;
use LBM\Module\Contracts\RegistrarInterface;
use LBM\Module\ModuleManager;

/**
 * The four things every caller that drives a registrar module needs.
 *
 * `ServesServices` for domains, and written the same way for the same reason:
 * `Registration` registers names, the client area pushes nameserver changes,
 * and the catalogue asks whether a name is free. All three have to find the
 * registrar a domain belongs to, build the module that serves it, and keep a
 * note on the row of what was tried and what went wrong.
 *
 * ---------------------------------------------------------------------------
 * THIS IS THE ONLY PLACE THE MODULE NAME IS RESOLVED
 * ---------------------------------------------------------------------------
 * `domain_registrars.module_name` is the module DIRECTORY name, matched against
 * the uid `ModuleManager` builds as `<type>-<directory>` - so a registrar
 * running `namesilo` is served by `modules/registrars/namesilo`. Deliberately
 * not matched on the manifest `name`, which is a display string an operator may
 * edit: a link that breaks when somebody tidies a label breaks silently, months
 * later, on somebody else install.
 *
 * ---------------------------------------------------------------------------
 * NO MODULE IS NOT A FAILURE
 * ---------------------------------------------------------------------------
 * An operator who registers names by hand at their registrar has no module at
 * all, and that is a supported way to run this. Every method here answers null
 * rather than throwing, and the callers treat null as "leave it for staff" -
 * the same answer Phase 22.4 settled on for a product with no provisioning
 * module.
 */
trait RegistersDomains
{
    /**
     * Build The Registrar Driver For a Registrar Row
     *
     * Every check here exists because a class name out of the database is
     * untrusted input, whatever wrote it.
     * @param array $registrar Registrar Row
     * @return ?RegistrarInterface Null when there is no usable module
     */
    protected function registrarDriver(array $registrar): ?RegistrarInterface
    {
        $module = trim((string) ($registrar['module_name'] ?? ''));

        if ($module === '') {
            return null;
        }

        if (($registrar['is_active'] ?? 'yes') !== 'yes') {
            return null;
        }

        $wanted = 'registrars-' . strtolower($module);
        $class = '';

        foreach (ModuleManager::loaded() as $uid => $meta) {
            if (($meta['type'] ?? '') !== 'registrars' || strtolower((string) $uid) !== $wanted) {
                continue;
            }

            $class = trim((string) ($meta['class'] ?? ''));
            break;
        }

        if ($class === '' || !class_exists($class) || !is_subclass_of($class, RegistrarInterface::class)) {
            return null;
        }

        // Constructed WITH its credentials - Phase 35. Until then this was
        // `new $class()`, and nothing anywhere read domain_registrars.credentials,
        // so a real registrar module was never handed its own API key. Every
        // caller reaches a driver through here, so this one line serves all six
        // verbs - including the three call sites that pass no context at all.
        try {
            $driver = new $class((new Registrar())->settingsFor($registrar));
        } catch (Throwable) {
            return null;
        }

        return $driver instanceof RegistrarInterface ? $driver : null;
    }

    /**
     * One Registrar Row
     * @param int $registrarId Registrar ID
     * @return ?array
     */
    protected function registrarRow(int $registrarId): ?array
    {
        if ($registrarId <= 0) {
            return null;
        }

        $row = (new DomainRegistrarModel())->where(['dr_id' => $registrarId])->first();

        return is_array($row) ? $row : null;
    }

    /**
     * The Driver a Domain Row Belongs To
     * @param array $domain Domain Row
     * @return ?RegistrarInterface
     */
    protected function driverForDomain(array $domain): ?RegistrarInterface
    {
        $registrar = $this->registrarRow((int) ($domain['registrar_relid'] ?? 0));

        return is_array($registrar) ? $this->registrarDriver($registrar) : null;
    }

    /**
     * Merge Something Into a Domain registrar_data
     *
     * serialize()d by hand. Casts run on READ only, so handing update() the
     * array itself stores the word "Array" - the trap
     * `Transaction::recordGatewayData()` documents and Phase 22.1 walked into.
     * @param array $domain Domain Row
     * @param array $add What to merge in
     * @return void
     */
    protected function rememberDomain(array $domain, array $add): void
    {
        $current = $domain['registrar_data'] ?? null;
        $current = is_array($current) ? $current : [];

        $model = new DomainModel();

        $model->where([$model->id => (int) ($domain['domain_id'] ?? 0)])
            ->update(['registrar_data' => serialize(array_merge($current, $add))]);
    }

    /**
     * How Many Times One Direction Has Been Tried
     *
     * The verb names the counter, so registering, renewing and transferring
     * each get their own and one failing direction cannot exhaust another
     * retries.
     * @param array $domain Domain Row
     * @param string $verb register, renew, transfer or nameservers
     * @return int
     */
    protected function domainAttempts(array $domain, string $verb): int
    {
        $data = $domain['registrar_data'] ?? null;

        return is_array($data) ? (int) ($data[$verb . '_attempts'] ?? 0) : 0;
    }
}
