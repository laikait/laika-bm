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
use LBM\Model\ClientModel;
use LBM\Model\ProductModel;
use LBM\Model\TaxRuleModel;
use LBM\Service\Money;
use RuntimeException;

/**
 * Tax rules, and the rate that comes out of them.
 *
 * Everything under this class exists to answer one question - *what percentage
 * goes on this line, for this customer* - and to answer it ONCE, at the moment
 * an invoice is raised. The answer is written onto `invoice_items.tax` and is
 * never asked again: an invoice is a statement of what was charged on a day,
 * and re-deriving its rate from today's rules would silently reprice a document
 * somebody has already paid, filed and possibly reclaimed against.
 *
 * The amount in money IS recomputed, by `Invoice::recalculate()`, from that
 * stored rate. That is a different thing and it is correct - editing a line
 * genuinely changes what tax is due on it. The rule is: the rate is history,
 * the money is arithmetic.
 *
 * WHICH RULES APPLY - most specific wins
 *
 * A client's country is matched first. If any rule names it, only those rules
 * are in play, and within them a rule naming the client's state beats the ones
 * that name no state. If NO rule names the country, the rules with no country
 * on them apply instead, as a fallback for everywhere else.
 *
 * The schema comments `country_relid` as "NULL = all countries" and this is a
 * deliberate narrowing of that. Read literally, an operator with a 20% rule for
 * their own country and a 20% catch-all would charge their own customers 40%,
 * and they would find out from a customer rather than from us. A fallback that
 * stops applying once a country has its own answer is the reading that cannot
 * double-charge anybody.
 *
 * Rules stack WITHIN the winning set, which is how a country with more than one
 * tax works. `is_compound` says a rule is charged on the price plus the taxes
 * already on it rather than on the price alone - GST at 5% with a compound PST
 * at 8% is 13.4%, not 13%.
 *
 * WHAT OVERRIDES WHAT
 *
 *   1. A client marked `tax_exempt` pays no tax on anything. Nothing overrides
 *      this, because the operator has a certificate on file saying so.
 *   2. A product with its own `tax_rate` uses it. The column has existed since
 *      Phase 0 and was read by nothing; a non-zero value is the operator saying
 *      "this one is different", which is the only thing it could reasonably
 *      mean on a column that holds a rate rather than a class.
 *   3. Otherwise, the rules above.
 *
 * INCLUSIVE PRICING
 *
 * `prices_include_tax` says a catalogue price is what the customer pays, tax
 * and all - which is not a preference in much of the world, it is what consumer
 * pricing law requires. It changes nothing about how an invoice adds up: the
 * gross is divided back down to a net `unit_price` before the line is written,
 * so `subtotal` stays net, tax is added on top as always, and the total comes
 * back to the number on the catalogue page. Off by default, because that is
 * what every install has been doing since Phase 0.
 */
class Tax extends Action
{
    /** @var string[] Columns a Form May Write */
    public const FIELDS = [
        'rule_name', 'rate', 'country_relid', 'state', 'is_compound', 'is_active',
    ];

    /**
     * @var string The Highest Rate The Column Can Hold
     *
     * `rate` is decimal(7,4). A compound stack cannot realistically reach this,
     * but a rate that overflows its column is a database error in the middle of
     * raising an invoice, and there is no good place for one of those.
     */
    public const MAX_RATE = '999.9999';

    /**
     * @var ?array Active Rules, Read Once Per Request
     *
     * A fifty line invoice would otherwise ask the same question fifty times.
     * Same memoisation as option(), and the same consequence: a save cannot be
     * read back in the process that made it, which is why every settings save
     * in this product ends in a redirect.
     */
    private static ?array $rules = null;

    public function model(): Model
    {
        return new TaxRuleModel();
    }

    protected function searchable(): array
    {
        return ['rule_name'];
    }

    protected function createdColumn(): ?string
    {
        return 'rule_created_at';
    }

    /**
     * `tax_rules` has no updated column, so there is nothing to stamp.
     * @return ?string
     */
    protected function updatedColumn(): ?string
    {
        return null;
    }

    ####################################################################################
    /*================================= EXTERNAL API =================================*/
    ####################################################################################

    /**
     * Every Tax Rule, Newest Last
     * @param bool $activeOnly Only Rules Currently In Force
     * @return array
     */
    public function listing(bool $activeOnly = false): array
    {
        $model = $this->model();

        if ($activeOnly) {
            $model->where(['is_active' => 'yes']);
        }

        return $model->order('country_relid', self::ASC)->get();
    }

