<?php
/**
 * Laika Bill Manager - release build, portable edition (Phase 49).
 *
 * Produces dist/lbm-<version>.zip from the app root. The same build as
 * bin/release.ps1, in PHP, so it runs wherever the product does - the move to
 * Linux left the PowerShell script unusable, and a release that only one
 * machine can build is one machine away from not being buildable at all.
 *
 * ---------------------------------------------------------------------------
 * Why this exists
 * ---------------------------------------------------------------------------
 * Hand-zipping the app root fails in two directions, and both only show up
 * after somebody has downloaded the result:
 *
 *   - too much - this machine's encryption key, database credentials, install
 *     lock and logs
 *   - too little - vendor/laikait/laika-bm is a SYMLINK (a junction on
 *     Windows), and zip tools disagree about those. Some follow them, some
 *     store the link, which ships an archive with no product code in it at all
 *     and looks completely normal locally
 *
 * So: copy to a staging tree with the link resolved to real files, rewrite
 * what describes this machine, then refuse to write an archive unless
 * bin/verify-stage.php passes.
 *
 * ---------------------------------------------------------------------------
 * Zip entry names
 * ---------------------------------------------------------------------------
 * Always forward slashes. The PowerShell build used .NET's ZipFile on 5.1,
 * which stores `lf-app\Controller\...` - and Linux unzip and PHP's ZipArchive
 * then extract that as ONE file with backslashes in its name, in the root. The
 * archive is re-read after writing and rejected if any entry has a backslash.
 *
 * The version is never passed in - it is read from LBM\Support\Version::CURRENT
 * so a zip cannot be named after a version the application inside it disagrees
 * with. Bump the constant, commit, then build.
 *
 * Usage:
 *   php bin/release.php
 *   php bin/release.php --app-root=/var/www/cloud --output=/tmp/dist
 *   php bin/release.php --allow-dirty
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('403 Direct Access Denied!');
}

$repoRoot = dirname(__DIR__);

$opts = getopt('', ['app-root:', 'output:', 'allow-dirty']);
$appRoot = rtrim((string) ($opts['app-root'] ?? dirname($repoRoot, 2) . '/cloud'), '/');
$outputDir = rtrim((string) ($opts['output'] ?? $repoRoot . '/dist'), '/');
$allowDirty = isset($opts['allow-dirty']);

function step(string $text): void { echo "\n==> {$text}\n"; }
function note(string $text): void { echo "    {$text}\n"; }

function stop_build(string $reason): never
{
    fwrite(STDERR, "\n  BUILD ABORTED\n  {$reason}\n\n");
    exit(1);
}

/**
 * Run a command, echoing nothing, and hand back its output and exit code.
 * @param string[] $argv
 * @return array{0:string,1:int}
 */
function run(array $argv): array
{
    $cmd = implode(' ', array_map('escapeshellarg', $argv));
    exec($cmd . ' 2>&1', $lines, $code);

    return [implode("\n", $lines), $code];
}

/**
 * Remove a directory tree without following links out of it.
 */
function remove_tree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }

    if (!is_dir($path)) {
        return;
    }

    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
        remove_tree($path . '/' . $entry);
    }

    rmdir($path);
}

/**
 * Copy a tree as real files.
 *
 * Symlinks are skipped, never followed - the one that matters, the package,
 * is copied separately and deterministically from the repo. Empty directories
 * are kept: verify-stage.php checks for some of them.
 *
 * @param string   $from        Source directory
 * @param string   $to          Destination directory
 * @param string[] $skipNames   Directory names skipped at any depth
 * @param string[] $skipPaths   Paths relative to $from skipped exactly (dirs or files)
 */
function copy_tree(string $from, string $to, array $skipNames, array $skipPaths, string $rel = ''): void
{
    if (!is_dir($to) && !mkdir($to, 0775, true)) {
        stop_build("could not create {$to}");
    }

    foreach (array_diff(scandir($from) ?: [], ['.', '..']) as $entry) {
        $src = $from . '/' . $entry;
        $relPath = ltrim($rel . '/' . $entry, '/');

        if (in_array($relPath, $skipPaths, true) || is_link($src)) {
            continue;
        }

        if (is_dir($src)) {
            if (in_array($entry, $skipNames, true)) {
                continue;
            }

            copy_tree($src, $to . '/' . $entry, $skipNames, $skipPaths, $relPath);
            continue;
        }

        if (!copy($src, $to . '/' . $entry)) {
            stop_build("could not copy {$relPath}");
        }
    }
}

// ---------------------------------------------------------------------------
step('Checking the source tree');
// ---------------------------------------------------------------------------

if (!is_file($appRoot . '/index.php')) {
    stop_build("No index.php under {$appRoot} - that is not an app root. Pass --app-root.");
}

// The product ships from the link target, so the repo being built had better
// be the one wired into the app. Shipping code that was never the code under
// test is the quietest possible mistake.
$linkPath = $appRoot . '/vendor/laikait/laika-bm';

if (!file_exists($linkPath)) {
    stop_build("{$linkPath} does not exist - run composer install first.");
}

if (is_link($linkPath)) {
    if (realpath($linkPath) !== realpath($repoRoot)) {
        stop_build("The app's link points at '" . realpath($linkPath) . "', not at this repo ('{$repoRoot}'). You would ship code you did not build.");
    }

    note('link verified -> ' . realpath($linkPath));
} else {
    note('vendor/laikait/laika-bm is already real files');
}

// A release nobody can reproduce from a commit is not a release.
[$dirty, $code] = run(['git', '-C', $repoRoot, 'status', '--porcelain']);

if ($code !== 0) {
    stop_build("git could not read {$repoRoot}");
}

