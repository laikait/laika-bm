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

namespace LBM\Controller\Front;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Throwable;
use Laika\Service\Request;
use LBM\Service\Currency;
use LBM\Service\Domain;
use LBM\Service\Tld;
use LBM\Support\RegistersDomains;

/**
 * The public domain search - what a visitor types a name into.
 *
 * A GET, because it is a search: the result is a URL somebody can send to a
 * colleague or come back to, and nothing here writes. Ordering happens on the
 * cart route, which is a POST like every other mutation.
 *
 * ---------------------------------------------------------------------------
 * THREE ANSWERS, NOT TWO
 * ---------------------------------------------------------------------------
 * Available, taken, and NOT KNOWN - and the third is the one that has to be
 * shown honestly rather than folded into one of the others.
 *
 *   - An install with no registrar module cannot ask anybody. Every name comes
 *     back unknown, and the visitor is told the operator will check. That is a
 *     supported way to run this shop, exactly as a product with no provisioning
 *     module is: Phase 22.4 settled the same question the same way.
 *   - A lookup that failed is not a name that is taken. Showing it as taken
 *     turns a bad afternoon at the registry into a shop that refuses to sell
 *     anything, and nobody watching the screen would know why.
 *
 * A name already in this install `domains` table is TAKEN whatever a registry
 * says, because the column is UNIQUE and the order could not be recorded.
 *
 * ---------------------------------------------------------------------------
 * THE LOOKUPS ARE BOUNDED
 * ---------------------------------------------------------------------------
 * Every ending checked is a call over somebody else network. An operator with
 * forty endings on the price list would otherwise turn one search box into
 * forty blocking calls, and the page would time out rather than sell anything.
 * MAX_LOOKUPS is the cap; endings past it are still listed with their price and
 * an unknown availability, which is the same state a missing module produces
 * and needs no second explanation on the screen.
 */
class DomainController extends FrontController
{
    use RegistersDomains;

    /** @var int Most Endings One Search Will Ask a Registrar About */
    public const MAX_LOOKUPS = 10;

    /**
     * Which Top-Nav Item Is Current
     * @return string
     */
    protected function nav(): string
    {
        return 'domains';
    }

