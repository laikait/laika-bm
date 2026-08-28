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
 * What a payment gateway module has to provide.
 *
 * Money is the thing here, so the shape is deliberately strict about two
 * points.
 *
 * **Amounts are decimal strings, never floats.** Everything in LBM's money path
 * goes through `LBM\Support\Money`, which is bcmath over strings at a scale of
 * six. A float `0.1 + 0.2` is not `0.3`, and a billing system that rounds
 * differently from the payment processor produces invoices nobody can reconcile.
 *
 * **Nothing here writes to the database.** A gateway reports what happened; the
 * caller records it through `LBM\Action\Transaction`, which is the only place
 * that writes a `transactions` row and settles the invoice behind it. A gateway
 * that wrote its own ledger row would be a second, divergent source of truth
 * about what a client has paid.
 */
interface GatewayInterface
{
    /**
     * Take a Payment
     *
     * @param array $payment {
     *     @type string $amount        Decimal string, never a float
     *     @type string $currency      ISO 4217 code. Example: 'USD'
     *     @type int    $invoice_id    The invoice being settled
     *     @type int    $client_id     Who is paying
     *     @type string $description   What appears on their statement
     *     @type array  $client        The client row, for billing details
     *     @type string $return_url    Where a redirecting gateway comes back to
     * }
     * @return array{
     *     success: bool,
     *     reference: ?string,
     *     amount: ?string,
     *     fee: ?string,
     *     redirect: ?string,
     *     pending: bool,
     *     message: ?string,
     *     raw: array
     * }
     *   `redirect` is a URL the client must be sent to, for gateways that take
     *   payment on their own pages - in which case `pending` is true and the
     *   real outcome arrives at webhook().
     */
    public function charge(array $payment): array;

    /**
     * Give Money Back
     *
     * @param string $reference The gateway's own reference for the original payment
     * @param string $amount Decimal string. The whole payment when it equals the original
     * @param string $reason Why, for the gateway's records
     * @return array{success: bool, reference: ?string, amount: ?string, message: ?string, raw: array}
     */
    public function refund(string $reference, string $amount, string $reason = ''): array;

    /**
     * Make Sense Of a Callback From The Gateway
     *
     * Called with whatever arrived on the module's own webhook route. The
     * implementation is responsible for verifying the signature and **must**
     * return `verified => false` when it cannot: an unverified callback is an
     * instruction from a stranger to mark an invoice paid.
     *
     * @param array $payload Decoded request body
     * @param array $headers Request headers, for the signature
     * @return array{
     *     verified: bool,
     *     event: ?string,
     *     reference: ?string,
     *     invoice_id: ?int,
     *     amount: ?string,
     *     success: bool,
     *     message: ?string,
     *     raw: array
     * }
     */
    public function webhook(array $payload, array $headers = []): array;
}
