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
 * What a domain lookup module has to provide - Phase 36.
 *
 * One question: can this name be registered? Until Phase 36 only a REGISTRAR
 * module could answer it, so an operator who registers names by hand - a
 * supported way to run this shop - had a public search that answered "we will
 * check this one for you" about every name anybody typed.
 *
 * A lookup module answers without registering anything: a WHOIS or RDAP query,
 * or a third-party availability service. It moves no money and no ownership,
 * which is why one can ship switched on where a registrar never could.
 *
 * ---------------------------------------------------------------------------
 * THE SAME VERB AS RegistrarInterface::available(), DELIBERATELY
 * ---------------------------------------------------------------------------
 * Same parameters, same return shape, same three answers. This answer fills the
 * slot a registrar's used to, and two shapes for one answer is how a caller
 * comes to read `available: "yes"` as free.
 *
 *   - true  - it can be registered.
 *   - false - it cannot; somebody has it.
 *   - null  - could not say: a timeout, a rate limit, an answer in a format
 *             nobody recognises, an answer that contradicts itself. Never a
 *             name somebody has.
 *
 * `success` is about the CALL, `available` about the domain. A failed call's
 * `available` is never read, whatever it says.
 *
 * ---------------------------------------------------------------------------
 * WHO IS ASKED, AND IN WHAT ORDER
 * ---------------------------------------------------------------------------
 * `LBM\Action\Lookup`: the lookup module the operator chose, then the TLD's
 * registrar, then "could not say". Anything but a boolean from a successful
 * call here - null, a failed call, an exception - hands the question on to the
 * registrar. A WHOIS query costs nothing; a registrar call spends an API
 * credential and is rate-limited, which is why this goes first.
 *
 * ---------------------------------------------------------------------------
 * WHAT A DRIVER IS GIVEN
 * ---------------------------------------------------------------------------
 * Nothing at construction - it is built with no arguments, and a provider that
 * needs an API key reads its own option. Each call's CONTEXT carries:
 *
 *   timeout  float   Seconds this call may take. A public search has ONE
 *                    budget for every TLD it asks about and this is what is
 *                    left of it, so it shrinks across a single search. Honour
 *                    it: a module that waits longer is a page that never loads.
 *   tld      string  The TLD being asked about, with its leading dot.
 *
 * A driver should not throw. If it does, the caller treats it as "could not
 * say" and asks the registrar - but it is not written to the error log, because
 * a public search is driven by visitors and bots ten names at a time, and a
 * broken provider would fill the log by the minute.
 */
interface LookupInterface
{
    /**
     * Ask Whether a Domain Can Be Registered
     *
     * @param string $domain The name being asked about, including its TLD
     * @param array $context `timeout` and `tld` - see the interface docblock
     * @return array{success: bool, available: ?bool, message: ?string, raw: array}
     */
    public function available(string $domain, array $context = []): array;
}