    /**
     * Search For a Domain, Or Just Show The Price List
     * @return string
     */
    public function index(): string
    {
        $query = trim((string) Request::input('q', ''));
        $currency = Currency::default();
        $currencyId = (int) ($currency['currency_id'] ?? 0);

        $endings = Tld::forSale();

        return $this->screen('domains', local('domains'), [
            'meta_description' =>  local('domains_meta', app_name()),
            'query'            =>  $query,
            'currency'         =>  $currency,
            'endings'          =>  $this->priceList($endings, $currencyId),
            'results'          =>  $query === '' ? [] : $this->search($query, $endings, $currencyId),
            'searched'         =>  $query !== '',
            'invalid'          =>  $query !== '' && Tld::normaliseDomain($this->withEnding($query, $endings)) === null,
        ]);
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * The Price List, For The Table Under The Search Box
     *
     * Endings with no price in this currency are dropped rather than shown at
     * nothing. Tld::priceFor() explains why an unpriced ending is not a free
     * one, and a row reading 0.00 is a promise the shop cannot keep.
     * @param array $endings Active TLD Rows
     * @param int $currencyId Currency ID
     * @return array<int,array<string,mixed>>
     */
    private function priceList(array $endings, int $currencyId): array
    {
        $rows = [];

        foreach ($endings as $tld) {
            $price = Tld::priceFor($tld, $currencyId, (int) ($tld['min_years'] ?? 1) ?: 1, 'register');

            // min_years may be outside what a domain row can record at all, in
            // which case termsFor() is empty and there is nothing to quote.
            $terms = Tld::termsFor($tld);

            if ($price === null && $terms !== []) {
                $price = Tld::priceFor($tld, $currencyId, $terms[0], 'register');
            }

            if ($price === null) {
                continue;
            }

            $rows[] = [
                'tld'      =>  (string) $tld['tld'],
                'tld_id'   =>  (int) $tld['tld_id'],
                'price'    =>  $price,
                'years'    =>  $terms === [] ? 1 : $terms[0],
                'terms'    =>  $terms,
                'renew'    =>  Tld::priceFor($tld, $currencyId, $terms === [] ? 1 : $terms[0], 'renew'),
            ];
        }

        return $rows;
    }

    /**
     * What The Visitor Asked For, Across Every Ending On Sale
     *
     * The exact name typed comes first, then the same label on every other
     * ending. That order matters: somebody who typed `example.com` wants to
     * know about `example.com`, and burying it among suggestions in
     * alphabetical order is how a search box stops answering the question.
     * @param string $query What Was Typed
     * @param array $endings Active TLD Rows
     * @param int $currencyId Currency ID
     * @return array<int,array<string,mixed>>
     */
    private function search(string $query, array $endings, int $currencyId): array
    {
        $typed = Tld::normaliseDomain($this->withEnding($query, $endings));

        if ($typed === null) {
            return [];
        }

        // The label is what is left once a known ending is removed. When the
        // visitor typed an ending nobody sells, everything before the first dot
        // is the best guess available.
        $split = Tld::split($typed);
        $label = is_array($split) ? $split['name'] : explode('.', $typed)[0];

        if ($label === '') {
            return [];
        }

        $exact = is_array($split) ? (int) $split['row']['tld_id'] : 0;
        $ordered = [];

        foreach ($endings as $tld) {
            if ((int) $tld['tld_id'] === $exact) {
                array_unshift($ordered, $tld);

                continue;
            }

            $ordered[] = $tld;
        }

        $results = [];
        $asked = 0;

        foreach ($ordered as $tld) {
            $ending = Tld::normaliseTld((string) $tld['tld']);
            $name = $label . $ending;

            $terms = Tld::termsFor($tld);
            $years = $terms === [] ? 0 : $terms[0];
            $price = $years === 0 ? null : Tld::priceFor($tld, $currencyId, $years, 'register');

            $row = [
                'domain'    =>  $name,
                'tld'       =>  $ending,
                'tld_id'    =>  (int) $tld['tld_id'],
                'exact'     =>  (int) $tld['tld_id'] === $exact,
                'price'     =>  $price,
                'years'     =>  $years,
                'terms'     =>  $terms,
                'available' =>  null,
                'reason'    =>  null,

                // ONE SEARCH ANSWERS BOTH QUESTIONS. Whether a name is free
                // and whether it can be moved here are the same lookup read in
                // opposite directions - a taken name is exactly a
                // transferable one - so a second screen would ask somebody
                // else's registry the same question twice and then disagree
                // with itself when one of the two answers timed out.
                'transfer'  =>  Tld::transferPrice($tld, $currencyId),
                'movable'   =>  false,
            ];

            if ($price === null && $row['transfer'] === null) {
                $row['reason'] = 'no_price';
                $results[] = $row;

                continue;
            }

            // Already on this install. UNIQUE on the column, so no registry
            // answer could make this orderable - which is why it is checked
            // first and costs nothing.
            //
            // And NOT movable either, which is the one case where taken and
            // transferable come apart: a name this install already holds
            // cannot be transferred in from anywhere.
            if (Domain::byName($name) !== null) {
                $row['available'] = false;
                $row['reason'] = 'here';
                $results[] = $row;

                continue;
            }

            if ($asked < self::MAX_LOOKUPS) {
                $row['available'] = $this->ask($tld, $name);
                $asked++;
            }

            if ($row['available'] === false) {
                $row['reason'] = 'taken';
            }

            // Registered SOMEWHERE ELSE and priced for transfer. A `null`
            // availability is deliberately not movable: 27.1's third answer is
            // "not known", and offering to move a name nobody could confirm
            // exists takes money for a transfer that will be refused.
            $row['movable'] = $row['available'] === false && $row['transfer'] !== null;

            $results[] = $row;
        }

        return $results;
    }

    /**
     * Ask One Registrar Whether a Name Is Free
     *
     * Null for every way of not knowing - no module, a driver that threw, a
     * driver that reported failure, a driver that answered with something other
     * than a boolean. The contract makes the same distinction and for the same
     * reason: an unanswered question must never read as a refusal.
     * @param array $tld TLD Row
     * @param string $name The Name Being Asked About
     * @return ?bool
     */
    private function ask(array $tld, string $name): ?bool
    {
        $registrar = $this->registrarRow((int) ($tld['registrar_relid'] ?? 0));

        if (!is_array($registrar)) {
            return null;
        }

        $driver = $this->registrarDriver($registrar);

        if ($driver === null) {
            return null;
        }

        try {
            $result = $driver->available($name);
        } catch (Throwable) {
            return null;
        }

        if (!is_array($result) || empty($result['success'])) {
            return null;
        }

        $available = $result['available'] ?? null;

        return is_bool($available) ? $available : null;
    }

    /**
     * Give a Bare Label An Ending So It Can Be Looked Up
     *
     * Somebody who types `example` and presses the button has asked a real
     * question, and answering it with "that is not a domain" is a search box
     * refusing to search. The first ending on the price list stands in, and
     * every other ending is offered beside it anyway.
     * @param string $query What Was Typed
     * @param array $endings Active TLD Rows
     * @return string
     */
    private function withEnding(string $query, array $endings): string
    {
        $query = strtolower(trim($query));

        if ($query === '' || str_contains($query, '.')) {
            return $query;
        }

        $first = $endings[0] ?? null;

        return is_array($first) ? $query . Tld::normaliseTld((string) $first['tld']) : $query;
    }
}
