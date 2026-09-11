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

use Laika\Service\Request;
use LBM\Service\Currency;
use LBM\Service\Domain;
use LBM\Service\Lookup;
use LBM\Service\Tld;

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
 *   - An install with no lookup module and no registrar module cannot ask
 *     anybody. Every name comes back unknown, and the visitor is told the
 *     operator will check. That is a supported way to run this shop, exactly
 *     as a product with no provisioning module is: Phase 22.4 settled the same
 *     question the same way.
 *   - A lookup that failed is not a name that is taken. Showing it as taken
 *     turns a bad afternoon at the registry into a shop that refuses to sell
 *     anything, and nobody watching the screen would know why.
 *
 * A name already in this install `domains` table is TAKEN whatever a registry
 * says, because the column is UNIQUE and the order could not be recorded.
 *
 * Who is asked is `Action\Lookup`'s business, not this screen's - since Phase
 * 36 a lookup module first and the TLD's registrar second.
 *
 * ---------------------------------------------------------------------------
 * THE LOOKUPS ARE BOUNDED - IN NUMBER AND IN TIME
 * ---------------------------------------------------------------------------
 * Every TLD checked is a call over somebody else network. An operator with
 * forty TLDs on the price list would otherwise turn one search box into
 * forty blocking calls, and the page would time out rather than sell anything.
 * MAX_LOOKUPS is the cap; TLDs past it are still listed with their price and
 * an unknown availability, which is the same state a missing module produces
 * and needs no second explanation on the screen.
 *
 * A count alone is not a bound, and Phase 36 is what made that matter: ten
 * WHOIS queries against a registry that has stopped answering is ten timeouts
 * back to back. LOOKUP_BUDGET is the whole search's allowance, each question
 * is handed what is LEFT of it so a module can bound its own wait, and once it
 * is spent the rest of the list is shown unknown, exactly as past the cap.
 */
class DomainController extends FrontController
{
    /** @var int Most TLDs One Search Will Ask About */
    public const MAX_LOOKUPS = 10;

    /** @var float Seconds One Search May Spend Asking, Across Every TLD */
    public const LOOKUP_BUDGET = 8.0;

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

        $tlds = Tld::forSale();

        return $this->screen('domains', local('domains'), [
            'meta_description' =>  local('domains_meta', app_name()),
            'query'            =>  $query,
            'currency'         =>  $currency,
            'tlds'             =>  $this->priceList($tlds, $currencyId),
            'results'          =>  $query === '' ? [] : $this->search($query, $tlds, $currencyId),
            'searched'         =>  $query !== '',
            'invalid'          =>  $query !== '' && Tld::normaliseDomain($this->withTld($query, $tlds)) === null,
        ]);
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * The Price List, For The Table Under The Search Box
     *
     * TLDs with no price in this currency are dropped rather than shown at
     * nothing. Tld::priceFor() explains why an unpriced TLD is not a free
     * one, and a row reading 0.00 is a promise the shop cannot keep.
     * @param array $tlds Active TLD Rows
     * @param int $currencyId Currency ID
     * @return array<int,array<string,mixed>>
     */
    private function priceList(array $tlds, int $currencyId): array
    {
        $rows = [];

        foreach ($tlds as $tld) {
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
     * What The Visitor Asked For, Across Every TLD On Sale
     *
     * The exact name typed comes first, then the same label on every other
     * TLD. That order matters: somebody who typed `example.com` wants to
     * know about `example.com`, and burying it among suggestions in
     * alphabetical order is how a search box stops answering the question.
     * It also puts the one name they asked about first in the time budget.
     * @param string $query What Was Typed
     * @param array $tlds Active TLD Rows
     * @param int $currencyId Currency ID
     * @return array<int,array<string,mixed>>
     */
    private function search(string $query, array $tlds, int $currencyId): array
    {
        $typed = Tld::normaliseDomain($this->withTld($query, $tlds));

        if ($typed === null) {
            return [];
        }

        // The label is what is left once a known TLD is removed. When the
        // visitor typed a TLD nobody sells, everything before the first dot
        // is the best guess available.
        $split = Tld::split($typed);
        $label = is_array($split) ? $split['name'] : explode('.', $typed)[0];

        if ($label === '') {
            return [];
        }

        $exact = is_array($split) ? (int) $split['row']['tld_id'] : 0;
        $ordered = [];

        foreach ($tlds as $tld) {
            if ((int) $tld['tld_id'] === $exact) {
                array_unshift($ordered, $tld);

                continue;
            }

            $ordered[] = $tld;
        }

        $results = [];
        $asked = 0;
        $started = microtime(true);

        foreach ($ordered as $tld) {
            $tldName = Tld::normaliseTld((string) $tld['tld']);
            $name = $label . $tldName;

            $terms = Tld::termsFor($tld);
            $years = $terms === [] ? 0 : $terms[0];
            $price = $years === 0 ? null : Tld::priceFor($tld, $currencyId, $years, 'register');

            $row = [
                'domain'    =>  $name,
                'tld'       =>  $tldName,
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

            $left = self::LOOKUP_BUDGET - (microtime(true) - $started);

            if ($asked < self::MAX_LOOKUPS && $left > 0) {
                $row['available'] = Lookup::available($tld, $name, $left);
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
     * Give a Bare Label A TLD So It Can Be Looked Up
     *
     * Somebody who types `example` and presses the button has asked a real
     * question, and answering it with "that is not a domain" is a search box
     * refusing to search. The first TLD on the price list stands in, and
     * every other TLD is offered beside it anyway.
     * @param string $query What Was Typed
     * @param array $tlds Active TLD Rows
     * @return string
     */
    private function withTld(string $query, array $tlds): string
    {
        $query = strtolower(trim($query));

        if ($query === '' || str_contains($query, '.')) {
            return $query;
        }

        $first = $tlds[0] ?? null;

        return is_array($first) ? $query . Tld::normaliseTld((string) $first['tld']) : $query;
    }
}
