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

/**
 * Print the product version, and nothing else.
 *
 * A file rather than an inline `php -r`, because the constant's namespace is
 * `LBM\Support\Version` and getting those backslashes through PowerShell's
 * quoting intact is a coin toss. This also means the build reads the constant
 * the same way the application does - by loading it - rather than by pattern
 * matching the source, which would happily match a version number written in a
 * comment.
 *
 * Usage: php bin/version.php <app-root>
 */

$root = rtrim(str_replace('\\', '/', $argv[1] ?? ''), '/');
$boot = $root . '/lf-boot/app.php';

if ($root === '' || !is_file($boot)) {
    fwrite(STDERR, "usage: php bin/version.php <app-root>   (needs <app-root>/lf-boot/app.php)\n");
    exit(2);
}

// lf-boot/app.php, not vendor/autoload.php directly. Every LBM file opens with
// `defined('APP_PATH') || ... die('403 Direct Access Denied!')`, and composer's
// files autoload runs helpers/loader.php, which calls ModuleManager::discover()
// - so requiring the autoloader on its own loads ModuleManager with APP_PATH
// still undefined and the guard kills the process.
//
// It dies with exit code 0 and prints the 403 text to stdout, so the caller
// reads "403 Direct Access Denied!" as if it were the version. Booting properly
// is the fix; this is the same sequence cron.php uses.
require_once $boot;

if (!class_exists(LBM\Support\Version::class)) {
    fwrite(STDERR, "LBM\\Support\\Version is not autoloadable from {$root}\n");
    exit(1);
}

echo LBM\Support\Version::CURRENT;
exit(0);
