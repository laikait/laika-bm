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
 * The gate. Nothing is zipped until this passes.
 *
 * Every check here is a failure that looks completely fine on the build machine
 * and only shows up on somebody else's server, after they have downloaded it.
 * That asymmetry is the whole reason a build script exists instead of a person
 * right-clicking a folder.
 *
 * Two directions of failure, and both matter:
 *
 *   - shipping too much - this machine's encryption key, its database
 *     credentials, its install lock, its logs
 *   - shipping too little - the .htaccess files (which `.gitignore` lists as
 *     `*.htaccess`), the vendor tree, the product code behind the junction
 *
 * The second is the one that hurts, because an over-eager exclusion rule is
 * invisible: the archive builds, the zip has a sensible size, and the operator
 * finds out.
 *
 * Usage: php bin/verify-stage.php <staging-dir>
 * Exits 0 when the staging tree is fit to ship, 1 otherwise.
 */

$stage = $argv[1] ?? '';

if ($stage === '' || !is_dir($stage)) {
    fwrite(STDERR, "usage: php bin/verify-stage.php <staging-dir>\n");
    exit(2);
}

$stage = rtrim(str_replace('\\', '/', realpath($stage)), '/');

$problems = [];
$checked  = 0;

/** Record a failure. The message is read by somebody who did not write this. */
function fault(string $message): void
{
    global $problems;
    $problems[] = $message;
}

function must_exist(string $rel, string $why): void
{
    global $stage, $checked;
    $checked++;

    if (!file_exists($stage . '/' . $rel)) {
        fault("MISSING  {$rel}\n           {$why}");
    }
}

function must_not_exist(string $rel, string $why): void
{
    global $stage, $checked;
    $checked++;

    if (file_exists($stage . '/' . $rel)) {
        fault("PRESENT  {$rel}\n           {$why}");
    }
}

/**
 * A check that is not simply "does this path exist".
 *
 * The two above cover most of this file, but a few things - DEBUG's value, what
 * a directory contains - are conditions rather than paths. They still have to be
 * counted, or the total the build prints understates what was verified.
 */
function must(bool $ok, string $message): void
{
    global $checked;
    $checked++;

    if (!$ok) {
        fault($message);
    }
}


// ---------------------------------------------------------------------------
// 1. Secrets and per-install state
// ---------------------------------------------------------------------------

must_not_exist(
    'lf-storage/keys/app.key',
    'Every install would share one CSRF and encryption key. App\Key::fix() regenerates it on first request.'
);

must_not_exist(
    'lf-storage/lbm/install.lock',
    'Its presence means "installed". Shipping it closes the wizard on a fresh download.'
);

must_not_exist('lf-logs/error.log', 'Logs can carry request data.');

// ---------------------------------------------------------------------------
// 2. The database config must exist AND be blank
// ---------------------------------------------------------------------------
//
// Both halves are load-bearing. Config::set() throws
// "Config File [database] Does Not Exist." if the file is absent, so deleting
// it kills the wizard at step 2 - the exclusion list said to drop this file and
// that would have shipped an installer that cannot start.

$checked++;
$dbFile = $stage . '/lf-config/database.php';

if (!is_file($dbFile)) {
    fault("MISSING  lf-config/database.php\n           Config::set() throws if it is absent - the wizard dies at step 2.");
} else {
    $raw = (string) file_get_contents($dbFile);

    // Read as text, not by including it: the file guards on APP_PATH and would
    // die() on a bare include.
    foreach (['database', 'username', 'password'] as $key) {
        $checked++;

        if (preg_match("/'{$key}'\s*=>\s*'([^']+)'/", $raw, $m)) {
            fault("CREDENTIAL  lf-config/database.php still sets {$key}\n           The shipped skeleton must be blank; the wizard writes the real one.");
        }
    }

    $checked++;
    if (str_contains($raw, 'cloud')) {
        fault("CREDENTIAL  lf-config/database.php mentions the dev database name.");
    }
}

// ---------------------------------------------------------------------------
// 3. The product code must be real files, not a reparse point
// ---------------------------------------------------------------------------
//
// vendor/laikait/laika-bm is a junction during development. Some zip tools
// follow it and some store the reparse point, and the second produces an
// archive with no product code in it at all - which looks perfectly normal
// until somebody extracts it.

$pkg = $stage . '/vendor/laikait/laika-bm';

$checked++;
if (!is_dir($pkg)) {
    fault("MISSING  vendor/laikait/laika-bm\n           The product itself. The junction was not resolved.");
} else {
    // Containment, not is_link(). Measured on this machine: is_link() answers
    // FALSE for a real Windows junction, and readlink() answers a path for a
    // perfectly ordinary directory (its own). Either test alone is useless -
    // one misses every junction, the other flags everything.
    //
    // realpath() follows a junction, so the question that actually separates
    // them is where the directory lands: inside the staging tree, or back out
    // in the development checkout.
    $checked++;
    $resolved = realpath($pkg);

    if ($resolved === false) {
        fault("BROKEN   vendor/laikait/laika-bm does not resolve\n           A dangling reparse point ships as an empty directory.");
    } elseif (!str_starts_with(str_replace('\\', '/', $resolved), $stage)) {
        fault("REPARSE  vendor/laikait/laika-bm resolves to {$resolved}\n           That is outside the staging tree, so it is still a link. The archive would contain no product code.");
    }

    // A directory can exist and be empty. Name a file that must be inside it.
    must_exist('vendor/laikait/laika-bm/src/Support/Version.php', 'The product source did not come across.');
    must_exist('vendor/laikait/laika-bm/helpers/loader.php', 'Without this the package registers no resources at all.');
    must_not_exist('vendor/laikait/laika-bm/.git', 'Development artefact.');
}

