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

namespace LBM\Controller\Webhook;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Throwable;
use Laika\Service\Request;
use Laika\Service\Response;
use LBM\Service\Gateway;
use LBM\Service\GatewayCallback;

/**
 * Where a payment gateway calls us back.
 *
 * `POST /webhook/{gateway}`, public, unauthenticated - because the caller is a
 * payment processor's server, not a person with a session.
 *
 * ---------------------------------------------------------------------------
 * This is a TRANSPORT ADAPTER. It decides nothing about money.
 * ---------------------------------------------------------------------------
 * It reads the body, finds the gateway, hands both to the driver, and hands the
 * driver's answer to LBM\Action\GatewayCallback. Every rule about what a
 * callback means - verification, idempotency, which invoice, how much - lives
 * in the action, where it can be exercised without an HTTP request. A webhook
 * handler that also decides things is a webhook handler that can only be tested
 * by pretending to be a payment processor.
 *
 * ---------------------------------------------------------------------------
 * It does NOT extend Controller, and that is deliberate
 * ---------------------------------------------------------------------------
 * Controller exists to render a template into a theme. There is nothing to
 * render here - the response is a line of text a machine reads - and inheriting
 * a template stack would mean a missing theme could turn a payment
 * notification into a 500. The gateway would then retry, indefinitely, against
 * a fault that has nothing to do with the payment.
 *
 * ---------------------------------------------------------------------------
 * The status codes are a contract with the gateway
 * ---------------------------------------------------------------------------
 * Every processor treats a non-2xx as "try again later". So:
 *
 *   200  we are finished with this event - applied, duplicate, or deliberately
 *        ignored. Do not send it again.
 *   400  the signature did not verify. Loud on purpose: retries and the
 *        operator's alerting are both the right response to a callback we
 *        cannot trust.
 *   404  no such gateway, or it is switched off. Nothing here to call.
 *   422  understood, and refused - no reference, no such invoice, no usable
 *        amount. A retry cannot fix any of those, but the operator needs to see
 *        it rather than have it silently swallowed by a 200.
 *   500  we failed to record it. Retrying is exactly right.
 *
 * A duplicate answering 200 matters more than it looks: it is the reply to a
 * gateway that did not hear our first 200, and answering anything else asks for
 * the retry that the idempotency key exists to survive.
 */
class GatewayWebhookController
{
    /**
     * Receive a Callback
     *
     * @param string $gateway Gateway Slug, from the URL
     * @return ?string
     */
    public function receive(string $gateway): ?string
    {
        // payableBySlug() rather than a bare lookup: it requires the gateway to
        // be active AND its driver to build. A callback for a gateway the
        // operator has switched off is not something to act on, and one whose
        // module has been removed has nothing to interpret it.
        $row = Gateway::payableBySlug($gateway);

        if ($row === null) {
            return $this->answer(404, 'Unknown gateway.');
        }

        $driver = Gateway::driverFor($row);

        if ($driver === null) {
            return $this->answer(404, 'Unknown gateway.');
        }

        $payload = $this->payload();

        try {
            $result = $driver->webhook($payload, Request::headers());
        } catch (Throwable) {
            // A driver that throws has told us nothing, so nothing can be
            // applied - but the attempt is still worth recording, and it is
            // recorded as unverified, which is what an answer we could not
            // obtain amounts to. No exception message reaches the response:
            // the caller is unauthenticated until a signature says otherwise.
            $result = [
                'verified' => false,
                'message'  => 'The gateway module raised an error while reading this callback.',
            ];
        }

        $outcome = GatewayCallback::receive($row, is_array($result) ? $result : [], $payload);

        return $this->answer((int) $outcome['status'], (string) $outcome['message']);
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * The Request Body, However It Arrived
     *
     * Gateways send JSON, and older ones send form-encoded bodies. Both are
     * handed to the driver as an array, because a driver should be written
     * against what the gateway sends rather than against how this application
     * happened to parse it.
     *
     * The RAW body is passed through as `__raw` untouched, because a signature
     * is computed over bytes: re-encoding a decoded payload changes key order
     * and whitespace, and the signature then never matches. A driver that
     * checks an HMAC needs the original.
     * @return array
     */
    private function payload(): array
    {
        $raw = Request::raw();

        $decoded = null;

        if (trim($raw) !== '') {
            $decoded = json_decode($raw, true);
        }

        if (!is_array($decoded)) {
            // Not JSON. Fall back to whatever the request parser made of it,
            // which covers application/x-www-form-urlencoded.
            $decoded = Request::inputs();
        }

        if (!is_array($decoded)) {
            $decoded = [];
        }

        $decoded['__raw'] = $raw;

        return $decoded;
    }

    /**
     * Answer The Gateway
     *
     * Through the Response service, not http_response_code(): the renderer
     * writes the service's status last, so anything set directly is overwritten
     * - the same trap FrontController::notFound() documents.
     * @param int $status HTTP Status
     * @param string $message One line, for a human reading a delivery log
     * @return string
     */
    private function answer(int $status, string $message): string
    {
        Response::setStatus($status);
        Response::setContentType('text/plain');

        return $message . "\n";
    }
}
