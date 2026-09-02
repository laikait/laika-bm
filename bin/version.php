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

$root = $argv[1] ?? '';
$autoload = rtrim(str_replace('\\', '/', $root), '/') . '/vendor/autoload.php';

if ($root === '' || !is_file($autoload)) {
    fwrite(STDERR, "usage: php bin/version.php <app-root>   (needs <app-root>/vendor/autoload.php)\n");
    exit(2);
}

require_once $autoload;

if (!class_exists(LBM\Support\Version::class)) {
    fwrite(STDERR, "LBM\\Support\\Version is not autoloadable from {$root}\n");
    exit(1);
}

echo LBM\Support\Version::CURRENT;
exit(0);