// ---------------------------------------------------------------------------
// 4. The .htaccess files - the ones .gitignore would have eaten
// ---------------------------------------------------------------------------
//
// `.gitignore` lists `*.htaccess`. modules/.htaccess (Require all denied) has
// been the PRIMARY control keeping module source off the web since laika-route
// v2.0.2 dropped its allowlist. Building from git semantics drops it silently.

must_exist('.htaccess', 'The front controller rewrite. Without it nothing routes.');
must_exist('lf-storage/.htaccess', 'Keeps storage off the web.');
must_exist('modules/.htaccess', 'PRIMARY control denying module source over the web (Phase 14.2).');

// ---------------------------------------------------------------------------
// 5. Things that ship and are easy to lose
// ---------------------------------------------------------------------------

must_exist('vendor/autoload.php', 'The zip is prebuilt; composer never runs on the operator machine.');
must_exist('vendor/composer/installed.php', 'Package discovery reads this.');
must_exist('composer.lock', 'gitignored, but it is the record of exactly what shipped.');

// assets/ is NOT dead: app_logo()/app_icon() resolve to assets/img/, and the
// admin footer loads bootstrap from assets/js/.
must_exist('assets/img/logo.png', 'Default logo - app_logo() resolves here.');
must_exist('assets/img/icon.png', 'Default favicon - app_icon() resolves here.');
must_exist('assets/js/bootstrap.bundle.min.js', 'The admin template footer loads this.');

must_exist('index.php', 'The front controller.');
must_exist('cron.php', 'The entire scheduled-task story.');
must_exist('nginx.conf', 'Carries the modules/ deny for nginx deployments.');
must_exist('modules/README.md', 'The module contract.');

foreach (['front', 'admin', 'panel', 'install'] as $area) {
    must_exist("lf-lang/{$area}", "The {$area} catalogue. local() throws on a missing key - no fallback.");
}

foreach (['front', 'admin', 'panel', 'install'] as $area) {
    must_exist("template/{$area}", "The {$area} template.");
}

// ---------------------------------------------------------------------------
// 6. Development artefacts that must not ship
// ---------------------------------------------------------------------------

must_not_exist('.github', 'CI for the framework skeleton.');
must_not_exist('docs', 'Documents the framework, not the product.');
must_not_exist('.gitignore', 'Development artefact - and it lists *.htaccess, which misleads.');
must_not_exist('.git', 'Development artefact.');
must_not_exist('composer.phar', 'Development artefact.');

// The CLI entrypoints. Developer tools: the operator path is the web wizard, a
// scheduled cron.php, and /admin/utils/update, which runs Installer::migrate()
// in process. Asserted rather than left to the exclude list, because that list
// is one line in a PowerShell array and this is the only thing that would
// notice it being edited back.
must_not_exist('laika', 'CLI entrypoint - a developer tool. Nothing an operator does may need it.');
must_not_exist('worker', 'Queue worker CLI - a developer tool. cron.php drains the queue.');

// ---------------------------------------------------------------------------
// 6b. DEBUG must be off
// ---------------------------------------------------------------------------
//
// The single most damaging thing that could ship switched on. Handler.php puts
// $e->getMessage() into the response when DEBUG is true, so an exception on an
// operator's public site shows the visitor a stack trace and a file path. It
// also decides whether Resource.php reads the compiled manifest at all, so a
// debug build re-discovers every class on every request.
//
// stage-fixup.php rewrites it. This is what proves the rewrite happened, since
// a regex that silently matched nothing looks exactly like success.
$constFile = $stage . '/lf-inc/const.php';
$const = is_file($constFile) ? (string) file_get_contents($constFile) : '';

must(
    $const !== '',
    "MISSING  lf-inc/const.php\n           The framework constants. Nothing boots without it."
);

must(
    $const === '' || preg_match("/define\('DEBUG',\s*true\s*\)/i", $const) !== 1,
    "DEBUG    lf-inc/const.php ships with DEBUG on\n           Exception messages and stack traces would reach visitors, and the resource manifest would never be read."
);

must(
    $const === '' || preg_match("/define\('DEBUG',\s*false\s*\)/i", $const) === 1,
    "DEBUG    lf-inc/const.php does not define DEBUG as a literal false\n           The fixup rewrite did not take. Check bin/stage-fixup.php."
);


