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
 * What a domain registrar module has to provide.
 *
 * The `domains` table is a record of what the client owns; a registrar module is
 * what makes that record true at the registry. Where the two disagree, the
 * registry is right - so every method here returns what the registrar actually
 * did rather than confirming what it was asked to do, and the caller updates the
 * row from the answer.
 *
 * `nameservers()` is the one a client can reach, through the client area. The
 * others cost money or move ownership and stay with staff.
 */
interface RegistrarInterface
{
    /**
     * Ask The Registry Whether a Domain Can Be Registered
     *
     * Added in Phase 27.1, and its absence until then was a real hole rather
     * than an omission: without it the shop takes money for a name and finds
     * out at the registry that somebody else already has it, which is a refund
     * and an apology on every single one.
     *
     * `available` is DELIBERATELY nullable, and the three answers are three
     * different things:
     *
     *   - true  - it can be registered.
     *   - false - it cannot; somebody has it.
     *   - null  - the registrar could not say. A lookup that timed out is not
     *             a domain that is taken, and treating it as one turns a slow
     *             afternoon at the registry into a shop that sells nothing.
     *
     * `success` is about the CALL, `available` about the domain. A driver that
     * cannot reach its API returns success false and available null.
     *
     * @param string $domain The name being asked about, including its ending
     * @param array $context See register()
     * @return array{success: bool, available: ?bool, message: ?string, raw: array}
     */
    public function available(string $domain, array $context = []): array;

    /**
     * Register a Domain
     *
     * @param array $domain The `domains` row
     * @param array $context {
     *     @type array    $client      Who it is for
     *     @type array    $contacts    Registrant/admin/tech/billing contacts
     *     @type int      $years       Term
     *     @type string[] $nameservers What it should point at
     * }
     * @return array{
     *     success: bool,
     *     expiry_date: ?string,
     *     reference: ?string,
     *     message: ?string,
     *     raw: array
     * }
     *   `expiry_date` is `Y-m-d H:i:s` **as the registry reports it** - not a
     *   year added locally. A renewal date the registry does not agree with is
     *   how a domain expires while the panel says it is fine.
     */
    public function register(array $domain, array $context = []): array;

    /**
     * Extend The Registration
     *
     * @param array $domain The `domains` row
     * @param int $years How many more
     * @param array $context See register()
     * @return array{success: bool, expiry_date: ?string, reference: ?string, message: ?string, raw: array}
     */
    public function renew(array $domain, int $years = 1, array $context = []): array;

    /**
     * Bring a Domain In From Another Registrar
     *
     * @param array $domain The `domains` row
     * @param string $authCode The EPP/auth code from the losing registrar
     * @param array $context See register()
     * @return array{
     *     success: bool,
     *     pending: bool,
     *     expiry_date: ?string,
     *     reference: ?string,
     *     message: ?string,
     *     raw: array
     * }
     *   A transfer is rarely immediate - it usually waits on the losing
     *   registrar or on the registrant approving it - so `pending` true with
     *   `success` true is the normal answer, not a failure.
     */
    public function transfer(array $domain, string $authCode, array $context = []): array;

    /**
     * Read Or Replace The Nameservers
     *
     * One method for both, because they are the same registry call with and
     * without a payload, and splitting them would have two implementations to
     * keep in agreement about ordering.
     *
     * Passing null reads. Passing a list replaces the set - order is meaningful,
     * ns1 is not ns2 - and the return value is what the registry holds
     * afterwards, which is not always what was asked for.
     * @param array $domain The `domains` row
     * @param ?string[] $hosts Null to read, a list to replace
     * @param array $context See register()
     * @return array{success: bool, nameservers: string[], message: ?string, raw: array}
     */
    public function nameservers(array $domain, ?array $hosts = null, array $context = []): array;

    /**
     * Read Or Replace The Registry Contacts
     *
     * Added in Phase 29, and its absence was the same shape of hole
     * `available()` was before 27.1. `register()` has declared a `contacts` key
     * in its context since Phase 9 and every caller passed an EMPTY ARRAY, so a
     * driver was asked to register a name with nobody named on it. A registry
     * either refuses that or - worse, and this is what resellers actually hit -
     * substitutes the account holder's own details, which makes the OPERATOR
     * the registrant of their customer's domain.
     *
     * Safe to add for 27.1's reason: LBM has never shipped a registrar driver
     * and this interface had no third-party implementations, so nobody can have
     * one to break.
     *
     * Read and replace are one method for `nameservers()`'s reason - they are
     * the same registry call with and without a payload, and two methods would
     * be two implementations to keep in agreement about shape.
     *
     * Passing null reads. Passing a set replaces it. The return value is WHAT
     * THE REGISTRY HOLDS afterwards, which is not always what was asked for: a
     * registry may reject a field, normalise a country, or - for many endings -
     * refuse a registrant change outright because that is a trade rather than
     * an edit. Storing the request instead is how a panel comes to disagree
     * with the registry about who owns a name.
     *
     * @param array $domain The `domains` row
     * @param ?array<string,array> $contacts Null to read; otherwise keyed by
     *   type - registrant, admin, tech, billing, abuse - each a flat array of
     *   company_name, first_name, last_name, email, phone_cc, phone_number,
     *   address1, address2, city, state, postcode and country (ISO 2)
     * @param array $context See register()
     * @return array{success: bool, contacts: array<string,array>, message: ?string, raw: array}
     */
    public function contacts(array $domain, ?array $contacts = null, array $context = []): array;
}
