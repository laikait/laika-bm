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

namespace LBM\Action;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Laika\Model\Model;
use LBM\Model\DomainModel;
use LBM\Model\TldModel;
use LBM\Service\Money;
use RuntimeException;

/**
 * The domain price list - what the shop sells and what each TLD costs.
 *
 * `tlds` has existed since Phase 0 with ZERO callers anywhere in the product:
 * register, renew, transfer and restore prices, a term range, a registrar and a
 * currency, read by nothing. This class is the first thing that has ever looked
 * at it, which is what made every domain screen in the product a window onto a
 * table nothing could fill.
 *
 * ---------------------------------------------------------------------------
 * THE TERM IS THE CYCLE
 * ---------------------------------------------------------------------------
 * `domains.billing_cycle` is an enum of annual/biennial/triennial and there is
 * no column anywhere holding a registration term in years. So the term a
 * customer buys IS the cycle the domain then renews on: one year annual, two
 * biennial, three triennial. That is not a simplification, it is the only thing
 * the row can express - a five-year registration would have to be written as
 * `annual` with an expiry five years out, and every renewal sweep after that
 * would read the pair and disagree with itself.
 *
 * `min_years` and `max_years` are honoured within that: a TLD that sells in
 * two-year blocks offers 2 and 3, and a TLD whose max_years is 10 still offers
 * at most 3, because 3 is what the schema can say. See termsFor().
 *
 * ---------------------------------------------------------------------------
 * ONE TLD, ONE CURRENCY - AND NO CONVERSION
 * ---------------------------------------------------------------------------
 * `tlds` is UNIQUE on `tld` and carries a single `currency_relid`, so a TLD has
 * exactly one price in exactly one currency. A customer checking out in another
 * currency therefore cannot buy it, and priceFor() says so by answering null -
 * which is precisely the rule `Product::price()` already follows, where no row
 * for a currency means not sold in that currency.
 *
 * `Money::convert()` exists and would silently produce a number here. It is not
 * used, deliberately. An exchange rate is an operator decision about what to
 * charge, and `currencies.exchange_rate` defaults to 1 on every install - so
 * converting would quote a 12 USD domain at 12 EUR to anybody the operator had
 * not thought to set a rate for, and would look exactly like a working shop.
 * A price nobody typed is not a price.
 */
class Tld extends Action
{
    /** @var string[] Columns a Form May Write */
    public const FIELDS = [
        'tld', 'registrar_relid', 'currency_relid', 'min_years', 'max_years',
        'register_price', 'renew_price', 'transfer_price', 'restore_price',
        'epp_required', 'id_protection', 'is_active',
    ];

    /**
     * @var array<string,int> Billing Cycle Names, And The Term In Years Each One Means
     *
     * The whole mapping between what a customer buys and what the `domains` row
     * can hold. Ordered shortest first, which is the order the terms are
     * offered in.
     */
    public const TERMS = [
        'annual'    =>  1,
        'biennial'  =>  2,
        'triennial' =>  3,
    ];

    /** @var string[] The Priced Actions */
    public const ACTIONS = ['register', 'renew', 'transfer', 'restore'];

    /** @var int Longest a TLD String May Be - the column is varchar(30) */
    public const MAX_LENGTH = 30;

    public function model(): Model
    {
        return new TldModel();
    }

    protected function searchable(): array
    {
        return ['tld'];
    }

    protected function createdColumn(): ?string
    {
        return 'tld_created_at';
    }

    protected function updatedColumn(): ?string
    {
        return 'tld_updated_at';
    }

    ####################################################################################
    /*================================= EXTERNAL API =================================*/
    ####################################################################################

    /**
     * Every TLD The Operator Has Set Up
     * @param bool $activeOnly Only TLDs Currently Sold
     * @return array
     */
    public function listing(bool $activeOnly = false): array
    {
        $model = $this->model();

        if ($activeOnly) {
            $model->where(['is_active' => 'yes']);
        }

        return $model->order('tld', self::ASC)->get();
    }

    /**
     * The TLDs a Visitor May Order
     * @return array
     */
    public function forSale(): array
    {
        return $this->listing(true);
    }

