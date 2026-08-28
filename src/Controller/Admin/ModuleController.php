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

        // The enabled flag is an option per module rather than something on the
        // manifest, so it is looked up here rather than being on disk.
        foreach ($modules as $uid => $module) {
            $modules[$uid]['enabled'] = Module::isEnabled((string) $uid);
        }

        return $this->screen('admin/modules', 'Modules', [
            'modules' =>  $modules,
            'types'   =>  Module::types(),
            'path'    =>  Module::path(),
        ]);
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