if (trim($dirty) !== '') {
    if (!$allowDirty) {
        echo "\n    Uncommitted changes in the package:\n";
        foreach (explode("\n", $dirty) as $line) {
            echo "      {$line}\n";
        }
        stop_build('Commit them, or re-run with --allow-dirty for a throwaway build.');
    }

    note('WARNING: building a dirty tree (--allow-dirty)');
}

[$sha] = run(['git', '-C', $repoRoot, 'rev-parse', 'HEAD']);
$sha = trim($sha);
note("laika-bm at {$sha}");

// ---------------------------------------------------------------------------
step('Reading the version');
// ---------------------------------------------------------------------------

[$version, $code] = run([PHP_BINARY, __DIR__ . '/version.php', $appRoot]);
$version = trim($version);

if ($code !== 0) {
    stop_build('Could not read LBM\Support\Version::CURRENT');
}

if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
    stop_build("Version::CURRENT is '{$version}', which is not MAJOR.MINOR.PATCH.");
}

note("building version {$version}");

// ---------------------------------------------------------------------------
step('Staging');
// ---------------------------------------------------------------------------

$stage = rtrim(sys_get_temp_dir(), '/') . "/lbm-release-{$version}";

remove_tree($stage);
note("staging in {$stage}");

// Directories and files that never ship - the same list as release.ps1.
//
// '.git' is by name so it matches at any depth, including inside vendor
// packages; everything else is a path from the app root so a vendor package's
// own 'docs' directory is not collateral damage.
//
// 'laika' and 'worker' are the CLI entrypoints, and they are developer tools.
// The operator path is the web wizard, a scheduled cron.php, and the update
// utility at /admin/settings/utils/update. verify-stage.php asserts both are
// absent from the stage, so an edit here cannot quietly put them back.
//
// The lf-app entries are the framework skeleton's demo classes - nothing
// references the App\ namespace - but their directories stay, because
// composer maps App\ to lf-app/.
copy_tree($appRoot, $stage, ['.git'], [
    '.github',
    'docs',
    'lf-logs',
    'lf-storage/cache',
    'lf-storage/keys',
    'uploads',
    '.gitignore',
    'composer.phar',
    'server.conf',
    'laika',
    'worker',
    'lf-storage/lbm/install.lock',
    'lf-storage/queues/jobs.json',
    // Phase 52: the firewall's and the sign-in throttle's live counters.
    'lf-storage/shield',
    'lf-app/Controller/HomeController.php',
    'lf-app/Filter/LogFilter.php',
    'lf-app/Job/WriteLog.php',
    'lf-app/Pipeline/HomePipeline.php',
    'lf-app/Relay/SampleRelay.php',
    'lf-app/Service/SampleService.php',
    'vendor/laikait/laika-bm',
]);

note('app root copied');

// The product, as real files.
// tests/, tools/ and phpunit.xml.dist are the test suite and its runner (Phase 51):
// development only, and a PHPUnit phar on an operator's server is an attack surface.
copy_tree($repoRoot, $stage . '/vendor/laikait/laika-bm', ['.git', 'dist', 'bin'], ['tests', 'tools', 'phpunit.xml.dist']);

note('laika-bm resolved to real files');

// ---------------------------------------------------------------------------
step('Rewriting what describes this machine');
// ---------------------------------------------------------------------------

passthru(implode(' ', array_map('escapeshellarg', [PHP_BINARY, __DIR__ . '/stage-fixup.php', $stage, $version, $sha])), $code);

if ($code !== 0) {
    stop_build('stage fixup failed');
}

// ---------------------------------------------------------------------------
step('Verifying the stage');
// ---------------------------------------------------------------------------

passthru(implode(' ', array_map('escapeshellarg', [PHP_BINARY, __DIR__ . '/verify-stage.php', $stage])), $code);

if ($code !== 0) {
    stop_build('The staged tree is not fit to ship. Nothing was written.');
}

// ---------------------------------------------------------------------------
step('Writing the archive');
// ---------------------------------------------------------------------------

if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true)) {
    stop_build("could not create {$outputDir}");
}

$zipPath = "{$outputDir}/lbm-{$version}.zip";

if (is_file($zipPath)) {
    unlink($zipPath);
}

$zip = new ZipArchive();

if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
    stop_build("could not open {$zipPath} for writing");
}

$files = 0;
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($iterator as $item) {
    /** @var SplFileInfo $item */
    // Built by hand from '/', never from the OS separator - see the header.
    $name = str_replace('\\', '/', substr($item->getPathname(), strlen($stage) + 1));

    if ($item->isDir()) {
        $zip->addEmptyDir($name);
        continue;
    }

    $zip->addFile($item->getPathname(), $name);
    $files++;
}

if (!$zip->close()) {
    stop_build("could not finish writing {$zipPath}");
}

// Read it back. The archive, not the stage, is what an operator unpacks.
$check = new ZipArchive();

if ($check->open($zipPath) !== true) {
    stop_build("{$zipPath} was written but cannot be opened");
}

for ($i = 0; $i < $check->numFiles; $i++) {
    $entry = (string) $check->getNameIndex($i);

    if (str_contains($entry, '\\')) {
        $check->close();
        unlink($zipPath);
        stop_build("Entry '{$entry}' has a backslash - it would extract as one file named with backslashes. Nothing was kept.");
    }
}

$check->close();

$size = round(filesize($zipPath) / 1048576, 1);

remove_tree($stage);

echo "\n";
note("lbm-{$version}.zip  ({$size} MB, {$files} files)");
note($zipPath);
note("built from laika-bm {$sha}");
note('archive contents sit at the root - extract into an empty web directory');
echo "\n";

exit(0);
