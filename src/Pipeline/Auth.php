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

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use LANG;
use Laika\Service\Url;
use Laika\Service\Redirect;
use Laika\Auth\AuthManager;
use Laika\Session\Session;
use Laika\Session\SessionManager;
use Laika\Auth\Guards\TokenGuard;
use Laika\Route\Contracts\PipelineInterface;

/**
 * Authentication gate for /admin and /panel.
 *
 * Attached as a group pipeline. Login and logout routes are registered outside
 * that group - a guard that redirected the login page to itself would loop.
 *
 * Built on Laika\Auth\Guards\TokenGuard rather than SessionGuard. issueToken()
 * stores only a SHA-256 hash of the token in `auth_tokens`, alongside the
 * browser, IP and user agent seen at issue time; the plain token exists only
 * inside the (database-backed) session. Choosing the token guard also means a
 * later REST API reuses this exact guard with a Bearer header instead of a
 * session lookup, so there is never a second authentication path to keep in sync.
 *
 * Static helpers here are the single entry point for login and logout - the
 * controllers verify credentials, this class owns the token lifecycle.
 */
class Auth implements PipelineInterface
{
    /** @var string Session Key Holding The Plain Token */
    public const TOKEN = 'auth_token';

    /** @var string Session Key Holding The Guard That Issued It */
    public const GUARD = 'auth_guard';

    /** @var string Admin Area Guard */
    public const STAFF = 'staff';

    /** @var string Client Area Guard */
    public const CLIENT = 'client';

    /** @var string Client Sub-Login Guard */
    public const CONTACT = 'contact';

    /** @var string Admin Login Route Name */
    public const STAFF_LOGIN = 'staff.login';

    /** @var string Client Login Route Name */
    public const CLIENT_LOGIN = 'client.login';

    /** @var array<string,?array> Resolved Users, Keyed By Area */
    private static array $resolved = [];

    /**
     * Handle The Request
     * @param callable $next Next Pipeline
     * @param array $params Route Parameters
     * @return ?string
     */
    public function handle(callable $next, array &$params): ?string
    {
        $area = self::area();
        $user = self::user($area);

        if ($user === null) {
            // Nothing to revoke - the token is either absent, expired or already
            // revoked - but the stale session key would otherwise be retried on
            // every request.
            Session::pop(self::TOKEN, self::scope($area));
            Session::pop(self::GUARD, self::scope($area));

            // A route NAME, not a path: Handler::namedUrl() throws on anything
            // it has not seen declared.
            Redirect::with(LANG::$require_sign_in, false)->to(self::loginRoute($area));
        }

        // Threaded to the controller and to ActivityFilter, so neither has to
        // resolve the user a second time.
        $params['auth']  = $user;
        $params['area']  = $area;
        $params['guard'] = self::guardName($area);

        return $next();
    }

    ####################################################################################
    /*================================= EXTERNAL API =================================*/
    ####################################################################################

    /**
     * Sign a User In
     *
     * The session id is rotated before the token is stored, so a fixated session
     * id captured before login is useless afterwards.
     * @param string $area ADMIN or PANEL
     * @param int $userId Staff/Client/Contact ID
     * @param ?string $guard Guard Name. Defaults to the area's own guard
     * @return string The plain token, for callers that need it
     */
    public static function login(string $area, int $userId, ?string $guard = null): string
    {
        $guard  = $guard ?? self::guardName($area);
        $issued = self::guard($guard)->issueToken($userId, self::lifetime());

        Session::regenerate();
        Session::set(self::TOKEN, $issued['token'], self::scope($area));
        Session::set(self::GUARD, $guard, self::scope($area));

        unset(self::$resolved[$area]);

        return $issued['token'];
    }

    /**
     * Sign a User Out
     * @param string $area ADMIN or PANEL
     * @return void
     */
    public static function logout(string $area): void
    {
        $namespace = self::scope($area);
        $token     = Session::get(self::TOKEN, null, $namespace);
        $guard     = Session::get(self::GUARD, self::guardName($area), $namespace);

        // Revoking marks revoked_at rather than deleting, so the row stays as an
        // audit trail of the session that existed.
        if (is_string($token) && $token !== '') {
            self::guard((string) $guard)->revoke($token);
        }

        Session::purge($namespace);
        unset(self::$resolved[$area]);
    }

    /**
     * Sign a User Out Of Every Device
     * @param string $area ADMIN or PANEL
     * @param int $userId Staff/Client/Contact ID
     * @return void
     */
    public static function logoutEverywhere(string $area, int $userId): void
    {
        self::guard(self::guardName($area))->revokeAllForUser($userId);
        Session::purge(self::scope($area));
        unset(self::$resolved[$area]);
    }

