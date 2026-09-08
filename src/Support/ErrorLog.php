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

use Throwable;
use Laika\Core\Exceptions\HttpException;
use LBM\Model\ErrorLogModel;
use LBM\Pipeline\Auth;
use Laika\Service\Uid;

/**
 * The error log a shipped installation has never had.
 *
 * `Laika\Core\Exceptions\Handler::log()` opens with `if (!DEBUG) return;` and
 * `bin/verify-stage.php` forces DEBUG false in every release, so on every
 * installation an operator has ever run an exception is rendered and then
 * dropped. Fixing that is framework-end and out of bounds; this captures LBM's
 * own instead. `LBM\Schema\ErrorLogSchema` carries the full reasoning.
 *
 * ---------------------------------------------------------------------------
 * NOTHING HERE MAY THROW. NOTHING.
 * ---------------------------------------------------------------------------
 * The thing being logged may BE the database, so every public method is a
 * try/catch around its own body and the failure path is a file, then silence.
 * A logger that turns a handled error into a fatal is worse than no logger:
 * it converts a page somebody could still have used into a white one, and it
 * does it at precisely the moment the operator is least able to work out why.
 *
 * That is also why `install()` refuses to chain onto nothing. If there were no
 * previous exception handler, taking over would mean LBM had become the reason
 * an uncaught exception renders nothing at all.
 */
class ErrorLog
{
    /** @var ?callable The exception handler this one delegates rendering to */
    private static $previous = null;

    /** @var bool Whether install() has run in this process */
    private static bool $installed = false;

    /**
     * @var string What Was Recorded Last, To Stop One Failure Being Logged Twice
     *
     * An uncaught exception reaches `handle()`. A FATAL reaches the shutdown
     * hook instead, because laika-core's own shutdown function calls
     * `Handler::handle()` directly rather than going through
     * `set_exception_handler`. The two paths are separate, but PHP's reporting
     * of what counts as the "last error" is not always, so the key is compared.
     */
    private static string $lastKey = '';

    ####################################################################################
    /*================================= EXTERNAL API =================================*/
    ####################################################################################

    /**
     * Start Capturing
     *
     * Called from `GlobalPipeline::boot()` for web requests and from
     * `Support\Cron::run()` for scheduled ones - the two places where the
     * database is open and this class can do anything useful. It cannot be done
     * during composer's autoload: laika-core registers its handler in ITS
     * loader, which runs AFTER LBM's, so anything installed there is replaced a
     * moment later.
     * @return void
     */
    public static function install(): void
    {
        if (self::$installed) {
            return;
        }

        self::$installed = true;

        try {
            // Returns the handler it replaces, which is laika-core's.
            $previous = set_exception_handler([self::class, 'handle']);

            if (!is_callable($previous)) {
                // NOTHING TO DELEGATE TO. Rendering matters more than logging:
                // with no previous handler, staying installed would mean an
                // uncaught exception produced a blank page and a database row.
                // Put back whatever was there and do nothing.
                restore_exception_handler();

                return;
            }

            self::$previous = $previous;

            // The shutdown hook for fatals is NOT registered here. It is in
            // helpers/loader.php, which composer runs before laika-core's -
            // and it has to be, because laika-core registers a shutdown
            // function of its own that renders through Whoops, and Whoops
            // calls exit() from inside the shutdown sequence. Anything
            // registered after it never runs at all.
        } catch (Throwable) {
            // Even installing is not allowed to break anything.
        }
    }

    /**
     * Record An Uncaught Exception, Then Let It Render
     *
     * Public because `set_exception_handler` needs a callable, not because
     * anything should call it.
     * @param Throwable $e The exception
     * @return void
     */
    public static function handle(Throwable $e): void
    {
        // A DELIBERATE 4xx IS AN ANSWER, NOT A FAULT, and this guard is here
        // because the first run without it filled the table with
        // `/panel/register :: Registration is not open.` - which is the product
        // working exactly as designed on an install that has sign-up switched
        // off, and would be logged again on every visit by every bot that ever
        // guesses the URL.
        //
        // `AdminController::attempt()` already made this distinction; the
        // global handler had to learn it. 5xx is kept: an HttpException in that
        // range says the server could not do something it should have.
        if ($e instanceof HttpException && $e->getStatusCode() < 500) {
            self::render($e);

            return;
        }

        self::record($e, 'app');
        self::render($e);
    }

    /**
     * Hand The Exception Back To Whoever Was Rendering Them Before
     *
     * The whole reason this class chains rather than replaces. LBM adds a row
     * on the way past and changes nothing about what the visitor sees.
     * @param Throwable $e The exception
     * @return void
     */
    private static function render(Throwable $e): void
    {
        try {
            if (self::$previous !== null) {
                (self::$previous)($e);
            }
        } catch (Throwable) {
            // The renderer failing is the renderer's problem. Swallowing it
            // here is still better than a second uncaught exception thrown from
            // inside an exception handler, which PHP reports as a bare fatal
            // naming this file rather than the original fault.
        }
    }

