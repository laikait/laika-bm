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
 * What a fraud screening module has to provide - Phase 41.
 *
 * `modules/fraud` has been a module type since Phase 20.1 and nothing ever
 * called one: there was no contract, and no caller. Checkout is the caller now
 * - `Action\Fraud::screen()`, between the order being written and its invoice
 * being raised - and only when an operator has chosen a fraud module.
 *
 * ---------------------------------------------------------------------------
 * FOUR ANSWERS, AND WHAT EACH ONE DOES
 * ---------------------------------------------------------------------------
 *   pass    - invoiced as normal.
 *   review  - HELD: the order is written with the `fraud` status and NO
 *             invoice, for staff to accept or cancel on the order screen.
 *   fail    - held the same way. Never refused outright: a false positive
 *             refused at checkout is a lost customer nobody hears about, and one
 *             held is an order staff can still accept.
 *   null    - could not say. The order goes through exactly as if no module
 *             were chosen. A screening service that is down must not stop every
 *             sale in the shop.
 *
 * `success` is about the CALL. A failed call is could-not-say whatever verdict
 * it carries, and so is a verdict that is not one of the three words above.
 *
 * `score` is optional, a number from 0 to 100, and is written to
 * `orders.fraud_score` whatever the verdict - staff read it on the order.
 *
 * ---------------------------------------------------------------------------
 * WHAT A DRIVER IS GIVEN
 * ---------------------------------------------------------------------------
 * At construction, as every module: the fields it declares through
 * `Contracts\Configurable`, opened, plus `mode` - live or test. See
 * `LBM\Module\Api`. `Action\Fraud` is the only place a fraud driver is built.
 *
 * A driver should not throw. If it does, the order goes through and the
 * exception is written to the error log against the module - unlike a lookup,
 * a checkout is not something a bot can do ten times a second, so the log
 * cannot be flooded by it.
 */
interface FraudInterface
{
    /**
     * Screen An Order Before It Is Invoiced
     *
     * @param array $order The `orders` row, as written a moment ago
     * @param array $context {
     *     @type array  $client    The client row placing it
     *     @type string $email     Their email address
     *     @type string $ip        Where the order came from
     *     @type string $amount    What the order is for - a decimal string
     *     @type string $currency  ISO 4217 code
     *     @type string $country   The client's country, ISO 3166 alpha-2, or ''
     *     @type float  $timeout   Seconds this call may take - a customer is waiting
     * }
     * @return array{
     *     success: bool,
     *     verdict: ?string,
     *     score: ?string,
     *     message: ?string,
     *     raw: array
     * }
     *   `verdict` is `pass`, `review`, `fail`, or null for could not say.
     */
    public function check(array $order, array $context = []): array;
}
