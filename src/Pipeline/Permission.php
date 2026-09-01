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
use Laika\Core\Exceptions\HttpException;
use Laika\Route\Contracts\PipelineInterface;
use LBM\Pipeline\Auth;
use LBM\Service\Permission as Access;

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
            Redirect::to(Auth::STAFF_LOGIN);
        }

        if (!Access::allows(isset($staff['role_relid']) ? (int) $staff['role_relid'] : null, $access)) {
            $this->deny($access);
        }

        return $next();
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Refuse The Request
     *
     * Throwing is the only real short-circuit available here. Invoke::pipeline()
     * builds each link as `function (bool $continue = true)`, and passing false
     * makes that link `return $core()` - which runs the controller. Calling
     * $next(false) would therefore skip the remaining checks and let the request
     * straight through, the exact opposite of denying it.
     *
     * HttpException is caught by the framework's exception handler, which
     * renders it with the right status code.
     * @param string $access Required Access
     * @return never
     * @throws HttpException
     */
    private function deny(string $access): never
    {
        throw new HttpException(403, local('no_permission_to', $access));
    }
}
