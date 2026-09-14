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
 * A gateway that takes the card ON THE SITE - Phase 48.
 *
 * There are two kinds of payment gateway, and the kind is the class:
 *
 *   THIRD-PARTY  GatewayInterface alone. The customer pays on the provider's
 *                own page (a redirect) or away from the site altogether (bank
 *                transfer). The site never sees card details.
 *   TOKENIZER    this interface. The card box sits on the invoice page, drawn
 *                inside the PROVIDER'S OWN frame by the provider's script. That
 *                script turns the card into a token, and only the token is
 *                posted here - card numbers never touch this application, which
 *                is what keeps the operator at the lowest PCI level. The token is
 *                charged at once, and can be SAVED and charged again later.
 *
 * Nothing stored decides the kind: a setting that said "tokenizer" over a class
 * that is not one would draw a card field nothing can charge.
 *
 * ---------------------------------------------------------------------------
 * WHAT charge() IS GIVEN BESIDES GatewayInterface'S KEYS
 * ---------------------------------------------------------------------------
 *   token        The single-use token the page's card field made, or ''.
 *   pay_method   A SAVED token, opened - exactly as store()/`saved` returned it.
 *   save         true: keep the card for later, and answer `saved`.
 *   off_session  true: the customer is not there (auto-charge, staff). Nothing
 *                can be asked of them, so a bank that wants them answers
 *                failure with `authenticate` true.
 *   attempt_key  An idempotency key the product chose. Send it as the
 *                provider's, so two charges of one balance on one day are one.
 *
 * And may answer, besides GatewayInterface's keys:
 *   saved        {token, brand, last_four, exp_month, exp_year} - what to keep.
 *   authenticate true when the bank wants the customer present.
 *
 * A charge that needs the customer at their bank (3-D Secure) answers
 * `redirect` with `pending` true, like any third-party gateway; they come back
 * to the `return_url` given, and complete() says what happened.
 *
 * THE TOKEN IS OPAQUE. The product seals it, stores it and hands it back. What is
 * inside is the module's business.
 */
interface TokenizerInterface extends GatewayInterface
{
    /**
     * The Module's Browser Adapter
     *
     * An absolute path to a JavaScript file INSIDE the module's own directory.
     * modules/ is closed to the web, so the product serves it, and refuses any
     * path that resolves outside the module. The file calls
     * `LBMTokenizer.register(name, factory)`, where `factory(host, config)`
     * mounts the provider's card field into `host` and returns an object whose
     * `tokenize()` resolves to `{token}` or `{error}`.
     * @return string
     */
    public static function script(): string;

    /**
     * What The Page Needs To Draw The Card Field - PUBLIC Values Only
     *
     * Everything returned here is printed into a page anybody with the invoice
     * can see. A secret here is the worst mistake this contract allows.
     * @param array $context {
     *     @type string $purpose     payment Or setup (saving a card without paying)
     *     @type string $amount      Decimal string, for a payment
     *     @type string $currency    ISO 4217 code
     *     @type array  $client      The client row
     * }
     * @return array{scripts: string[], adapter: string, config: array, error?: string}
     *   `scripts` load before the adapter - the provider's own library, from the
     *   provider. `adapter` is the name the adapter registers under. `error`,
     *   when set, is why no field can be drawn, in words.
     */
    public function browser(array $context): array;

    /**
     * Save a Card Without Paying
     * @param array $client The client row
     * @param string $token The single-use token the page made
     * @param array $context {return_url: string} - where 3-D Secure comes back to
     * @return array{success: bool, pending: bool, redirect: ?string, saved: ?array, message: ?string}
     */
    public function store(array $client, string $token, array $context = []): array;

    /**
     * The Customer Is Back From Their Bank
     *
     * MUST look the payment up AT THE PROVIDER. The query string is the
     * customer's browser talking, and it can say anything. And it MUST refuse a
     * payment made for another invoice, or a card saved for another client:
     * otherwise anybody could settle their invoice with somebody else's money by
     * coming back with its id.
     * @param array $query What the provider appended to the return address
     * @param array $context {purpose: payment|setup, invoice_id: int, client_id: int}
     * @return array The charge() shape for a payment, the store() shape for a setup
     */
    public function complete(array $query, array $context): array;

    /**
     * Forget a Saved Card At The Provider
     * @param string $token The saved token, opened
     * @return array{success: bool, message: ?string}
     */
    public function forget(string $token): array;
}