    /**
     * Whether This Install Charges Tax At All
     *
     * Used to keep tax off screens that would otherwise show a row of zeroes.
     * A product carrying its own rate counts, because that is a way of charging
     * tax without writing a single rule.
     * @return bool
     */
    public function configured(): bool
    {
        if ($this->activeRules() !== []) {
            return true;
        }

        return (new ProductModel())->where(['tax_rate' => 0], '>')->count() > 0;
    }

    /**
     * Whether Catalogue Prices Are Tax Inclusive
     * @return bool
     */
    public function inclusive(): bool
    {
        // ONE argument. option_bool() is preg_match('/^true$/i', option($key,
        // 'false')) in laika-core - a second argument meant as a default is
        // accepted by PHP, ignored, and reads ever afterwards like a setting
        // somebody could change. Phase 23 lost an afternoon to that.
        return option_bool('prices_include_tax');
    }

    /**
     * Whether a Client Pays No Tax
     * @param ?array $client Client Row
     * @return bool
     */
    public function exempt(?array $client): bool
    {
        return ($client['tax_exempt'] ?? 'no') === 'yes';
    }

    /**
     * The Rules That Apply To One Client
     *
     * Most specific wins - see the class docblock. Public rather than private
     * because "which of my rules is this customer actually getting" is the
     * first question anybody asks of a tax table, and answering it needs the
     * set rather than the single number rateFor() reduces it to.
     * @param ?array $client Client Row
     * @return array
     */
    public function rulesFor(?array $client): array
    {
        $active = $this->activeRules();

        if ($active === []) {
            return [];
        }

        $country = (int) ($client['country_relid'] ?? 0);
        $state   = $this->normalise((string) ($client['state'] ?? ''));

        $inCountry = $country > 0
            ? array_values(array_filter(
                $active,
                static fn(array $rule): bool => (int) ($rule['country_relid'] ?? 0) === $country
            ))
            : [];

        // Nothing names this country, so the rules that name no country are the
        // answer. This is the only place they are ever reached.
        if ($inCountry === []) {
            return array_values(array_filter(
                $active,
                static fn(array $rule): bool => (int) ($rule['country_relid'] ?? 0) === 0
            ));
        }

        $inState = $state === '' ? [] : array_values(array_filter(
            $inCountry,
            fn(array $rule): bool => $this->normalise((string) ($rule['state'] ?? '')) === $state
        ));

        if ($inState !== []) {
            return $inState;
        }

        // A country whose rules all name states the client is not in charges
        // this client nothing, and that is right: an operator with rules for
        // two US states means those two states.
        return array_values(array_filter(
            $inCountry,
            fn(array $rule): bool => $this->normalise((string) ($rule['state'] ?? '')) === ''
        ));
    }

    /**
     * The Rate For One Line, As a Percentage
     * @param ?array $client Client Row
     * @param ?array $product Product Row, When The Line Is For One
     * @return string Decimal string. '0' when nothing applies
     */
    public function rateFor(?array $client, ?array $product = null): string
    {
        if ($this->exempt($client)) {
            return '0';
        }

        $own = (string) ($product['tax_rate'] ?? '0');

        if (!Money::isZero($own)) {
            return $this->cap($own);
        }

        return $this->cap($this->fromRules($client));
    }

    /**
     * The Rate For One Line, Given Ids Rather Than Rows
     *
     * For callers holding a service or an order line rather than the rows
     * behind it. Prefer rateFor() in a loop - this reads the client back on
     * every call, and an invoice has more than one line.
     * @param ?int $clientId Client ID
     * @param ?int $productId Product ID
     * @return string Decimal string
     */
    public function rateForClient(?int $clientId, ?int $productId = null): string
    {
        $client = $clientId > 0
            ? (new ClientModel())->where(['cid' => $clientId])->first()
            : null;

        $product = $productId > 0
            ? (new ProductModel())->where(['pid' => $productId])->first()
            : null;

        return $this->rateFor(
            is_array($client) ? $client : null,
            is_array($product) ? $product : null
        );
    }

    /**
     * The Tax On a Net Amount
     * @param string $net Amount Before Tax
     * @param string $rate Percentage
     * @return string Decimal string
     */
    public function amountOn(string $net, string $rate): string
    {
        if (Money::isZero($rate) || Money::isZero($net)) {
            return '0';
        }

        return Money::percent($net, $rate);
    }

