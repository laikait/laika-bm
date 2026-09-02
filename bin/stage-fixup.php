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
 * Rewrite the staged tree so it describes a shipped product rather than this
 * development machine.
 *
 * This is PHP rather than PowerShell for one reason: it edits JSON, and
 * Windows PowerShell 5.1's ConvertTo-Json defaults to -Depth 2, silently
 * truncating anything nested deeper into the string "System.Object[]". The
 * composer manifests here are five levels deep. Losing them quietly is exactly
 * the class of bug this whole phase exists to prevent.
 *
 * Everything it touches is verified afterwards by bin/verify-stage.php, which
 * is the actual gate. This script is allowed to be wrong; it is not allowed to
 * be wrong silently, so every step that could fail says so and exits non-zero.
 *
 * Usage: php bin/stage-fixup.php <staging-dir> <version> <laika-bm-sha>
 */

$stage   = $argv[1] ?? '';
$version = $argv[2] ?? '';
$sha     = $argv[3] ?? '';

if ($stage === '' || !is_dir($stage) || $version === '') {
    fwrite(STDERR, "usage: php bin/stage-fixup.php <staging-dir> <version> <laika-bm-sha>\n");
    exit(2);
}

$stage = rtrim(str_replace('\\', '/', realpath($stage)), '/');

function step(string $what): void
{
    echo "  - {$what}\n";
}

