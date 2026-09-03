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

namespace LBM\Module;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Throwable;
use Composer\Autoload\ClassLoader;
use Laika\Queue\Abstracts\Job;
use Laika\Core\App\Resource;
use Laika\Cli\Contracts\CommandInterface;
use Laika\Model\Contract\SchemaAbstract;
use Laika\Route\Contracts\FilterInterface;
use Laika\Route\Contracts\PipelineInterface;
use LBM\Contract\MigrationAbstract;

/**
 * Loads what is installed in the app root's `modules/` directory.
 *
 * `LBM\Action\Module` reads manifests and remembers which are switched on, for
 * the admin screen. This class is the other half: it takes the ones that are on
 * and makes them part of the application - autoloadable classes, routes the
 * dispatcher will match, schemas `app:migrate` will create, hooks the templates
 * can call.
 *
 * ## Why this runs during autoload
 *
 * `Dispatcher::dispatch()` requires every route file *before* it matches a route
 * and therefore before any pipeline runs. So a module's routes have to be
 * registered earlier than that, and the only place earlier is composer's `files`
 * autoload - which is where `helpers/loader.php` calls discover().
 *
 * ## Why the enabled flag comes from a file and not the database
 *
 * At that moment there is no database and no `option()`: laika-core's helper
 * functions are loaded by `lf-boot/app.php` *after* the autoloader, and on a
 * fresh checkout there is no database at all. Reading `options` here would mean
 * a query on every request before the app has decided whether it is even
 * installed, and an exception on every request during install.
 *
 * So the `options` table stays the source of truth, and this reads a small
 * generated cache beside it. `LBM\Action\Module::toggle()` rewrites the cache
 * the moment somebody flips a switch, and `GlobalPipeline` rebuilds it if it has
 * gone missing - by then the database is open and `option()` exists. A cleared
 * `lf-cache` therefore costs one request of lag, not a broken install.
 *
 * ## What a disabled module costs
 *
 * A `glob()` and nothing else. Its manifest is not read, its classes are not
 * autoloadable, its routes do not exist and `app:migrate` cannot see its
 * schemas.
 */
class ModuleManager
{
    /** @var string The Generated Cache Of Enabled Module Uids */
    public const CACHE = '/lf-storage/cache/lbm-modules.php';

    /** @var string Where Modules Live, Below The App Root */
    public const ROOT = '/modules';

    /** @var string The File That Makes a Directory a Module */
    public const MANIFEST = 'module.php';

    /**
     * @var string[] The Kinds Of Module, Which Are Also The Subdirectories
     *
     * **This is the single source of truth.** `LBM\Action\Module` aliases it
     * rather than restating it, because the two lists had drifted: this one
     * carried `plugins` and the other carried `fraud` and `widgets`, so a module
     * in `modules/fraud` was listed by the admin screen and then never loaded,
     * while one in `modules/plugins` loaded but was never listed. Neither
     * failure said anything.
     *
     * `plugins` is gone (2026-09-03) - addons fill that role - and so is
     * `widgets`, which was an empty directory the loader never knew about.
     */
    public const TYPES = ['fraud', 'addons', 'gateways', 'servers', 'registrars'];

    /**
     * @var array<string,?string> The Contract Each Resource Kind Must Satisfy
     *
     * The same contracts LBM's own resources are registered under, so a module
     * shipping a schema that does not extend SchemaAbstract is refused at
     * registration rather than fataling in the middle of a migration.
     */
    private const CONTRACTS = [
        'models'      =>  null,
        'schemas'     =>  SchemaAbstract::class,
        'migrations'  =>  MigrationAbstract::class,
        'controllers' =>  null,
        'pipelines'   =>  PipelineInterface::class,
        'filters'     =>  FilterInterface::class,
        'jobs'        =>  Job::class,
        'commands'    =>  CommandInterface::class,
        'functions'   =>  null,
        'hooks'       =>  null,
        'routes'      =>  null,
    ];

    /** @var array<string,array> What Was Actually Loaded This Request, Keyed By Uid */
    private static array $loaded = [];

    /** @var array<string,string> Modules That Refused To Load, Uid => Reason */
    private static array $failed = [];

    /** @var bool Whether discover() Has Run */
    private static bool $discovered = false;

    ####################################################################################
    /*================================= EXTERNAL API =================================*/
    ####################################################################################

    /**
     * Find And Load Every Enabled Module
     *
     * Called once, from `helpers/loader.php`. Safe to call again - it does
     * nothing the second time.
     * @return array<string,array> What was loaded, keyed by uid
     */
    public static function discover(): array
    {
        if (self::$discovered) {
            return self::$loaded;
        }

        self::$discovered = true;

        $enabled = self::enabled();

        if ($enabled === []) {
            return self::$loaded;
        }

        foreach (self::TYPES as $type) {
            $base = self::path() . '/' . $type;

            if (!is_dir($base)) {
                continue;
            }

            foreach (glob($base . '/*/' . self::MANIFEST) ?: [] as $file) {
                $uid = self::uid($type, basename(dirname($file)));

                // Not switched on: the manifest is not even read. This is the
                // whole reason discovery is cheap.
                if (!in_array($uid, $enabled, true)) {
                    continue;
                }

                self::load($uid, $file, $type);
            }
        }

        return self::$loaded;
    }

