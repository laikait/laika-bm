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

namespace LBM\Module\Contracts;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

/**
 * A gateway that registers its own webhook with its provider - Phase 48.
 *
 * Optional, beside GatewayInterface. A provider whose API can create a webhook
 * endpoint returns the endpoint's signing secret when it does, so nobody has to
 * copy one out of a dashboard, paste it into the wrong mode's field, or forget
 * it - which is a gateway that takes money and never records it.
 *
 * The module RETURNS what should be kept. The product stores it through
 * LBM\Support\ModuleSettings, sealed when the field is declared a secret. A
 * module still never writes the database.
 *
 * It is asked after every save of the gateway's Configure page, and when the
 * operator presses Register webhook there.
 */
interface RegistersWebhook
{
    /**
     * Whether The Current Mode Still Needs Its Webhook Registered
     *
     * True only when there is something to register WITH - a usable key for the
     * mode - and nothing registered yet. So saving a page that already works
     * never touches the provider.
     * @return bool
     */
    public function needsWebhook(): bool;

    /**
     * Register The Webhook At This Address, For The Current Mode
     *
     * Leave anything this installation did not create alone: an endpoint the
     * operator added by hand at the same address is theirs.
     * @param string $url This installation's webhook address for the gateway
     * @return array{success: bool, settings: array<string,string>, message: string}
     *   `settings` are DECLARED field names and the values to store; anything
     *   else is dropped. `message` is shown to the operator either way, so say
     *   what happened in words - the provider's own when it refused.
     */
    public function registerWebhook(string $url): array;
}
