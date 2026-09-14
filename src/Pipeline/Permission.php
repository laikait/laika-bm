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

use Laika\Service\Redirect;
use Laika\Route\Contracts\PipelineInterface;
use LBM\Pipeline\Auth;
use LBM\Service\Permission as Access;
use LBM\Support\Permission as Rules;
use LBM\Support\Referrer;

/**
 * Per-route permission check for the admin area.
 *
 * Attached with an argument, which Invoke::parse() splits off the class name
 * and merges into the route params:
 *
 *     Url::get('/invoices', [InvoiceController::class, 'index'])
 *        ->pipeline([Permission::class . '|perm=invoice.read']);
 *
 * Permissions are JSON on staff_roles.permissions, in the shape
 * {"invoice":{"read":1,"create":1,"update":1,"delete":0}, ...}.
 *
 * This deliberately does not depend on Auth having run first, because it has
 * not. Url::group(...)->pipeline() calls Handler::applyToPrefix(), which
 * *appends* the group pipeline to routes that already carry their own - so the
 * chain for a guarded route is [Permission, Auth], not [Auth, Permission].
 *
 * Rather than fight that ordering, this resolves the staff member itself.
 * Auth::user() memoises per area, so the lookup Auth performs a moment later is
 * free, and the check stays correct no matter which order the two run in.
 */
class Permission implements PipelineInterface
{
    /** @var string Route Parameter Carrying The Required Access */
    public const PARAM = 'perm';

    /** @var string Where a Refusal Goes When There Is No Page To Go Back To */
    public const FALLBACK = 'staff.dashboard';

    /**
     * Handle The Request
     * @param callable $next Next Pipeline
     * @param array $params Route Parameters
     * @return ?string
     */
    public function handle(callable $next, array &$params): ?string
    {
        $access = $params[self::PARAM] ?? null;

        // A route that asks for no permission is a wiring mistake, not an open
        // door - failing loudly here beats silently granting access.
        if (!is_string($access) || $access === '') {
            throw new \InvalidArgumentException(
                'Permission pipeline needs a ' . self::PARAM . ' argument, '
                . 'e.g. Permission::class . \'|' . self::PARAM . '=invoice.read\'.'
            );
        }

        // Auth fills this in when it runs first; otherwise resolve it here.
        $staff = $params['auth'] ?? Auth::user(ADMIN);

        if (!is_array($staff)) {
            // Not signed in at all. That is a login problem, not a permission
            // problem - 403ing here would show a locked door to somebody who
            // was never offered a key.
            Redirect::with(local('require_sign_in'), false)->to(Auth::STAFF_LOGIN);
        }

        if (!Access::allows(isset($staff['role_relid']) ? (int) $staff['role_relid'] : null, $access)) {
            $this->deny($access);
        }

        return $next();
    }

    ##############################################################################
    /*============================== INTERNAL API ==============================*/
    ##############################################################################

    /**
     * Refuse The Request
     *
     * A missing permission is an answer, not a fault: the person clicked
     * something their role does not cover, so they are told so and sent back to
     * the page they came from. Referrer::refuse() never returns - a redirect
     * exits - which matters here, because it is the only real short-circuit:
     * Invoke::pipeline() builds each link as `function (bool $continue = true)`,
     * and passing false makes that link `return $core()`, running the
     * controller. $next(false) would let the request straight through.
     *
     * It used to throw HttpException(403), which DEBUG renders as a 500 error
     * page and production as a generic one with the message dropped.
     * @param string $access Required Access. Example: 'invoice.update'
     * @return never
     */
    private function deny(string $access): never
    {
        Referrer::refuse(local('no_permission_to', self::describe($access)), self::FALLBACK);
    }

    /**
     * A Permission In Words
     *
     * "change invoices", the way a person would say it - never the key. Falls
     * back to the key for a group or action the catalogue has no words for,
     * because local() throws on a missing key and a refusal must not become an
     * error on the way out.
     *
     * Public since Phase 46, for the one controller that refuses by permission
     * itself: Configure serves every kind of module, and each keeps its own.
     * @param string $access Example: 'invoice.update'
     * @return string
     */
    public static function describe(string $access): string
    {
        [$group, $action] = array_pad(explode('.', $access, 2), 2, '');

        if (!in_array($group, Rules::GROUPS, true) || !in_array($action, Rules::ACTIONS, true)) {
            return $access;
        }

        return local('permission_' . $action, local('permission_area_' . $group));
    }
}
