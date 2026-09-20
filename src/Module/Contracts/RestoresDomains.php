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
 * A registrar that can bring a domain back out of redemption - Phase 50.
 *
 * Optional, beside RegistrarInterface. Past the renewal grace window a registry
 * no longer accepts a plain renewal: the name has to be RESTORED (the RGP
 * restore), which is a separate command at a separate price - `tlds.restore_price`,
 * billed on the renewal invoice by Action\DomainRenewal when the domain enters
 * redemption.
 *
 * Once that invoice is paid, a domain in redemption is sent here instead of to
 * renew(). A registrar without this contract leaves the domain for staff to
 * restore by hand, exactly as a domain with no module at all is - sending a
 * renewal the registry is bound to refuse would only burn the retry budget.
 */
interface RestoresDomains
{
    /**
     * Restore a Domain From Redemption, Renewing It
     *
     * Most registries renew as part of the restore; a module whose registry
     * does not should renew straight after, inside this call, so the product
     * sees one outcome for one paid invoice.
     * @param array $domain The `domains` row
     * @param int $years The term paid for on the renewal line
     * @param array $context See RegistrarInterface::register()
     * @return array{success: bool, expiry_date: ?string, reference: ?string, message: ?string, raw: array}
     *   The same shape as RegistrarInterface::renew(); `expiry_date` as the
     *   registry reports it, never a year added locally.
     */
    public function restore(array $domain, int $years = 1, array $context = []): array;
}
