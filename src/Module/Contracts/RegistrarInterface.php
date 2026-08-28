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
}