    /**
     * What Loaded This Request
     * @return array<string,array>
     */
    public static function loaded(): array
    {
        return self::$loaded;
    }

    /**
     * What Was Enabled But Would Not Load, And Why
     *
     * Surfaced on the admin screen. A module that is switched on and silently
     * absent is the failure somebody spends an afternoon on.
     * @return array<string,string>
     */
    public static function failed(): array
    {
        return self::$failed;
    }

    /**
     * Whether a Module Is Loaded Right Now
     * @param string $uid Module Uid
     * @return bool
     */
    public static function isLoaded(string $uid): bool
    {
        return isset(self::$loaded[$uid]);
    }

    /**
     * Where Modules Are Expected To Live
     * @return string
     */
    public static function path(): string
    {
        return APP_PATH . self::ROOT;
    }

    /**
     * A Stable Identifier For a Module
     *
     * Derived from where it sits rather than stored, so it survives being
     * disabled, deleted and put back. Must agree with `LBM\Action\Module`.
     * @param string $type Subdirectory
     * @param string $directory Module Directory
     * @return string
     */
    public static function uid(string $type, string $directory): string
    {
        return strtolower($type . '-' . preg_replace('/[^a-zA-Z0-9]+/', '-', $directory));
    }

    ##########################################################################
    /*============================== THE CACHE =============================*/
    ##########################################################################

    /**
     * The Enabled Module Uids
     *
     * From the generated cache only - see the class docblock for why this cannot
     * read the database.
     * @return string[]
     */
    public static function enabled(): array
    {
        $file = self::cacheFile();

        if (!is_file($file)) {
            return [];
        }

        try {
            $cached = require $file;
        } catch (Throwable) {
            return [];
        }

        if (!is_array($cached) || !isset($cached['enabled']) || !is_array($cached['enabled'])) {
            return [];
        }

        return array_values(array_filter($cached['enabled'], 'is_string'));
    }

    /**
     * Whether The Cache Has Been Written
     *
     * `GlobalPipeline` asks, and rebuilds when it has not - at which point the
     * database is open and `option()` exists.
     * @return bool
     */
    public static function cached(): bool
    {
        return is_file(self::cacheFile());
    }

    /**
     * Write The Cache
     *
     * @param string[] $enabled Enabled Module Uids
     * @return bool Whether it was written
     */
    public static function writeCache(array $enabled): bool
    {
        $file = self::cacheFile();
        $directory = dirname($file);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return false;
        }

        $uids = [];

        foreach ($enabled as $uid) {
            if (is_string($uid) && $uid !== '') {
                $uids[] = $uid;
            }
        }

        sort($uids);

        $export = var_export(['built' => time(), 'enabled' => $uids], true);

        $php = "<?php\n\n"
            . "/**\n"
            . " * Generated by LBM\\Module\\ModuleManager. Do not edit.\n"
            . " *\n"
            . " * Which modules are switched on. The `options` table is the source of\n"
            . " * truth; this exists because discovery runs during composer's autoload,\n"
            . " * where there is no database and no option() helper yet.\n"
            . " *\n"
            . " * Safe to delete - GlobalPipeline writes it again on the next request.\n"
            . " */\n\n"
            . "return {$export};\n";

        // Written whole, then moved into place: a half-written file is a parse
        // error on every subsequent request, which is a bad way to find out the
        // disk filled up.
        $temporary = $file . '.' . getmypid() . '.tmp';

        if (file_put_contents($temporary, $php, LOCK_EX) === false) {
            return false;
        }

        if (!@rename($temporary, $file)) {
            is_file($temporary) && unlink($temporary);

            return false;
        }

        self::invalidateOpcache($file);