    /**
     * The TLD One Domain Name Belongs To
     *
     * ONE RULE DOES ALL THE WORK: what is left in front of the TLD has to be
     * a single label. `.com` matches the tail of `www.example.com` too, and a
     * subdomain is not something anybody can register - so that is a miss
     * rather than a sale.
     *
     * That rule is also what settles the overlapping-TLD case, which is why
     * there is no length comparison here. An operator selling both `.uk` and
     * `.co.uk` has two rows whose TLDs overlap, and `example.co.uk` must be
     * priced as a `.co.uk` - but `.uk` cannot claim it either, because it would
     * leave `example.co`, which has a dot in it.
     *
     * That generalises: at most ONE TLD can ever pass the guard. If a longer
     * TLD E2 and a shorter E1 were both suffixes of the same name, the label
     * E1 leaves is the label E2 leaves plus the front of E2 - and E2 begins
     * with a dot, so E1's label always contains one. So the first survivor is
     * the only survivor.
     *
     * A longest-wins tiebreak was written here first. It could never fire, and
     * inverting it to shortest-wins changed no behaviour at all - which is how
     * it was found. Dead code that a comment describes as load-bearing is worse
     * than no code.
     * @param string $domain Domain Name
     * @return ?array The TLD row, or null when nothing sold here matches
     */
    public function match(string $domain): ?array
    {
        $domain = $this->normaliseDomain($domain);

        if ($domain === null) {
            return null;
        }

        foreach ($this->forSale() as $row) {
            $tld = $this->normaliseTld((string) ($row['tld'] ?? ''));

            if ($tld === '' || !str_ends_with($domain, $tld)) {
                continue;
            }

            $label = substr($domain, 0, -strlen($tld));

            // Nothing in front of the TLD, or a dot in it: `.co.uk` and
            // `www.example.com` respectively. Neither is a registrable name,
            // and this is the whole of the matching rule - see the docblock.
            if ($label === '' || str_contains($label, '.')) {
                continue;
            }

            return $row;
        }

        return null;
    }

    /**
     * One TLD By Its Name, Sold Or Not
     *
     * `domains.tld` records the TLD as a STRING rather than a link, so this
     * is how a domain finds its price list. Deliberately not match(), which
     * searches only what is on sale: a domain on a TLD the operator has
     * withdrawn still has to be renewable, and match() would answer null and
     * strand it.
     * @param string $tld TLD, With Or Without Its Dot
     * @return ?array
     */
    public function byName(string $tld): ?array
    {
        $tld = $this->normaliseTld($tld);

        if ($tld === '') {
            return null;
        }

        $row = $this->model()->where(['tld' => $tld])->first();

        return is_array($row) ? $row : null;
    }

    /**
     * Split a Domain Into Its Name And Its TLD
     * @param string $domain Domain Name
     * @return ?array{name:string,tld:string,row:array} Null when nothing sold here matches
     */
    public function split(string $domain): ?array
    {
        $row = $this->match($domain);

        if ($row === null) {
            return null;
        }

        $domain = (string) $this->normaliseDomain($domain);
        $tld = $this->normaliseTld((string) $row['tld']);

        return [
            'name'  =>  substr($domain, 0, -strlen($tld)),
            'tld'   =>  $tld,
            'row'   =>  $row,
        ];
    }

    /**
     * The Terms One TLD May Be Ordered For
     *
     * The operator range, intersected with what a `domains` row is able to
     * record. See the class docblock: three years is not a policy, it is the
     * longest term the billing_cycle enum can express.
     * @param array $tld TLD Row
     * @return int[] Terms in years, shortest first
     */
    public function termsFor(array $tld): array
    {
        $min = max(1, (int) ($tld['min_years'] ?? 1));
        $max = (int) ($tld['max_years'] ?? 1);

        if ($max < $min) {
            $max = $min;
        }

        $terms = [];

        foreach (self::TERMS as $years) {
            if ($years >= $min && $years <= $max) {
                $terms[] = $years;
            }
        }

        return $terms;
    }

    /**
     * What One TLD Costs, For a Term, In One Currency
     *
     * Null rather than zero for every way of not being sellable - the wrong
     * currency, a term outside the range, a withdrawn TLD. Zero is a price
     * an operator may legitimately set (a free first year), so the two answers
     * have to stay distinguishable. Product::price() draws the same line.
     *
     * The figure is the price for the WHOLE term: a two-year registration at 12
     * a year is 24 today, not 12 now and 12 later. There is one invoice.
     * @param array $tld TLD Row
     * @param int $currencyId Currency The Customer Is Checking Out In
     * @param int $years Term
     * @param string $action One Of ACTIONS
     * @return ?string Decimal string, or null when it is not sold like that
     */
    public function priceFor(array $tld, int $currencyId, int $years = 1, string $action = 'register'): ?string
    {
        if (($tld['is_active'] ?? 'yes') !== 'yes') {
            return null;
        }

        // The currency is the operator choice, not the customer one. See the
        // class docblock on why nothing is converted here.
        if ($currencyId <= 0 || (int) ($tld['currency_relid'] ?? 0) !== $currencyId) {
            return null;
        }

        if (!in_array($years, $this->termsFor($tld), true)) {
            return null;
        }

        if (!in_array($action, self::ACTIONS, true)) {
            return null;
        }

        $each = (string) ($tld[$action . '_price'] ?? '0');

        // A restore or a transfer is a single event whatever the term is; only
        // a registration and a renewal buy years.
        $multiply = $action === 'register' || $action === 'renew';

        return Money::round($multiply ? Money::mul((string) $years, $each) : $each);
    }