// ---------------------------------------------------------------------------
// 6c. Exactly one template per area
// ---------------------------------------------------------------------------
//
// admin/dark and admin/plain were byte-copies of admin/bootstrap that areawalk
// made and never removed, distinguished only by a marker comment. They shipped:
// about a megabyte and 144 twig files of nothing, offered in the admin template
// picker as themes that were not themes, and already stale - neither carried
// 20.2's upload card.
//
// The gate had no reason to catch that, because it only ever looked for files it
// knew the names of. This looks for the opposite: anything present that should
// not be.
foreach (['admin', 'panel', 'front'] as $area) {
    $dir = $stage . '/template/' . $area;

    if (!is_dir($dir)) {
        continue;
    }

    $names = array_values(array_filter(
        scandir($dir) ?: [],
        static fn(string $e): bool => $e !== '.' && $e !== '..' && is_dir($dir . '/' . $e)
    ));

    $extra = array_values(array_diff($names, ['bootstrap']));

    must(
        $extra === [],
        "LITTER   template/{$area} carries " . implode(', ', $extra)
            . "\n           A release ships one template per area. Copies made by a harness are litter, and the operator is offered them as themes."
    );
}


// lf-app sample code. The directories stay (PSR-4 App\ is mapped there); the
// framework skeleton's demo classes do not.
foreach ([
    'lf-app/Controller/HomeController.php',
    'lf-app/Filter/LogFilter.php',
    'lf-app/Job/WriteLog.php',
    'lf-app/Pipeline/HomePipeline.php',
    'lf-app/Relay/SampleRelay.php',
    'lf-app/Service/SampleService.php',
] as $sample) {
    must_not_exist($sample, 'Framework skeleton sample. Nothing references the App\ namespace.');
}

must_exist('lf-app', 'The directory stays - composer maps App\ to it.');

// ---------------------------------------------------------------------------
// 7. Empty-but-present directories
// ---------------------------------------------------------------------------

foreach (['uploads', 'lf-logs', 'lf-storage/queues'] as $dir) {
    $checked++;

    if (!is_dir($stage . '/' . $dir)) {
        fault("MISSING  {$dir}/\n           Must ship as an empty directory, not be absent.");
        continue;
    }

    $left = array_diff(scandir($stage . '/' . $dir) ?: [], ['.', '..', '.htaccess', 'jobs.json']);

    if ($left !== []) {
        fault("DIRTY    {$dir}/ still contains: " . implode(', ', $left));
    }
}

$checked++;
$jobs = $stage . '/lf-storage/queues/jobs.json';

if (is_file($jobs) && filesize($jobs) > 0) {
    fault('DIRTY    lf-storage/queues/jobs.json is not empty - it carries this machine\'s queue.');
}

$checked++;
$cache = $stage . '/lf-storage/cache';

if (is_dir($cache) && array_diff(scandir($cache) ?: [], ['.', '..']) !== []) {
    fault('DIRTY    lf-storage/cache/ still has contents - compiled templates and the resource manifest.');
}

// ---------------------------------------------------------------------------
// 8. No reference to the development tree, anywhere
// ---------------------------------------------------------------------------
//
// composer.json declares a path repository at ../git-repos/laika-bm and
// installed.json records the same as a dist url. On an operator's machine that
// path cannot exist, so any composer install fails hard.

$checked++;
$hits = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);

foreach ($iterator as $file) {
    /** @var SplFileInfo $file */
    if (!$file->isFile() || $file->getSize() > 2_000_000) {
        continue;
    }

    if (!in_array(strtolower($file->getExtension()), ['php', 'json', 'lock', 'md', 'conf', 'twig', 'ps1'], true)) {
        continue;
    }

    $body = (string) @file_get_contents($file->getPathname());

    if (str_contains($body, 'git-repos')) {
        $hits[] = ltrim(str_replace([$stage, '\\'], ['', '/'], $file->getPathname()), '/');
    }
}

if ($hits !== []) {
    fault("DEV PATH  'git-repos' appears in:\n           - " . implode("\n           - ", array_slice($hits, 0, 10)));
}

// composer.json must be honest about what is installed.
$checked++;
$manifest = json_decode((string) @file_get_contents($stage . '/composer.json'), true);

if (!is_array($manifest)) {
    fault('BROKEN   composer.json is not valid JSON after staging.');
} else {
    $checked++;
    if (isset($manifest['repositories'])) {
        fault('DEV PATH  composer.json still declares a path repository.');
    }

    $checked++;
    $constraint = $manifest['require']['laikait/laika-bm'] ?? '';

    if ($constraint === '' || str_contains($constraint, 'dev-')) {
        fault("UNPINNED  composer.json requires laika-bm as '{$constraint}' - pin the released version.");
    }
}

// ---------------------------------------------------------------------------

echo "\n";

if ($problems === []) {
    echo "  Stage verified: {$checked} checks, no problems.\n\n";
    exit(0);
}

echo "  RELEASE BLOCKED - " . count($problems) . " problem(s) across {$checked} checks:\n\n";

foreach ($problems as $p) {
    echo "  - {$p}\n";
}

echo "\n  No archive was written.\n\n";
exit(1);
