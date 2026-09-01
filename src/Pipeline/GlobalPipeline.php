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

use Throwable;
use Laika\Service\Url;
use Laika\Service\CSRF;
use Laika\Service\Date;
use Laika\Service\Init;
use Laika\Service\Local;
use Laika\Service\Request;
use Laika\Service\Redirect;
use Laika\Core\Exceptions\HttpException;
use Laika\Model\Connection;
use Laika\Session\SessionConfig;
use Laika\Route\Contracts\PipelineInterface;
use LBM\Support\Clock;
use LBM\Module\ModuleManager;
use LBM\Action\Module as ModuleAction;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

/**
 * Boot every request: database, timezone, session, language, CSRF.
 *
 * Attached with Url::globalPipeline() in helpers/routes/app.php, directly after
 * Install. Order inside handle() matters and is not arbitrary:
 *
 *   database -> timezone -> session -> language -> CSRF
 *
 * The database comes first because option() reads from it, and the timezone,
 * session driver and language are all option-backed. CSRF comes last because it
 * needs the session to burn a used token.
 *
 * Every step is skipped while uninstalled - there is no database to connect to,
 * so the session falls back to files and the timezone to UTC. That fallback is
 * the only reason /install renders at all on a fresh checkout.
 */
class GlobalPipeline implements PipelineInterface
{
    /** @var string Default Application Timezone */
    public const TIMEZONE = 'UTC';

    /** @var string Default Language */
    public const LANGUAGE = 'en';