    /**
     * Record a Fatal At Shutdown
     *
     * The only path that can see E_ERROR, E_PARSE and the compile-time errors:
     * they never reach an exception handler at all, so without this the one
     * class of failure that leaves a request unfinished is also the one class
     * that leaves no record.
     * @return void
     */
    public static function shutdown(): void
    {
        try {
            $error = error_get_last();

            if ($error === null || !in_array(
                $error['type'],
                [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR],
                true
            )) {
                return;
            }

            self::write([
                'level'           =>  'critical',
                'source'          =>  PHP_SAPI === 'cli' ? 'cron' : 'app',
                'message'         =>  (string) $error['message'],
                'exception_class' =>  null,
                'file'            =>  (string) $error['file'],
                'line'            =>  (int) $error['line'],

                // A fatal has no exception, so there is no getTrace() to
                // rebuild from. debug_backtrace() here would describe the
                // shutdown handler rather than the fault.
                'trace'           =>  null,
            ]);
        } catch (Throwable) {
            // Shutdown is the one place where throwing produces output nobody
            // can attribute to anything.
        }
    }

    /**
     * Record One Exception
     *
     * @param Throwable $e The exception
     * @param string $source app, module, cron or job
     * @param ?int $moduleId modules -> module_id, when a module is to blame
     * @param string $level error or critical
     * @return void
     */
    public static function record(
        Throwable $e,
        string $source = 'app',
        ?int $moduleId = null,
        string $level = 'error'
    ): void {
        self::write([
            'level'           =>  $level,
            'source'          =>  $source,
            'module_relid'    =>  $moduleId,
            'message'         =>  $e->getMessage(),
            'exception_class' =>  get_class($e),
            'file'            =>  $e->getFile(),
            'line'            =>  $e->getLine(),
            'trace'           =>  self::traceOf($e),
        ]);
    }

    /**
     * Record Something That Failed Without Throwing
     *
     * A module returning `success: false` is not an exception - it is working
     * correctly and delivering bad news - and those are different events for
     * an operator. One means the module is broken; the other means the control
     * panel said no.
     * @param string $message What happened
     * @param string $source app, module, cron or job
     * @param ?int $moduleId modules -> module_id
     * @return void
     */
    public static function note(string $message, string $source = 'app', ?int $moduleId = null): void
    {
        self::write([
            'level'        =>  'warning',
            'source'       =>  $source,
            'module_relid' =>  $moduleId,
            'message'      =>  $message,
        ]);
    }

    /**
     * Delete Entries Older Than a Number Of Days
     *
     * Rule three. `lf-logs/` has no rotation at all and one day's file on a
     * development checkout is already 428 KB; a table that only grows is a
     * table an operator eventually empties by hand, losing the entry they were
     * looking for along with everything else.
     * @param int $days Keep this many days. Zero or less keeps everything
     * @return int Rows removed
     */
    public static function prune(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        try {
            $cutoff = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));

