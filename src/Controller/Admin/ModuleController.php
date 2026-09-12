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
use LBM\Service\Module;
use LBM\Support\ModuleSettings;

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
        return 'settings';
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

            // Phase 40. A Configure link for the kinds configured on a page of
            // their own, when the module is loaded and declares something.
            // Resolved here rather than through Module::configurableClass(),
            // which reads every manifest on disk once per call.
            $class = $modules[$uid]['loaded']
                ? ModuleSettings::loadedClass((string) $module['type'], (string) $module['directory'])
                : null;

            $modules[$uid]['configurable'] = $class !== null
                && in_array((string) $module['type'], Module::configureTypes(), true)
                && ModuleSettings::fields($class) !== [];
        }

        return $this->screen('modules', local('modules'), [
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

    /**
     * One Module's Settings - Phase 40
     *
     * For lookup, fraud and plugin modules; the other kinds are configured on
     * a screen of their own, and asking for this page for one is a 404 rather
     * than a second place to save the same thing.
     *
     * GET behind module.read, POST behind module.update - the switch's gate.
     * @param string $module Module Uid
     * @return ?string
     */
    public function configure(string $module): ?string
    {
        $row = $this->record(Module::find($module), 'module');

        if (!in_array((string) $row['type'], Module::configureTypes(), true)) {
            $this->record(null, 'module');
        }

        $uid = (string) $row['uid'];

        if (Request::isPost()) {
            $input = Request::inputs();

            return $this->attempt(
                function () use ($uid, $row, $input): void {
                    Module::configure($uid, $input);

                    // The names that changed are not logged, and certainly not
                    // the values: a secret has no business in an audit trail.
                    $this->log('module.configured', 'Updated the settings of the ' . $row['name'] . ' module.');
                },
                'staff.module.configure',
                local('module_settings_saved'),
                ['module' => $uid]
            );
        }

        $form = Module::formFor($uid);

        return $this->screen('module-configure', local('configure_module', (string) $row['name']), [
            'module' =>  $row,
            'loaded' =>  Module::isLoaded($uid),
            'fields' =>  $form['fields'],
            'mode'   =>  $form['mode'],
        ]);
    }

    /**
     * Try a Module's Saved Settings - Phase 40
     *
     * The module's own words come back on the screen, success or not; a test
     * that fails is an answer, so it is not written to the error log.
     * @param string $module Module Uid
     * @return ?string
     */
    public function test(string $module): ?string
    {
        $row = $this->record(Module::find($module), 'module');
        $result = Module::testConnection((string) $row['uid']);

        $this->log('module.tested', 'Tested the connection of the ' . $row['name'] . ' module.');

        return $this->done(
            'staff.module.configure',
            local($result['success'] ? 'connection_ok' : 'connection_failed', $result['message']),
            $result['success'],
            ['module' => (string) $row['uid']]
        );
    }
}