    /**
     * What Transferring One TLD In Costs
     *
     * ONE YEAR, and no term at all. A transfer adds exactly one year at almost
     * every registry whatever the customer would like, and
     * RegistrarInterface::transfer() takes no term to pass one on with - so
     * offering two would be charging for something the call cannot ask for.
     *
     * That is also why this does not go through priceFor(), which checks the
     * term against the operator's own min_years: a TLD sold in twos would
     * otherwise refuse every transfer of it.
     *
     * The `is_active` gate DOES apply, unlike renewalPrice() below. Withdrawing
     * a TLD means stop taking on new names, and a transfer in IS a new name
     * to this install - it is only renewals that have to go on being honoured
     * for the customers already on one.
     * @param array $tld TLD Row
     * @param int $currencyId Currency ID
     * @return ?string Decimal string, or null when it is not sold
     */
    public function transferPrice(array $tld, int $currencyId): ?string
    {
        if (($tld['is_active'] ?? 'yes') !== 'yes') {
            return null;
        }

        if ($currencyId <= 0 || (int) ($tld['currency_relid'] ?? 0) !== $currencyId) {
            return null;
        }

        return Money::round((string) ($tld['transfer_price'] ?? '0'));
    }

    /**
     * What It Costs To KEEP a Domain Already Registered Here
     *
     * priceFor() with the `is_active` gate deliberately removed, and that is
     * the whole reason it is a separate method rather than a flag.
     *
     * Withdrawing a TLD means "stop selling new ones". It cannot mean
     * "abandon the customers already on it" - they have paid for names that
     * renew, and an operator tidying their price list must not silently strand
     * them. So a renewal reads the price whatever the TLD is set to, while
     * `forSale()` and priceFor() go on refusing new orders.
     *
     * The currency is still enforced. A domain carries the currency it was
     * bought in, and a renewal priced in another one is a figure nobody typed -
     * the same argument the class docblock makes about conversion.
     * @param array $tld TLD Row
     * @param int $currencyId The Currency The Domain Is Billed In
     * @param int $years Term
     * @return ?string Decimal string, or null when it cannot be priced
     */
    public function renewalPrice(array $tld, int $currencyId, int $years = 1): ?string
    {
        if ($currencyId <= 0 || (int) ($tld['currency_relid'] ?? 0) !== $currencyId) {
            return null;
        }

        if ($years < 1 || $this->cycleForYears($years) === null) {
            return null;
        }

        return Money::round(Money::mul((string) $years, (string) ($tld['renew_price'] ?? '0')));
    }

    /**
     * The Billing Cycle a Term Is Recorded As
     * @param int $years Term
     * @return ?string Cycle name, or null when no cycle means that many years
     */
    public function cycleForYears(int $years): ?string
    {
        $name = array_search($years, self::TERMS, true);

        return is_string($name) ? $name : null;
    }

    /**
     * How Many Years One Billing Cycle Means
     * @param string $cycle Cycle Name
     * @return int Years, 0 when the cycle is not a domain term
     */
    public function yearsForCycle(string $cycle): int
    {
        return (int) (self::TERMS[$cycle] ?? 0);
    }

    /**
     * Add A TLD To The Price List
     * @param array $input Submitted Data
     * @return int The new tld id
     * @throws RuntimeException
     */
    public function store(array $input): int
    {
        $data = $this->clean($input);

        if ($this->tldTaken((string) $data['tld'], null)) {
            throw new RuntimeException('That TLD is already on the price list.');
        }

        return $this->create($data);
    }

    /**
     * Change A TLD
     * @param int|string $key TLD ID Or Uid
     * @param array $input Submitted Data
     * @return int Affected rows
     * @throws RuntimeException
     */
    public function modify(int|string $key, array $input): int
    {
        $tld = $this->find($key);

        if ($tld === null) {
            throw new RuntimeException('That TLD is no longer on the price list.');
        }

        $data = $this->clean($input);
        $id = (int) $tld['tld_id'];

        if ($this->tldTaken((string) $data['tld'], $id)) {
            throw new RuntimeException('That TLD is already on the price list.');
        }

        return $this->update($id, $data);
    }

