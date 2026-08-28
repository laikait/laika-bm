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

namespace LBM\Filter;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Throwable;
use Laika\Service\Url;
use Laika\Service\Request;
use Laika\Route\Contracts\FilterInterface;
use LBM\Pipeline\Auth;
use LBM\Service\Activity;
use LBM\Action\Activity as Trail;

/**
 * The audit backstop.
 *
 * Actions record what they did in their own words - "Raised invoice INV-000123",
 * with the fields that changed. This runs afterwards and writes a plain entry
 * for any mutation that recorded nothing at all, so a controller that forgets to
 * log still leaves a trace of who changed something and where.
 *
 * A filter, not a pipeline: filters run on the way out, once the controller has
 * returned, so an entry is only written for a request that actually completed.
 * A mutation that threw never reaches here, and an audit trail claiming a change
 * that was rolled back would be worse than one that missed it.
 *
 * Only POSTs are considered. Reads are not changes, and logging every page view
 * would bury the entries that matter under noise nobody reads.
 */
class ActivityFilter implements FilterInterface
{
    /** @var string What The Backstop Entry Is Called */
    public const EVENT = 'request.mutation';

    /**
     * Handle The Response
     * @param callable $next Next Filter
     * @param ?string $response Response
     * @param array $params Route Parameters
     * @return ?string
     */
    public function terminate(callable $next, ?string $response, array &$params): ?string
    {
        // Nothing here may change what the browser receives. Every failure is
        // swallowed: an audit entry is not worth a blank page.
        try {
            $this->record($params);
        } catch (Throwable) {
            // Deliberately ignored.
        }

        return $next($response);
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Write The Backstop Entry, If One Is Wanted
     * @param array $params Route Parameters
     * @return void
     */
    private function record(array $params): void
    {
        if (!$this->isMutation()) {
            return;
        }

        // The action already said something more useful than this filter could.
        if (Activity::recorded()) {
            return;
        }

        $area = $params['area'] ?? Auth::area();
        $user = $params['auth'] ?? Auth::user($area);

        Activity::record(
            self::EVENT,
            'Submitted ' . $this->path(),
            $this->authorType($area, $params),
            $this->authorId($user)
        );
    }

    /**
     * Whether This Request Changed Something
     * @return bool
     */
    private function isMutation(): bool
    {
        return Request::isPost();
    }

    /**
     * The Path That Was Submitted To
     * @return string
     */
    private function path(): string
    {
        $path = Url::path();

        return is_string($path) && $path !== '' ? $path : '/';
    }

    /**
     * Which Kind Of Account Submitted This
     * @param string $area ADMIN or PANEL
     * @param array $params Route Parameters
     * @return string
     */
    private function authorType(string $area, array $params): string
    {
        if ($area === ADMIN) {
            return Trail::STAFF;
        }

        $guard = $params['guard'] ?? Auth::guardOf(PANEL);

        return $guard === Auth::CONTACT ? Trail::CONTACT : Trail::CLIENT;
    }

    /**
     * The Author's Primary Key
     *
     * The three account tables number their rows differently - sid, cid, cc_id -
     * so the row itself is asked rather than assumed.
     * @param ?array $user Authenticated User
     * @return ?int
     */
    private function authorId(?array $user): ?int
    {
        if ($user === null) {
            return null;
        }

        foreach (['sid', 'cid', 'cc_id', 'id'] as $column) {
            if (!empty($user[$column])) {
                return (int) $user[$column];
            }
        }

        return null;
    }
}
