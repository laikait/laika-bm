<?php
/**
 * Laika Bill Manager
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: Proprietary - see LICENSE
 * This file is part of Laika Bill Manager.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace LBM\Support;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Laika\Service\Url;
use Laika\Service\Request;
use Laika\Service\Response;
use Laika\Service\Redirect;
use Laika\Core\Exceptions\HttpException;

/**
 * Sending somebody back where they came from, and refusing them without an
 * error page.
 *
 * `Redirect::back()` follows whatever HTTP_REFERER says - another site's
 * address included - and falls back to `/`, which on this application is the
 * PUBLIC site. A referrer is a header the browser volunteers and anybody can
 * forge, so it is used here only when it is this application: it must begin
 * with Url::base() - the same scheme, host, port and install directory - and it
 * must not be the page being refused, or the person would be sent straight back
 * into the refusal.
 */
final class Referrer
{
    /**
     * The Page The Person Came From, When It Is Safe To Go Back To
     * @return ?string Absolute URL, or null when there is none worth following
     */
    public static function url(): ?string
    {
        $referer = trim((string) (Request::header('Referer') ?? ''));

        if ($referer === '' || preg_match('/[\x00-\x1f\x7f]/', $referer)) {
            return null;
        }

        $base = rtrim(Url::base(), '/');

        // The base itself or something below it. A bare prefix would let
        // "http://site.example.evil/" through for a base of "http://site.example".
        if ($referer !== $base && !str_starts_with($referer, $base . '/')) {
            return null;
        }

        return self::same($referer, Url::current()) ? null : $referer;
    }

    /**
     * Refuse The Request, Politely
     *
     * A person is sent back where they came from - or to $fallbackRoute when
     * there is nowhere safe - with $message in the flash.
     *
     * A script is told 403 in JSON instead: a redirect would hand it a page of
     * HTML it cannot read. It is written here rather than thrown for laika-core's
     * handler, because with DEBUG on that handler answers an XHR with an EMPTY
     * page at HTTP 200 - which a script reads as success.
     *
     * Never returns. When even the fallback is the page being refused there is
     * nowhere to send them, and the 403 is thrown as it always was.
     * @param string $message What They May Not Do, In Words
     * @param string $fallbackRoute Route Name For When There Is No Page To Go Back To
     * @return never
     * @throws HttpException
     */
    public static function refuse(string $message, string $fallbackRoute): never
    {
        if (self::wantsJson()) {
            // Through the framework's Response (Phase 51) rather than header()
            // and echo by hand: same status, same content type, and one place
            // that knows how a response is written.
            Response::json(['message' => $message], 403)->send();
            exit();
        }

        $to = self::url() ?? named($fallbackRoute, [], true);

        if (self::same($to, Url::current())) {
            throw new HttpException(403, $message);
        }

        Redirect::with($message, false)->to($to);

        // Redirect::to() exits. This keeps the `never` honest if it ever stops.
        exit();
    }

    /**
     * Whether The Request Came From a Script
     *
     * The three signs laika-core's error handler looks for, with Accept read as
     * a list rather than one exact value: `fetch()` commonly sends
     * "application/json, text/plain, * /*", and no browser navigation asks for
     * JSON at all.
     * @return bool
     */
    public static function wantsJson(): bool
    {
        // Request::header(), not $_SERVER (Phase 51). Not Request::isAjax():
        // it lowercases a header that may be absent, and strtolower(null) is a
        // deprecation the error handler turns into an exception - plan U3.
        $header = static fn (string $name): string => strtolower((string) (Request::header($name) ?? ''));

        return str_contains($header('Accept'), 'application/json')
            || str_starts_with($header('Content-Type'), 'application/json')
            || $header('X-Requested-With') === 'xmlhttprequest';
    }

    /**
     * Two URLs Naming The Same Page
     * @param string $a URL
     * @param string $b URL
     * @return bool
     */
    private static function same(string $a, string $b): bool
    {
        return rtrim($a, '/') === rtrim($b, '/');
    }
}