    /**
     * Take A TLD Off The Price List
     *
     * Refused while domains are registered on it. `domains` records its TLD
     * as a STRING rather than a link, so deleting the row orphans nothing a
     * foreign key would notice - it quietly removes the only place a renewal
     * price for those domains could ever be read from, and the first sign is a
     * renewal invoice that never gets raised.
     * @param int|string $key TLD ID Or Uid
     * @return int Affected rows
     * @throws RuntimeException
     */
    public function remove(int|string $key): int
    {
        $tld = $this->find($key);

        if ($tld === null) {
            return 0;
        }

        $name = $this->normaliseTld((string) $tld['tld']);

        if ((new DomainModel())->where(['tld' => $name])->count() > 0) {
            throw new RuntimeException(
                'Domains are registered on that TLD. Stop selling it instead of deleting it.'
            );
        }

        return $this->delete((int) $tld['tld_id']);
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * The Priced Actions
     *
     * A method rather than the constant, because a relay facade forwards method
     * calls and not constants.
     * @return string[]
     */
    public function actions(): array
    {
        return self::ACTIONS;
    }

    /**
     * The Term-To-Cycle Mapping
     *
     * A method rather than the constant, because a relay facade forwards method
     * calls and not constants.
     * @return array<string,int>
     */
    public function terms(): array
    {
        return self::TERMS;
    }

    /**
     * Put a Submitted TLD Into The One Shape Everything Else Assumes
     *
     * Lowercase, exactly one leading dot. An operator types `.com`, `com` or
     * `COM` and means the same thing; storing all three would make match() find
     * whichever was entered first and price the others at nothing.
     * @param string $tld Submitted TLD
     * @return string
     */
    public function normaliseTld(string $tld): string
    {
        $tld = strtolower(trim($tld));
        $tld = ltrim($tld, '.');

        if ($tld === '') {
            return '';
        }

        return '.' . $tld;
    }

    /**
     * Reduce a Submitted Domain To a Comparable Name
     *
     * The cart already does this for the domain a product is ordered against;
     * this is the same rule for the domain BEING ordered, kept here because the
     * catalogue has to answer a search box before there is a cart at all.
     * @param string $domain Submitted Domain
     * @return ?string Null when it is not shaped like a hostname
     */
    public function normaliseDomain(string $domain): ?string
    {
        $domain = strtolower(trim($domain));

        if ($domain === '') {
            return null;
        }

        $domain = (string) preg_replace('#^[a-z]+://#', '', $domain);
        $domain = explode('/', $domain)[0];
        $domain = trim($domain, " \t\n\r\0\x0B.");

        if ($domain === '' || strlen($domain) > 190) {
            return null;
        }

        if (!preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)+$/', $domain)) {
            return null;
        }

        return $domain;
    }

    /**
     * Reduce Submitted Input To Writable Columns
     * @param array $input Submitted Data
     * @return array
     * @throws RuntimeException
     */
    private function clean(array $input): array
    {
        $data = $this->only($input, self::FIELDS);

        $tld = $this->normaliseTld((string) ($data['tld'] ?? ''));

        if ($tld === '') {
            throw new RuntimeException('A TLD needs a name, such as .com.');
        }

        if (strlen($tld) > self::MAX_LENGTH) {
            throw new RuntimeException('That TLD is too long.');
        }

        if (!preg_match('/^\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)*$/', $tld)) {
            throw new RuntimeException('That does not look like a TLD.');
        }

        if ((int) ($data['registrar_relid'] ?? 0) <= 0) {
            throw new RuntimeException('A TLD needs a registrar.');
        }

        if ((int) ($data['currency_relid'] ?? 0) <= 0) {
            throw new RuntimeException('A TLD needs a currency to be priced in.');
        }

        $min = max(1, (int) ($data['min_years'] ?? 1));
        $max = max($min, (int) ($data['max_years'] ?? $min));

        $data['tld']             = $tld;
        $data['registrar_relid'] = (int) $data['registrar_relid'];
        $data['currency_relid']  = (int) $data['currency_relid'];
        $data['min_years']       = $min;
        $data['max_years']       = $max;

        foreach (self::ACTIONS as $action) {
            $column = $action . '_price';
            $data[$column] = Money::round((string) ($data[$column] ?? '0'));
        }

        foreach (['epp_required', 'id_protection', 'is_active'] as $flag) {
            $data[$flag] = $this->flag($data[$flag] ?? 'yes');
        }

        // Said out loud rather than left for the operator to discover from an
        // empty term dropdown on the order page.
        if ($this->termsFor($data) === []) {
            throw new RuntimeException(
                'No term between ' . $min . ' and ' . $max . ' years can be recorded. '
                . 'A domain renews annually, biennially or triennially, so the range has to include 1, 2 or 3.'
            );
        }

        return $data;
    }

    /**
     * Whether Another Row Already Holds That TLD
     *
     * `tld` is UNIQUE, so this is the difference between a message the operator
     * can act on and a driver exception on the form.
     * @param string $tld Normalised TLD
     * @param ?int $ignore TLD ID To Skip
     * @return bool
     */
    private function tldTaken(string $tld, ?int $ignore): bool
    {
        $row = (new TldModel())->where(['tld' => $tld])->first();

        if (!is_array($row)) {
            return false;
        }

        return $ignore === null || (int) $row['tld_id'] !== $ignore;
    }
}
