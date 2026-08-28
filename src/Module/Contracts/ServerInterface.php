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

namespace LBM\Module\Contracts;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

/**
 * What a provisioning module has to provide.
 *
 * These four verbs are the lifecycle of a `client_services` row: it is created,
 * it may be suspended and brought back when an invoice is settled, and
 * eventually it is terminated.
 *
 * Two things every implementation has to get right.
 *
 * **Suspending is not terminating.** A suspended service still exists, still
 * bills, and comes back with its data when unsuspend() runs. Implementing
 * suspend() as "delete the account" makes non-payment unrecoverable, and the
 * client who pays the next day has lost everything.
 *
 * **These are called from a queue worker, not a web request.** Provisioning
 * talks to somebody else's control panel over the network, which is slow and
 * fails often. Nothing here may assume a session, a signed-in user, or that it
 * will only ever run once - a retried job will call the same method again, so
 * create() on a service that already exists should report success rather than
 * failing on a duplicate.
 */
interface ServerInterface
{
    /**
     * Provision The Service
     *
     * @param array $service The `client_services` row
     * @param array $context {
     *     @type array $server   The `servers` row, with hostname and credentials
     *     @type array $client   Who it is for
     *     @type array $product  What was bought
     *     @type array $options  Configurable options chosen at order time
     * }
     * @return array{
     *     success: bool,
     *     username: ?string,
     *     password: ?string,
     *     message: ?string,
     *     raw: array
     * }
     *   `password` is returned in the clear and stored encrypted by
     *   `LBM\Action\ClientService::setCredential()` - never write it anywhere
     *   yourself, and never log it.
     */
    public function create(array $service, array $context = []): array;

    /**
     * Switch The Service Off Without Destroying It
     *
     * @param array $service The `client_services` row
     * @param string $reason Shown to the client, so write it for them
     * @param array $context See create()
     * @return array{success: bool, message: ?string, raw: array}
     */
    public function suspend(array $service, string $reason = '', array $context = []): array;

    /**
     * Switch It Back On
     *
     * @param array $service The `client_services` row
     * @param array $context See create()
     * @return array{success: bool, message: ?string, raw: array}
     */
    public function unsuspend(array $service, array $context = []): array;

    /**
     * Destroy The Service
     *
     * The one that cannot be undone. Callers are expected to have their own
     * guard in front of it; an implementation should still refuse anything that
     * looks like a mistake rather than being clever about it.
     * @param array $service The `client_services` row
     * @param array $context See create()
     * @return array{success: bool, message: ?string, raw: array}
     */
    public function terminate(array $service, array $context = []): array;
}