    /**
     * Get The Authenticated User For An Area
     *
     * Memoised per area: a template calling current_staff() twenty times costs
     * one token lookup, not twenty.
     * @param ?string $area ADMIN or PANEL. Defaults to the current area
     * @return ?array
     */
    public static function user(?string $area = null): ?array
    {
        $area = $area ?? self::area();

        if (array_key_exists($area, self::$resolved)) {
            return self::$resolved[$area];
        }

        // No session driver means this is not a web request - a queue worker or
        // a CLI command. Nobody is signed in there, and reading the session
        // would throw SessionHandlerException rather than say so.
        if (!SessionManager::isConfigured()) {
            return self::$resolved[$area] = null;
        }

        $namespace = self::scope($area);
        $token     = Session::get(self::TOKEN, null, $namespace);
        $guard     = Session::get(self::GUARD, self::guardName($area), $namespace);

        if (!is_string($token) || $token === '') {
            return self::$resolved[$area] = null;
        }

        // validateToken() slides expires_at forward on every success, so the
        // lifetime behaves as an idle timeout: activity keeps a session alive
        // and inactivity ends it.
        return self::$resolved[$area] = self::guard((string) $guard)->validateToken(
            $token,
            self::lifetime(),
            option_bool('strict_ip')
        );
    }

    /**
     * Whether Somebody Is Signed Into An Area
     * @param ?string $area ADMIN or PANEL
     * @return bool
     */
    public static function check(?string $area = null): bool
    {
        return self::user($area) !== null;
    }

    /**
     * The Guard The Current Session Was Issued Under
     *
     * The client area accepts two guards - a client signing in directly, and a
     * client contact signing in as a sub-login of that client. Both land in the
     * PANEL session scope, so this is how a screen tells which one is looking.
     * @param ?string $area ADMIN or PANEL. Defaults to the current area
     * @return ?string staff, client or contact. Null when nobody is signed in
     */
    public static function guardOf(?string $area = null): ?string
    {
        $area = $area ?? self::area();

        if (self::user($area) === null) {
            return null;
        }

        $guard = Session::get(self::GUARD, self::guardName($area), self::scope($area));

        return is_string($guard) && $guard !== '' ? $guard : self::guardName($area);
    }

    /**
     * Resolve a Guard
     * @param string $name staff, client or contact
     * @return TokenGuard
     */
    public static function guard(string $name): TokenGuard
    {
        $guard = (new AuthManager())->guard($name);

        if (!$guard instanceof TokenGuard) {
            throw new \RuntimeException(
                "Guard [{$name}] must use the token driver. Check lf-config/auth.php."
            );
        }

        return $guard;
    }

    /**
     * The Area The Current Request Belongs To
     * @return string ADMIN or PANEL
     */
    public static function area(): string
    {
        return Url::segment(1) === PANEL ? PANEL : ADMIN;
    }

    /**
     * The Login Route Name For An Area
     * @param string $area ADMIN or PANEL
     * @return string
     */
    public static function loginRoute(string $area): string
    {
        return $area === ADMIN ? self::STAFF_LOGIN : self::CLIENT_LOGIN;
    }

    /**
     * The Default Guard For An Area
     * @param string $area ADMIN or PANEL
     * @return string
     */
    public static function guardName(string $area): string
    {
        return $area === ADMIN ? self::STAFF : self::CLIENT;
    }

    /**
     * The Session Scope For An Area
     *
     * Keeping staff and client tokens in separate namespaces means one browser
     * can hold both an admin and a client session without either overwriting
     * the other.
     * @param string $area ADMIN or PANEL
     * @return string
     */
    public static function scope(string $area): string
    {
        return strtoupper($area);
    }

    /**
     * Session Lifetime, In Seconds
     *
     * Never null. A token issued without a TTL gets expires_at = NULL, and
     * validateToken() skips every expiry check on a NULL - so the session would
     * live forever.
     * @return int
     */
    public static function lifetime(): int
    {
        $lifetime = option_int('login_lifetime', 3600);

        return $lifetime > 0 ? $lifetime : 3600;
    }

    /**
     * Forget The Memoised User
     * @param ?string $area ADMIN or PANEL. Null clears every area
     * @return void
     */
    public static function flush(?string $area = null): void
    {
        if ($area === null) {
            self::$resolved = [];
            return;
        }

        unset(self::$resolved[$area]);
    }
}