        return true;
    }

    /**
     * Delete The Cache
     * @return void
     */
    public static function flushCache(): void
    {
        $file = self::cacheFile();

        if (is_file($file)) {
            unlink($file);
            self::invalidateOpcache($file);
        }
    }

    /**
     * Where The Cache Lives
     * @return string
     */
    public static function cacheFile(): string
    {
        return APP_PATH . self::CACHE;
    }

    ##############################################################################
    /*============================== INTERNAL API ==============================*/
    ##############################################################################

    /**
     * Load One Module
     *
     * Everything a manifest can do is wrapped: a module is somebody else's code
     * sitting in a directory anybody can write to, and a broken one must not be
     * able to stop the application booting. The whole point of the admin screen
     * is to still be reachable so it can be switched off.
     * @param string $uid Module Uid
     * @param string $file Manifest Path
     * @param string $type Subdirectory
     * @return void
     */
    private static function load(string $uid, string $file, string $type): void
    {
        try {
            $manifest = require $file;

            if (!is_array($manifest)) {
                self::$failed[$uid] = 'The manifest did not return an array.';

                return;
            }

            $directory = dirname($file);

            self::autoload($manifest, $directory);
            $registered = self::resources($manifest, $directory);

            self::$loaded[$uid] = [
                'uid'       =>  $uid,
                'type'      =>  $type,
                'name'      =>  (string) ($manifest['name'] ?? basename($directory)),
                'version'   =>  (string) ($manifest['version'] ?? ''),
                'path'      =>  $directory,
                'resources' =>  $registered,
            ];
        } catch (Throwable $e) {
            self::$failed[$uid] = $e->getMessage();
        }
    }

    /**
     * Make a Module's Classes Autoloadable
     *
     * Onto Composer's own loader rather than a second spl_autoload_register, so
     * modules behave exactly like any other PSR-4 package - including the
     * classmap optimisations of `composer dump-autoload -o`.
     *
     * Two shapes are accepted. The short one covers almost everything:
     *
     *     'namespace' => 'Modules\Gateways\Stripe',   // maps to the module dir
     *     'src'       => 'src',                       // optional subdirectory
     *
     * and the long one, for a module shipping more than one prefix:
     *
     *     'autoload'  => ['Modules\Gateways\Stripe\' => 'src'],
     *
     * @param array $manifest Manifest
     * @param string $directory Module Directory
     * @return void
     */
    private static function autoload(array $manifest, string $directory): void
    {
        $loader = self::loader();

        if ($loader === null) {
            return;
        }

        $prefixes = [];

        $namespace = trim((string) ($manifest['namespace'] ?? ''), '\\');

        if ($namespace !== '') {
            $prefixes[$namespace . '\\'] = (string) ($manifest['src'] ?? '');
        }

        foreach ((array) ($manifest['autoload'] ?? []) as $prefix => $path) {
            $prefix = trim((string) $prefix, '\\');

            if ($prefix !== '') {
                $prefixes[$prefix . '\\'] = (string) $path;
            }
        }

        foreach ($prefixes as $prefix => $path) {
            $full = self::resolve($directory, $path);

            if (is_dir($full)) {
                $loader->addPsr4($prefix, $full);
            }
        }
    }

    /**
     * Hand a Module's Declared Resources To The Framework
     *
     * Registration only records where to look - nothing is scanned until the
     * resource is actually used - so a module declaring five kinds costs five
     * array writes at boot.
     * @param array $manifest Manifest
     * @param string $directory Module Directory
     * @return string[] The resource kinds that were registered
     */
    private static function resources(array $manifest, string $directory): array
    {
        $registered = [];

        foreach ((array) ($manifest['resources'] ?? []) as $name => $definition) {
            $name = strtolower(trim((string) $name));

            // Only the kinds the framework knows. A typo in a manifest should
            // not quietly register a resource nothing will ever read.
            if (!array_key_exists($name, self::CONTRACTS)) {
                continue;
            }

            $definition = is_array($definition) ? $definition : ['path' => (string) $definition];

            $path = self::resolve($directory, (string) ($definition['path'] ?? ''));

            if (!is_dir($path)) {
                continue;
            }

            $namespace = trim((string) ($definition['namespace'] ?? ''), '\\');

            Resource::register(
                $name,
                $path,
                $namespace === '' ? null : $namespace,
                self::CONTRACTS[$name]
            );

            $registered[] = $name;
        }

        return $registered;
    }

    /**
     * Resolve a Manifest Path Against The Module Directory
     *
     * Relative always, and contained: a manifest cannot register `../../lf-app`
     * as its own source directory.
     * @param string $directory Module Directory
     * @param string $path Relative Path From The Manifest
     * @return string
     */
    private static function resolve(string $directory, string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        $full = $path === '' ? $directory : $directory . '/' . $path;

        $real = realpath($full);
        $root = realpath($directory);

        if ($real === false || $root === false) {
            return '';
        }

        // str_starts_with on the resolved paths, so "modules/x/../../lf-app"
        // fails the test rather than being registered.
        return str_starts_with($real, $root) ? $real : '';
    }

    /**
     * Composer's Class Loader
     *
     * Registered before the `files` autoload pass that reaches this class, so
     * it is always there in practice - but a null return is handled rather than
     * assumed, because an application booted some other way would otherwise
     * fatal here.
     * @return ?ClassLoader
     */
    private static function loader(): ?ClassLoader
    {
        if (!class_exists(ClassLoader::class, false)) {
            return null;
        }

        $loaders = ClassLoader::getRegisteredLoaders();

        return $loaders === [] ? null : reset($loaders);
    }

    /**
     * Drop a Generated File From The Opcode Cache
     *
     * Without this, a toggle would appear to do nothing on a server with
     * opcache and `validate_timestamps` off.
     * @param string $file File Path
     * @return void
     */
    private static function invalidateOpcache(string $file): void
    {
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($file, true);
        }
    }
}
