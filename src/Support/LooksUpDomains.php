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
use LBM\Module\Contracts\LookupInterface;
use LBM\Module\ModuleManager;

/**
 * Turns a lookup module's name into a driver - Phase 36.
 *
 * `RegistersDomains`' twin, and the ONLY place a lookup module name is
 * resolved, for the same reason: the option stores the module DIRECTORY,
 * matched against the uid `ModuleManager` builds as `lookup-<directory>` -
 * never the manifest's `name`, which is a display string an operator may edit.
 * A link that breaks when somebody tidies a label breaks silently.
 *
 * Null for every way of not having one - nothing chosen, switched off, not on
 * disk, a class that does not exist or does not implement the contract, a
 * constructor that throws - and the caller moves on to the registrar.
 */
trait LooksUpDomains
{
    /**
     * Build The Lookup Driver For a Module Directory
     *
     * Every check here exists because a class name read from a manifest is
     * untrusted input, whatever wrote it.
     * @param string $module Module Directory, In Any Case
     * @return ?LookupInterface Null when there is no usable module
     */
    protected function lookupDriver(string $module): ?LookupInterface
    {
        $module = trim($module);

        if ($module === '') {
            return null;
        }

        $wanted = 'lookup-' . strtolower($module);
        $class = '';

        foreach (ModuleManager::loaded() as $uid => $meta) {
            if (($meta['type'] ?? '') !== 'lookup' || strtolower((string) $uid) !== $wanted) {
                continue;
            }

            $class = trim((string) ($meta['class'] ?? ''));
            break;
        }

        if ($class === '' || !class_exists($class) || !is_subclass_of($class, LookupInterface::class)) {
            return null;
        }

        // Built with NO arguments - the contract says so. A provider needing a
        // key reads its own option; there is no credentials column for one.
        try {
            $driver = new $class();
        } catch (Throwable) {
            return null;
        }

        return $driver instanceof LookupInterface ? $driver : null;
    }
}
