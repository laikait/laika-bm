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

/**
 * What version of the product this is.
 *
 * ------------------------------------------------------------------------
 * Why a constant in the package rather than a file in the app root
 * ------------------------------------------------------------------------
 * The app root is not version controlled - it is published as a zip, and its
 * only copy is the working directory. Anything written there is a build
 * artefact and cannot be reviewed, diffed or blamed. The package is the thing
 * that is actually versioned, so the version number belongs beside the code it
 * describes.
 *
 * It also means a dev checkout knows its own version. A VERSION file written at
 * build time would exist only inside built zips, so the admin dashboard would
 * show nothing at all on the machine where the product is developed - which is
 * exactly where a wrong version number is easiest to notice and cheapest to fix.
 *
 * ------------------------------------------------------------------------
 * bin/release.ps1 reads this rather than taking a -Version argument
 * ------------------------------------------------------------------------
 * One source of truth. A build cannot name itself `lbm-1.1.0.zip` while the
 * application inside it reports 1.0.0, because there is nowhere for the two to
 * disagree. Bump this constant in the same commit that tags laika-bm.
 *
 * Read it with:
 *
 *     php bin/version.php <app-root>
 *
 * NOT by requiring vendor/autoload.php directly. Composer's files autoload runs
 * helpers/loader.php, which calls ModuleManager::discover() before APP_PATH is
 * defined - so every LBM file's guard fires and the process die()s having
 * printed `403 Direct Access Denied!` to stdout with exit code 0. A caller
 * reads that string as the version. bin/version.php boots lf-boot/app.php the
 * way cron.php does, which is why it exists as a file at all.
 *
 * ------------------------------------------------------------------------
 * Not derived from Composer
 * ------------------------------------------------------------------------
 * `InstalledVersions::getPrettyVersion('laikait/laika-bm')` would look tidier
 * and answers `dev-main` on this machine, because the package is wired in
 * through a path repository during development. A version string that is
 * accurate only in the artefact and misleading everywhere else is worse than no
 * version string, so the number is stated outright.
 */
final class Version
{
    /**
     * @var string The Product Version
     *
     * Semantic versioning: MAJOR.MINOR.PATCH. Bumped by hand at release time.
     */
    public const CURRENT = '1.1.0';

    /**
     * @var string Human Readable Product Name
     *
     * Deliberately separate from `app_name`, which is an option the operator
     * owns and routinely changes to their own company name. This one names the
     * software, and a support conversation needs both.
     */
    public const PRODUCT = 'Laika Bill Manager';
}
