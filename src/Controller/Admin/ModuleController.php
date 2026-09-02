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
use LBM\Module\ModuleInstaller;
use LBM\Service\Module;

/**
 * The modules screen.
 *
 * Lists what is installed in the app root's `modules/` directory and remembers
 * which are switched on. It does not load them - that is the module runtime,
 * which is a phase of its own. The separation is deliberate: this screen has to
 * stay reachable when a module is broken, so that somebody can disable it.
 */
class ModuleController extends AdminController
{
    protected function nav(): string
    {
        return 'modules';
    }

    /**
     * The Module List
     * @return string
     */
    public function index(): string
    {
        $modules = Module::all();

        foreach ($modules as $uid => $module) {
            $uid = (string) $uid;

            // Enabled and loaded are separate questions, and the screen shows
            // both. A module switched on a moment ago is enabled but not yet
            // loaded - discovery runs during autoload, so it takes effect from
            // the next request. One whose manifest throws is enabled and
            // failed, and reporting only "enabled" there would put a green tick
            // beside something doing nothing at all.
            $modules[$uid]['enabled']   = Module::isEnabled($uid);
            $modules[$uid]['loaded']    = Module::isLoaded($uid);
            $modules[$uid]['registers'] = Module::loadedResources($uid);

            // A load error wins over a manifest-read error: both come from the
            // same file, but the loader's is the one that stopped it working.
            $modules[$uid]['error'] = Module::loadError($uid) ?? ($module['error'] ?? null);
        }

        return $this->screen('modules', 'Modules', [
            'modules' =>  $modules,
            'types'   =>  Module::types(),
            'path'    =>  Module::path(),

            // The select is keyed by the type, so the view never has to know
            // the list - and a value that is not in it is refused server-side
            // by ModuleInstaller anyway.
            'kinds'   =>  array_combine(Module::types(), Module::types()),
            'maxsize' =>  (int) round(ModuleInstaller::MAX_BYTES / 1048576) . 'MB',
        ]);
    }

    /**
     * Install a Module From an Uploaded Archive
     *
     * The kind comes from the form, never from the archive. `modules/README.md`
     * is explicit that the directory decides a module's kind precisely so that
     * a module cannot claim to be something it is not, and reading a `type` out
     * of an uploaded manifest would hand that claim straight back.
     *
     * It arrives disabled. Nothing an operator uploads runs until they say so -
     * the installer does not execute the manifest either, so that holds all the
     * way through.
     * @return ?string
     */
    public function upload(): ?string
    {
        $type = trim((string) Request::input('module_type', ''));
        $file = Request::file('module_archive') ?? [];

        return $this->attempt(
            function () use ($file, $type): void {
                $module = (new ModuleInstaller())->install($file, $type);

                // Logged before the flash, because installing code is exactly
                // the kind of thing somebody needs to be able to look up later.
                $this->log(
                    'module.installed',
                    'Installed the ' . $module['name'] . ' ' . $module['type'] . ' module from an upload.'
                );

                // The listing is memoised per request and this changed the disk
                // under it, so the redirect target would otherwise show the old
                // set until the next page load.
                Module::flush();
            },
            'staff.modules',
            'Module installed. Switch it on when you are ready.'
        );
    }

    /**
     * Switch a Module On Or Off
     * @param string $module Module Uid
     * @return ?string
     */
    public function toggle(string $module): ?string
    {
        $row = $this->record(Module::find($module), 'module');

        $state = Module::toggle((string) $row['uid']);

        $this->log(
            'module.toggled',
            ($state ? 'Enabled' : 'Disabled') . ' the ' . $row['name'] . ' module.'
        );

        return $this->done(
            'staff.modules',
            $row['name'] . ' ' . ($state ? 'enabled.' : 'disabled.')
        );
    }
}