function fail(string $why): never
{
    fwrite(STDERR, "\n  FIXUP FAILED: {$why}\n\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// 1. A blank lf-config/database.php
// ---------------------------------------------------------------------------
//
// Overwritten, never deleted. Config::set() throws
// "Config File [database] Does Not Exist." when the file is absent, so removing
// it produces a wizard that dies at step 2 on every fresh install.

$skeleton = <<<'PHP'
<?php
/**
 * Laika PHP MVC Framework
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: MIT
 * This file is part of the Laika PHP MVC Framework.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

/*
 * Leave this file alone. The install wizard writes it.
 *
 * It must exist and be writable before installation begins - the wizard
 * rewrites it in place at the database step rather than creating it, and will
 * stop with "Config File [database] Does Not Exist." if it has been removed.
 *
 * Supported drivers: mysql, mariadb (as mysql), pgsql.
 */

return [
    'default' => [
        'driver' => 'mysql',
        'host' => 'localhost',
        'port' => 3306,
        'database' => '',
        'username' => '',
        'password' => '',
    ],
];

PHP;

if (file_put_contents($stage . '/lf-config/database.php', $skeleton) === false) {
    fail('could not write the blank lf-config/database.php');
}

step('lf-config/database.php reset to a blank skeleton');

// ---------------------------------------------------------------------------
// 2. The root composer.json must not point at the development tree
// ---------------------------------------------------------------------------

$file = $stage . '/composer.json';
$root = json_decode((string) file_get_contents($file), true);

if (!is_array($root)) {
    fail('the staged composer.json is not valid JSON');
}

// The path repository at ../git-repos/laika-bm cannot exist on an operator's
// machine, so any composer install would fail hard against it.
unset($root['repositories']);

// dev-main is a branch on a machine nobody else has. Say what actually shipped.
$root['require']['laikait/laika-bm'] = '^' . $version;

$written = file_put_contents(
    $file,
    json_encode($root, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
);

if ($written === false) {
    fail('could not rewrite composer.json');
}

step("composer.json: path repository dropped, laika-bm pinned to ^{$version}");

// ---------------------------------------------------------------------------
// 3. The two composer manifests that record where laika-bm came from
// ---------------------------------------------------------------------------
//
// composer.lock and vendor/composer/installed.json BOTH carry the path dist
// `"url": "../git-repos/laika-bm"`. The lock file was missed on the first
// build and the verifier caught it - which is the entire argument for having a
// verifier that greps rather than a checklist that trusts.

/**
 * Rewrite laika-bm's entry in a composer manifest so it describes a release.
 *
 * @param string $file  Absolute path to composer.lock or installed.json
 * @param string $label Name used in messages
 */
function fixPackageEntry(string $file, string $label): void
{
    global $version, $sha;

    if (!is_file($file)) {
        fail("{$label} is missing from the staged tree");
    }

    $json = json_decode((string) file_get_contents($file), true);

    if (!is_array($json) || !isset($json['packages'])) {
        fail("the staged {$label} is not valid JSON");
    }

    $found = false;

    foreach ($json['packages'] as $i => $package) {
        if (($package['name'] ?? '') !== 'laikait/laika-bm') {
            continue;
        }

        $found = true;

        $json['packages'][$i]['version'] = 'v' . $version;

        // installed.json carries a normalised form; composer.lock does not.
        if (isset($package['version_normalized'])) {
            $json['packages'][$i]['version_normalized'] = $version . '.0';
        }

        // A path dist and symlink transport options are development wiring.
        // The runtime autoloader does not read them; composer CLI does, and it
        // would report a package installed from a directory that is not there.
        unset(
            $json['packages'][$i]['dist'],
            $json['packages'][$i]['transport-options'],
            $json['packages'][$i]['installation-source']
        );

        if ($sha !== '') {
            $json['packages'][$i]['source'] = [
                'type'      =>  'git',
                'url'       =>  'https://github.com/laikait/laika-bm.git',
                'reference' =>  $sha,
            ];
        }

        // Mirror the floor the package itself now declares.
        $json['packages'][$i]['require']['laikait/laika-model'] = '^4.0.6';
    }

    if (!$found) {
        fail("laikait/laika-bm is not listed in the staged {$label}");
    }

    $written = file_put_contents(
        $file,
        json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
    );

    if ($written === false) {
        fail("could not rewrite {$label}");
    }

    step("{$label}: laika-bm recorded as v{$version}, path dist removed");
}

fixPackageEntry($stage . '/vendor/composer/installed.json', 'installed.json');
fixPackageEntry($stage . '/composer.lock', 'composer.lock');

// ---------------------------------------------------------------------------
// 4. vendor/composer/installed.php
// ---------------------------------------------------------------------------
//
// Edited as text on purpose. Loading it and re-exporting with var_export()
// would resolve its `__DIR__ . '/../laikait/laika-bm'` entries into absolute
// paths from THIS machine, which is precisely the portability the file
// currently has and must keep.
//
// So: a scoped replacement inside the laika-bm block only, then the file is
// re-parsed to prove it still works. A build that cannot prove that stops.

$file = $stage . '/vendor/composer/installed.php';
$body = (string) file_get_contents($file);
$anchor = "'laikait/laika-bm' => array(";
$at = strpos($body, $anchor);

if ($at === false) {
    fail('could not find the laika-bm block in installed.php');
}

$end = strpos($body, '),', $at);

if ($end === false) {
    fail('could not find the end of the laika-bm block in installed.php');
}

$block = substr($body, $at, $end - $at);
$fixed = str_replace("'dev-main'", "'v{$version}'", $block);

if ($fixed === $block) {
    step('installed.php: nothing to change (already versioned)');
} else {
    $body = substr_replace($body, $fixed, $at, $end - $at);

    if (file_put_contents($file, $body) === false) {
        fail('could not rewrite vendor/composer/installed.php');
    }

    // Prove it still parses AND still resolves paths relatively. A broken
    // installed.php takes the whole autoloader with it.
    $check = @include $file;

    if (!is_array($check) || !isset($check['versions']['laikait/laika-bm'])) {
        fail('installed.php no longer parses after the version rewrite');
    }

    if (!str_contains($body, '__DIR__')) {
        fail('installed.php lost its __DIR__ relative paths - the archive would not be portable');
    }

    step("installed.php: laika-bm reported as v{$version}");
}

// ---------------------------------------------------------------------------
// 5. Empty runtime directories
// ---------------------------------------------------------------------------
//
// Present but empty, not absent. The application writes into these and an
// operator on a locked-down host should get the directory (and its permissions)
// from the archive rather than hoping PHP can create it.

foreach ([
    'uploads',
    'lf-logs',
    'lf-storage/cache',
    'lf-storage/keys',
    'lf-storage/lbm',
    'lf-storage/queues',
    'lf-app/Controller',
    'lf-app/Filter',
    'lf-app/Job',
    'lf-app/Model',
    'lf-app/Pipeline',
    'lf-app/Relay',
    'lf-app/Schema',
    'lf-app/Service',
] as $dir) {
    $path = $stage . '/' . $dir;

    if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
        fail("could not create {$dir}/");
    }
}

step('empty runtime directories created');

// The queue's backing store ships empty rather than absent.
if (file_put_contents($stage . '/lf-storage/queues/jobs.json', '') === false) {
    fail('could not truncate lf-storage/queues/jobs.json');
}

step('lf-storage/queues/jobs.json truncated');

echo "\n";
exit(0);