            return (new ErrorLogModel())
                ->where(['log_created_at' => $cutoff], '<')
                ->delete();
        } catch (Throwable) {
            return 0;
        }
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * The Trace, WITHOUT ARGUMENT VALUES
     *
     * ---------------------------------------------------------------------
     * WHY THIS IS NOT getTraceAsString()
     * ---------------------------------------------------------------------
     * Because `getTraceAsString()` renders scalar arguments, truncated to
     * `zend.exception_string_param_max_len` - 15 characters by default:
     *
     *     #0 /app/Auth.php(31): signIn('admin', 'Sup3rSecret-Pas...')
     *
     * and `zend.exception_ignore_args` is Off in PHP's development ini, which
     * a great many shared hosts run. That is most of a password, in a table
     * any role with `settings.read` can page through. Measured on this machine
     * rather than assumed - the plan for this phase said getTraceAsString()
     * was the safe choice, and it is not.
     *
     * So the trace is rebuilt from `getTrace()`, an array whose `args` key this
     * method simply never reads. Not filtered, not truncated, not redacted -
     * a redaction is a regex somebody eventually gets wrong, and not reading a
     * value cannot be got wrong.
     * @param Throwable $e The exception
     * @return string
     */
    private static function traceOf(Throwable $e): string
    {
        $lines = [];
        $depth = 0;

        foreach ($e->getTrace() as $frame) {
            // Bounded. A recursion that blew the stack produces thousands of
            // identical frames, and a longText column full of them is a table
            // scan every time the viewer opens.
            if ($depth >= 40) {
                $lines[] = '#' . $depth . ' ... (truncated)';

                break;
            }

            $call = (string) ($frame['class'] ?? '')
                . (string) ($frame['type'] ?? '')
                . (string) ($frame['function'] ?? '');

            $lines[] = '#' . $depth . ' '
                . (string) ($frame['file'] ?? '[internal]')
                . '(' . (string) ($frame['line'] ?? '0') . '): '
                . ($call === '' ? '{closure}' : $call) . '()';

            $depth++;
        }

        return implode("\n", $lines);
    }

    /**
     * Write One Row
     *
     * @param array $row Column values
     * @return void
     */
    private static function write(array $row): void
    {
        $message = trim((string) ($row['message'] ?? ''));

        if ($message === '') {
            return;
        }

        $key = $message . '|' . ($row['file'] ?? '') . '|' . ($row['line'] ?? '');

        if ($key === self::$lastKey) {
            return;
        }

        self::$lastKey = $key;

        try {
            (new ErrorLogModel())->insert([
                'uid'             =>  Uid::make(),
                'level'           =>  (string) ($row['level'] ?? 'error'),
                'source'          =>  (string) ($row['source'] ?? 'app'),
                'module_relid'    =>  $row['module_relid'] ?? null,

                // Bounded to the column. A message longer than the column is a
                // driver error on the INSERT, which lands in the catch below
                // and loses the entry entirely - so the log would go quiet
                // exactly when something was going very wrong.
                'message'         =>  mb_substr($message, 0, 60000),
                'exception_class' =>  self::clip($row['exception_class'] ?? null, 191),
                'file'            =>  self::clip($row['file'] ?? null, 500),
                'line'            =>  isset($row['line']) ? (int) $row['line'] : null,
                'trace'           =>  isset($row['trace']) ? mb_substr((string) $row['trace'], 0, 60000) : null,
                'url'             =>  self::path(),
                'method'          =>  self::clip($_SERVER['REQUEST_METHOD'] ?? null, 10),
                'staff_relid'     =>  self::actor(ADMIN, 'sid'),
                'client_relid'    =>  self::actor(PANEL, 'cid'),
                'log_created_at'  =>  date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            self::toFile($row, $e);
        }
    }

    /**
     * The Fallback When The Database Cannot Be Written To
     *
     * Which is not a remote possibility here - "the database is unreachable" is
     * one of the most likely things anybody is ever trying to log.
     * @param array $row What could not be written
     * @param Throwable $why Why it could not
     * @return void
     */
    private static function toFile(array $row, Throwable $why): void
    {
        try {
            $directory = APP_PATH . '/lf-logs';

            if (!is_dir($directory)) {
                mkdir($directory, 0775, true);
            }

            file_put_contents(
                $directory . '/' . date('Y-M-d') . '-error.log',
                sprintf(
                    "[%s] %s %s: %s in %s on line %s\n  (not written to error_logs: %s)\n\n",
                    date('Y-M-d H:i:s'),
                    strtoupper((string) ($row['level'] ?? 'error')),
                    (string) ($row['exception_class'] ?? (string) ($row['source'] ?? 'app')),
                    (string) ($row['message'] ?? ''),
                    (string) ($row['file'] ?? '?'),
                    (string) ($row['line'] ?? '?'),
                    $why->getMessage()
                ),
                FILE_APPEND
            );
        } catch (Throwable) {
            // Both the table and the file are gone. There is nowhere left to
            // put this, and the one thing that must not happen is that saying
            // so becomes the operator's actual error.
        }
    }

    /**
     * Who Was Looking At It
     *
     * "Which member of staff saw this" is the difference between a fault
     * somebody can be asked about and one that has to be reproduced blind.
     *
     * Wrapped, and it has to be: the exception being logged may have come out
     * of the auth pipeline itself, and `Auth::user()` reads a session. It
     * memoises per area, so on a request that has already resolved a user this
     * costs nothing, and it answers null on the CLI where there is no session
     * driver at all rather than throwing.
     * @param string $area ADMIN or PANEL
     * @param string $column The primary key column for that area's table
     * @return ?int
     */
    private static function actor(string $area, string $column): ?int
    {
        try {
            $user = Auth::user($area);

            return $user === null ? null : (((int) ($user[$column] ?? 0)) ?: null);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The Request Path, WITHOUT ITS QUERY STRING
     *
     * A query string carries reset tokens, one-time links and whatever an
     * integration decided to put in it. The path is what says which screen was
     * being looked at, which is the whole diagnostic value.
     * @return ?string
     */
    private static function path(): ?string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');

        if ($uri === '') {
            return null;
        }

        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: $uri);

        return self::clip($path, 500);
    }

    /**
     * Trim a Value To Its Column
     * @param mixed $value Value
     * @param int $length Column length
     * @return ?string
     */
    private static function clip(mixed $value, int $length): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : mb_substr($value, 0, $length);
    }
}