    /**
     * Handle The Request
     * @param callable $next Next Pipeline
     * @param array $params Route Parameters
     * @return ?string
     */
    public function handle(callable $next, array &$params): ?string
    {
        // Install sets this. Recomputed rather than trusted blindly so the
        // pipeline still behaves if it is ever used without Install ahead of it.
        $installed = $params['installed'] ?? Install::isInstalled();

        $installed ? $this->boot() : $this->bootMinimal();

        $this->language();

        // Instruction 15: every POST is CSRF checked, with no exception and no
        // per-controller opt-in - a form that forgets the field fails here.
        if (Request::isPost() && !$this->csrf()) {
            // Throwing is the only genuine short-circuit. Invoke::pipeline()
            // builds each link as `function (bool $continue = true)` and makes
            // `false` mean "skip the rest of the chain and call the controller"
            // - so returning $next(false) here would run the very mutation this
            // check exists to stop.
            Redirect::with('Your session/csrf expired', false)->back();
            return $next(false);
        }

        return $next();
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Boot a Fully Installed App
     * @return void
     */
    private function boot(): void
    {
        // 1. Database first - every option() read below depends on it.
        Init::db();

        // 2. Timezone. The app clock and the database clock must agree, or a row
        //    written now and read back reports a different time.
        //
        //    Through Clock rather than inline, because a web request is not the
        //    only way in: a queue worker runs no pipeline at all, and the jobs
        //    call the same method. Two copies of this drift, and the symptom -
        //    a reminder sent on the wrong day - does not look like a timezone
        //    bug when it turns up.
        Clock::apply(true);

        // 3. Session in the database, so sessions survive a load-balanced
        //    deployment and the auth token is never written to a shared disk.
        //
        // Init::model() registers the connection itself before selecting the
        // driver, and leaves `install` false - the `sessions` table comes from
        // laika-session's own schema during app:migrate, not from a DDL round
        // trip on every page load.
        //
        // No SessionManager::start() here on purpose: Session::* starts on first
        // access, so starting eagerly would cost a query per request on requests
        // that never touch the session.
        Init::model('default');

        // lazy_write off, deliberately.
        //
        // With it on (PHP's default) a request that reads the session without
        // changing it makes PHP call the handler's updateTimestamp() instead of
        // write(). laika-session routes that to SessionModel::touch(), which is
        // `update([...]) > 0` - and MySQL reports zero affected rows when an
        // UPDATE writes the value already there. So any request finishing in the
        // same second the session was last touched gets `false` back, which PHP
        // reports as "Failed to write session data" and turns into a fatal at
        // shutdown. That is most requests on a responsive page.
        //
        // The sibling method touchRow() already guards against exactly this with
        // an existence check; touch() was missed. Turning lazy_write off routes
        // every request through write() -> touchRow() instead, which is correct.
        // It costs one UPDATE per request, which the database driver was already
        // doing on every write.
        //
        // Remove this once SessionModel::touch() is fixed upstream.
        SessionConfig::options(['lazy_write' => 0]);

        // 4. The module loader's cache, if it has gone missing.
        //
        // ModuleManager::discover() ran during composer's autoload, where there
        // is no database to ask which modules are on - so it reads a generated
        // file. Here the database is open and option() exists, which makes this
        // the first point in the request that can write that file.
        //
        // Only when it is absent: somebody cleared lf-cache, or this is the
        // first request after an install. The cost is one glob and one small
        // write, once, and the modules load from the next request onward -
        // rather than a cleared cache quietly disabling every module forever.
        $this->cacheModules();
    }

    /**
     * Rebuild The Module Loader's Cache If It Is Missing
     *
     * Deliberately not allowed to break the request. A failure here means
     * modules stay dormant for another request, which is a great deal better
     * than a page that will not render because a directory is read-only.
     * @return void
     */
    private function cacheModules(): void
    {
        try {
            if (!ModuleManager::cached()) {
                (new ModuleAction())->rebuildCache();
            }
        } catch (Throwable) {
            // Nothing to do. The next request tries again.
        }
    }

    /**
     * Boot The Bare Minimum, With No Database
     *
     * Used while the installer is running. Nothing here reads `options`.
     * @return void
     */
    private function bootMinimal(): void
    {
        Date::setAppTimezone(self::TIMEZONE);
        Date::setFormat('Y-m-d H:i:s');

        // Files, not the database - the installer has to hold state across its
        // steps before a database exists to hold it.
        Init::file();
    }

    /**
     * Load The Language File For The Current Area
     *
     * Catalogues are per area - lf-lang/admin/en.local.php, lf-lang/panel/…,
     * lf-lang/install/… - because a language file declares a global `class LANG`
     * and require_once'ing a second one in the same request is a fatal. Only one
     * area renders per request, so one file is ever loaded and the constraint
     * costs nothing. The price is that strings shared between areas are copied
     * into each file; there is no way to share them.
     *
     * Local::setPath() takes a name relative to LANG_PATH, which is exactly this
     * shape.
     * @return void
     */
    private function language(): void
    {
        // The installer renders before there is an area to be in, and before
        // there is a database to read default_language from.
        $area = Install::isInstalled()
            ? (Url::segment(1) === PANEL ? PANEL : ADMIN)
            : 'install';

        $code = $this->languageCode();

        // Local::load() writes an empty LANG stub for a file that is not there,
        // and that stub then looks like a real catalogue for ever after - the
        // area renders, every string is missing, and nothing says why. Fall back
        // before it can, rather than cleaning up after it.
        if (!$this->catalogue($area, $code)) {
            $code = self::LANGUAGE;
        }

        // English is missing too, so this area has no catalogue at all yet.
        // Loading would create exactly the stub the guard above exists to
        // prevent. Load nothing instead and let local() fail loudly.
        if (!$this->catalogue($area, $code)) {
            return;
        }

        // setPath() throws when the directory is absent. A checkout whose
        // lf-lang/ has not been populated should render untranslated, not 500.
        try {
            Local::set($code);
            Local::setPath($area);
            Local::load();
        } catch (Throwable) {
            return;
        }
    }

    /**
     * Does a Catalogue File Exist For This Area And Language
     * @param string $area Area Directory Below lf-lang
     * @param string $code Language Code
     * @return bool
     */
    private function catalogue(string $area, string $code): bool
    {
        return is_file(LANG_PATH . DS . $area . DS . $code . '.local.php');
    }

    /**
     * Resolve The Language Code To Load
     *
     * Reading `default_language` needs the database, so an uninstalled app - and
     * any install whose option row is empty - falls back to English.
     * @return string
     */
    private function languageCode(): string
    {
        if (!Install::isInstalled()) {
            return self::LANGUAGE;
        }

        return option('default_language', self::LANGUAGE) ?: self::LANGUAGE;
    }

    /**
     * Validate The CSRF Token On a POST
     *
     * CSRF::validate() throws rather than returning false for a replayed or
     * fingerprint-mismatched token, so both outcomes are folded into one bool.
     * @return bool
     */
    private function csrf(): bool
    {
        try {
            return CSRF::validate(CSRF::fromRequest());
        } catch (Throwable) {
            return false;
        }
    }
}
