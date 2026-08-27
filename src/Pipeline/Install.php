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

namespace LBM\Pipeline;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Laika\Service\Url;
use Laika\Service\AppKey;
use Laika\Service\Redirect;
use Laika\Route\Contracts\PipelineInterface;

/**
 * Install gate.
 *
 * Registered first in Url::globalPipeline(), ahead of GlobalPipeline, because
 * it is what keeps a fresh checkout reachable: until the installer has run
 * there is no database, so nothing that reads `options` may execute before this
 * has had a chance to divert the request to /install.
 *
 * Install state is a file, not an option row - the database is exactly the
 * thing that does not exist yet on a first run.
 */
class Install implements PipelineInterface
{
    /** @var string Lock File, Relative to APP_PATH */
    public const LOCK = '/lf-storage/lbm/install.lock';

    /** @var string First URL Segment Owned By The Installer */
    public const SEGMENT = 'install';

    /** @var string Route Name The Wizard Starts At */
    public const ROUTE = 'install';

    /** @var string Route Name To Fall Back To Once Installed */
    public const INSTALLED_ROUTE = 'staff.dashboard';

    /**
     * Handle The Request
     * @param callable $next Next Pipeline
     * @param array $params Route Parameters
     * @return ?string
     */
    public function handle(callable $next, array &$params): ?string
    {
        // CSRF needs an app key, and every installer step is a POST - so the key
        // has to exist before the wizard renders its first form, not after.
        AppKey::fix();

        $installed = self::isInstalled();
        $installer = self::isInstallerRequest();

        // Fresh checkout: everything funnels into the wizard.
        //
        // Redirect::to() resolves a route NAME, not a path - Handler::namedUrl()
        // throws on anything it does not recognise - so both targets here are
        // names declared in helpers/routes/.
        if (!$installed && !$installer) {
            Redirect::to(self::ROUTE);
        }

        // Already installed: the wizard is closed for good. Re-running it needs
        // the lock file removed by hand, or `php laika lbm:install --force`.
        if ($installed && $installer) {
            Redirect::to(self::INSTALLED_ROUTE);
        }

        // Threaded through so GlobalPipeline knows whether it may touch the
        // database, and so installer views can render a step without re-checking.
        $params['installed'] = $installed;

        return $next();
    }

    /**
     * Whether The App Has Been Installed
     * @return bool
     */
    public static function isInstalled(): bool
    {
        return is_file(APP_PATH . self::LOCK);
    }

    /**
     * Whether The Current Request Belongs To The Installer
     * @return bool
     */
    public static function isInstallerRequest(): bool
    {
        return Url::segment(1) === self::SEGMENT;
    }
}
