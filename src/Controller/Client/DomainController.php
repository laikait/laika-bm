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

namespace LBM\Controller\Client;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use RuntimeException;
use Laika\Service\Request;
use LBM\Service\Domain;

/**
 * A client's domains.
 *
 * One thing here is writable: the nameservers. That is the setting people
 * genuinely need at odd hours - pointing a domain at a new host - and it is the
 * one that costs an operator a support ticket every time it has to be done for
 * somebody.
 *
 * Everything else is read-only. Renewing, transferring and unlocking all cost
 * money or move ownership, and both belong to a registrar module talking to a
 * real registrar rather than to a form that only updates this database.
 *
 * The nameserver write does not reach a registrar either - the registrar
 * runtime is a later phase - so it records the intent and the screen says so.
 * Silently storing a change and letting somebody believe their DNS moved would
 * be worse than not offering it.
 */
class DomainController extends ClientController
{
    /** @var int The Fewest Nameservers a Domain Needs */
    private const MINIMUM = 2;

    /** @var int The Most The Form Accepts */
    private const MAXIMUM = 5;

    protected function nav(): string
    {
        return 'domains';
    }

    /**
     * The Client's Domains
     * @return string
     */
    public function index(): string
    {
        $this->allow('domain');

        $page = Domain::browseForClient($this->owner());

        foreach ($page['rows'] as $index => $row) {
            $page['rows'][$index]['days_left'] = Domain::daysToExpiry($row);
            $page['rows'][$index]['expired'] = Domain::isExpired($row);
        }

        return $this->screen('domains', 'My domains', [
            'pager'    =>  $page,
            'statuses' =>  Domain::statuses(),
        ]);
    }

    /**
     * One Domain
     * @param string $domain Domain Uid
     * @return string
     */
    public function show(string $domain): string
    {
        $this->allow('domain');

        $row = $this->domain($domain);

        return $this->screen('domain', (string) $row['domain'], [
            'domain'      =>  $row,
            'nameservers' =>  Domain::nameservers((int) $row['domain_id']),
            'expired'     =>  Domain::isExpired($row),
            'days'        =>  Domain::daysToExpiry($row),
            'minimum'     =>  self::MINIMUM,
            'maximum'     =>  self::MAXIMUM,
        ]);
    }

    /**
     * Point a Domain At Different Nameservers
     * @param string $domain Domain Uid
     * @return ?string
     */
    public function nameservers(string $domain): ?string
    {
        $this->allow('domain', self::UPDATE);

        $row = $this->domain($domain);
        $id = (int) $row['domain_id'];

        $submitted = Request::input('nameservers', []);

        // A textarea posts one string and a repeated field posts an array; the
        // form can produce either, so both are accepted.
        $hosts = is_array($submitted)
            ? $submitted
            : (preg_split('/[\s,]+/', (string) $submitted) ?: []);

        return $this->attempt(
            function () use ($id, $hosts, $row): void {
                $clean = $this->clean($hosts);

                // Refused rather than stored: a domain with one nameserver is a
                // domain that stops resolving the moment that host blinks, and
                // an empty list is a domain that stops resolving at once.
                if (count($clean) < self::MINIMUM) {
                    throw new RuntimeException(
                        'A domain needs at least ' . self::MINIMUM . ' nameservers.'
                    );
                }

                if (count($clean) > self::MAXIMUM) {
                    throw new RuntimeException(
                        'A domain takes at most ' . self::MAXIMUM . ' nameservers.'
                    );
                }

                Domain::setNameservers($id, $clean);

                $this->log(
                    'domain.nameservers.changed',
                    'Changed the nameservers on ' . $row['domain'] . '.',
                    ['nameservers' => implode(', ', $clean)]
                );
            },
            'client.domain',
            'Your nameservers have been recorded and passed to support.',
            ['domain' => $row['uid']]
        );
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Resolve One Of The Client's Own Domains, Or 404
     * @param string $uid Domain Uid
     * @return array
     */
    private function domain(string $uid): array
    {
        return $this->mine(
            static fn(int|string $key, int $clientId): ?array => Domain::forClientKey($key, $clientId),
            $uid,
            'domain'
        );
    }

    /**
     * Tidy Submitted Hostnames
     *
     * Blanks dropped, duplicates dropped, case folded. The form has five rows
     * and most people fill two, so most of what arrives is empty.
     * @param array $hosts Submitted Hostnames
     * @return string[]
     */
    private function clean(array $hosts): array
    {
        $clean = [];

        foreach ($hosts as $host) {
            $host = strtolower(trim((string) $host));

            if ($host !== '' && !in_array($host, $clean, true)) {
                $clean[] = $host;
            }
        }

        return $clean;
    }
}