    /**
     * The Net Hiding Inside a Tax Inclusive Price
     *
     * A gross of 120 at 20% is a net of 100, not 96 - the tax is a fifth of the
     * net, not a fifth of the gross, and getting that backwards understates
     * every price by the tax on the tax.
     * @param string $gross Amount Including Tax
     * @param string $rate Percentage
     * @return string Decimal string
     */
    public function netOf(string $gross, string $rate): string
    {
        if (Money::isZero($rate) || Money::isZero($gross)) {
            return $gross;
        }

        return Money::div(Money::mul($gross, '100'), Money::add('100', $rate));
    }

    /**
     * Create Or Update a Tax Rule
     *
     * One method because the rules screen is a single form: a row per rule and
     * a blank one at the bottom, the same shape as currencies.
     * @param array $input Submitted Data
     * @param int|string|null $key Rule ID Or Uid. Null creates
     * @return int The rule ID
     * @throws RuntimeException
     */
    public function save(array $input, int|string|null $key = null): int
    {
        $data = $this->only($input, self::FIELDS);

        $name = trim((string) ($data['rule_name'] ?? ''));

        if ($name === '') {
            throw new RuntimeException('A tax rule needs a name.');
        }

        $data['rule_name']   = $name;
        $data['rate']        = $this->cap((string) ($data['rate'] ?? '0'));
        $data['is_compound'] = $this->flag($data['is_compound'] ?? 'no');
        $data['is_active']   = $this->flag($data['is_active'] ?? 'yes');

        // Blank means "everywhere" and "every state", and blank has to reach the
        // database as NULL for it to mean that - an empty string is a state
        // called nothing, which matches no client.
        $country = (int) ($data['country_relid'] ?? 0);
        $data['country_relid'] = $country > 0 ? $country : null;

        $state = trim((string) ($data['state'] ?? ''));
        $data['state'] = $state === '' ? null : $state;

        if ($state !== '' && $data['country_relid'] === null) {
            throw new RuntimeException('A rule for a state needs the country it is in.');
        }

        $existing = $key !== null && $key !== '' && $key !== 0 ? $this->find($key) : null;

        $id = $existing !== null
            ? (int) $existing['tr_id']
            : 0;

        if ($existing !== null) {
            $this->update($id, $data);
        } else {
            $id = $this->create($data);
        }

        self::flush();

        return $id;
    }

    /**
     * Delete a Tax Rule
     *
     * Nothing points at a rule once an invoice is raised - the rate is copied
     * onto the line, not referenced - so deleting one changes no existing
     * document. It only stops the rule applying to what comes next.
     * @param int|string $key Rule ID Or Uid
     * @return int Affected rows
     */
    public function remove(int|string $key): int
    {
        $rule = $this->find($key);

        if ($rule === null) {
            return 0;
        }

        $affected = $this->delete((int) $rule['tr_id']);

        self::flush();

        return $affected;
    }

    /**
     * Forget The Memoised Rules
     *
     * For the harnesses, and for any caller that writes a rule and then needs
     * to act on it in the same process.
     * @return void
     */
    public static function flush(): void
    {
        self::$rules = null;
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Every Rule Currently In Force
     * @return array
     */
    private function activeRules(): array
    {
        if (self::$rules === null) {
            self::$rules = $this->model()->where(['is_active' => 'yes'])->get();
        }

        return self::$rules;
    }

    /**
     * Combine The Applicable Rules Into One Percentage
     *
     * Compound rules are charged on the price plus the non-compound tax already
     * on it, which is why they are held back and applied to (100 + base) rather
     * than added in with the rest.
     * @param ?array $client Client Row
     * @return string Decimal string
     */
    private function fromRules(?array $client): string
    {
        $base     = '0';
        $compound = [];

        foreach ($this->rulesFor($client) as $rule) {
            $rate = (string) ($rule['rate'] ?? '0');

            if (Money::isZero($rate)) {
                continue;
            }

            if (($rule['is_compound'] ?? 'no') === 'yes') {
                $compound[] = $rate;
                continue;
            }

            $base = Money::add($base, $rate);
        }

        $effective = $base;

        foreach ($compound as $rate) {
            $effective = Money::add($effective, Money::percent(Money::add('100', $base), $rate));
        }

        return $effective;
    }

    /**
     * Keep a Rate Inside Its Column, And Never Negative
     * @param string $rate Percentage
     * @return string Decimal string
     */
    private function cap(string $rate): string
    {
        if (!Money::isGreater($rate, '0')) {
            return '0';
        }

        return Money::isGreater($rate, self::MAX_RATE) ? self::MAX_RATE : $rate;
    }

    /**
     * Fold a State Name For Comparison
     *
     * Operators type "california", customers type "California", and neither of
     * them should decide whether tax is charged.
     * @param string $value State Name
     * @return string
     */
    private function normalise(string $value): string
    {
        return strtolower(trim($value));
    }
}
