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

namespace LBM\Support;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Laika\Service\Math;
use LBM\Model\CurrencyModel;

/**
 * Multi-currency amounts (instruction 8).
 *
 * Every arithmetic step goes through Laika\Service\Math, which is bcmath over
 * decimal strings. Money never becomes a float here: 0.1 + 0.2 is not 0.3 in
 * binary floating point, and on an invoice that surfaces as a total a cent off
 * that nobody can account for. The models already cast the money columns to
 * `decimal`, so amounts arrive as strings and stay strings until they are
 * formatted for display.
 *
 * Rates are stored per currency against a single base - the currency flagged
 * `is_default` - so converting between two non-base currencies goes through the
 * base rather than needing a rate for every pair.
 */
class Money
{
    /** @var int Scale For Intermediate Arithmetic */
    public const SCALE = 6;

    /** @var int Scale For Displayed Amounts */
    public const DISPLAY_SCALE = 2;

    /** @var array<int,array>|null Every currency, keyed by id */
    private ?array $currencies = null;

    /** @var array|null The default currency row */
    private ?array $default = null;

    /**
     * Every Active Currency, Keyed By Id
     * @return array<int,array>
     */
    public function all(): array
    {
        if ($this->currencies !== null) {
            return $this->currencies;
        }

        $model = new CurrencyModel();
        $id = $model->id;
        $rows = [];

        foreach ($model->where(['is_active' => 'yes'])->order('currency_code', 'ASC')->get() as $row) {
            $rows[(int) $row[$id]] = $row;
        }

        return $this->currencies = $rows;
    }

    /**
     * Get One Currency
     * @param int|string|null $currency Currency Id, or an ISO code like 'USD'
     * @return ?array
     */
    public function get(int|string|null $currency = null): ?array
    {
        if ($currency === null || $currency === '') {
            return $this->default();
        }

        if (is_numeric($currency)) {
            return $this->all()[(int) $currency] ?? null;
        }

        foreach ($this->all() as $row) {
            if (strcasecmp((string) $row['currency_code'], (string) $currency) === 0) {
                return $row;
            }
        }

        return null;
    }

    /**
     * The Default Currency
     *
     * Every exchange rate is quoted against this one.
     * @return ?array
     */
    public function default(): ?array
    {
        if ($this->default !== null) {
            return $this->default;
        }

        $all = $this->all();

        foreach ($all as $row) {
            if (($row['is_default'] ?? 'no') === 'yes') {
                return $this->default = $row;
            }
        }

        // Nothing is flagged. The default_currency option names the ISO code
        // the installer chose and Currency::makeDefault() keeps it in step, so
        // it is the next best answer.
        $code = strtoupper((string) (option('default_currency', '') ?: ''));

        if ($code !== '') {
            foreach ($all as $row) {
                if (strtoupper((string) ($row['currency_code'] ?? '')) === $code) {
                    return $this->default = $row;
                }
            }
        }

        // Neither - fall back to the first active one so a half-configured
        // install still renders totals instead of fatalling.
        $first = reset($all);

        return $this->default = ($first ?: null);
    }

    /**
     * The Exchange Rate Between Two Currencies
     *
     * Both rates are quoted against the default currency, so the pair rate is
     * `to / from`.
     * @param int|string|null $from Source Currency
     * @param int|string|null $to Target Currency
     * @return string Decimal string, '1' when either side is unknown
     */
    public function rate(int|string|null $from, int|string|null $to): string
    {
        $fromRate = $this->rateOf($from);
        $toRate   = $this->rateOf($to);

        if (Math::isZero($fromRate)) {
            return '1';
        }

        return Math::div($toRate, $fromRate, self::SCALE);
    }

    /**
     * Convert an Amount Between Currencies
     * @param int|float|string $amount Amount In The Source Currency
     * @param int|string|null $from Source Currency
     * @param int|string|null $to Target Currency
     * @return string Decimal string
     */
    public function convert(int|float|string $amount, int|string|null $from, int|string|null $to): string
    {
        $amount = $this->normalize($amount);

        if ($from === null || $to === null || (string) $from === (string) $to) {
            return $amount;
        }

        return Math::mul($amount, $this->rate($from, $to), self::SCALE);
    }

    /**
     * Format an Amount For Display
     *
     * Applies the currency's prefix and suffix symbols and the operator's
     * decimal and thousand separators.
     * @param int|float|string $amount Amount
     * @param int|string|null $currency Currency Id or ISO code. Null uses the default
     * @return string
     */
    public function format(int|float|string $amount, int|string|null $currency = null): string
    {
        $row = $this->get($currency);
        $amount = Math::round($this->normalize($amount), self::DISPLAY_SCALE);

        $formatted = number_format(
            (float) $amount,
            self::DISPLAY_SCALE,
            option('decimal_symbol', '.') ?: '.',
            option('thousand_separator', ',') ?: ','
        );

        $prefix = (string) ($row['prefix_symbol'] ?? '');
        $suffix = (string) ($row['suffix_symbol'] ?? '');

        return $prefix . $formatted . $suffix;
    }

    ####################################################################################
    /*================================== ARITHMETIC ==================================*/
    ####################################################################################
    //
    // Thin wrappers over Math, fixed at this class's scale. They exist so an
    // invoice total is added up the same way everywhere, and so no action has to
    // remember to pass SCALE - a subtotal summed at bcmath's default scale of 0
    // would silently truncate every fractional amount.

    /**
     * Add Two Amounts
     * @param int|float|string $a First Amount
     * @param int|float|string $b Second Amount
     * @return string
     */
    public function add(int|float|string $a, int|float|string $b): string
    {
        return Math::add($this->normalize($a), $this->normalize($b), self::SCALE);
    }

    /**
     * Subtract The Second Amount From The First
     * @param int|float|string $a First Amount
     * @param int|float|string $b Second Amount
     * @return string
     */
    public function sub(int|float|string $a, int|float|string $b): string
    {
        return Math::sub($this->normalize($a), $this->normalize($b), self::SCALE);
    }

    /**
     * Multiply Two Amounts
     * @param int|float|string $a First Amount
     * @param int|float|string $b Second Amount
     * @return string
     */
    public function mul(int|float|string $a, int|float|string $b): string
    {
        return Math::mul($this->normalize($a), $this->normalize($b), self::SCALE);
    }

    /**
     * Divide The First Amount By The Second
     *
     * Dividing by zero returns '0' rather than throwing. Every caller here is
     * working out a ratio for display - "revenue is up N% on last month" - and
     * an empty baseline means there is no percentage to show, not that the page
     * should fail.
     * @param int|float|string $a Dividend
     * @param int|float|string $b Divisor
     * @return string
     */
    public function div(int|float|string $a, int|float|string $b): string
    {
        $b = $this->normalize($b);

        if (Math::isZero($b)) {
            return '0';
        }

        return Math::div($this->normalize($a), $b, self::SCALE);
    }

    /**
     * Add Up a List Of Amounts
     * @param array $amounts Amounts
     * @return string
     */
    public function sum(array $amounts): string
    {
        $total = '0';

        foreach ($amounts as $amount) {
            $total = $this->add($total, $amount);
        }

        return $total;
    }

    /**
     * A Percentage Of An Amount
     *
     * Tax and discount rates are stored as percentages, not multipliers.
     * @param int|float|string $amount Amount
     * @param int|float|string $percent Percentage. 7.5 means 7.5%
     * @return string
     */
    public function percent(int|float|string $amount, int|float|string $percent): string
    {
        return Math::percentOf($this->normalize($percent), $this->normalize($amount), self::SCALE);
    }

    /**
     * Compare Two Amounts
     * @param int|float|string $a First Amount
     * @param int|float|string $b Second Amount
     * @return int -1, 0 or 1
     */
    public function compare(int|float|string $a, int|float|string $b): int
    {
        return Math::compare($this->normalize($a), $this->normalize($b), self::SCALE);
    }

    /**
     * Whether An Amount Is Zero
     * @param int|float|string $amount Amount
     * @return bool
     */
    public function isZero(int|float|string $amount): bool
    {
        return Math::isZero($this->normalize($amount));
    }

    /**
     * Whether The First Amount Is Greater Than The Second
     * @param int|float|string $a First Amount
     * @param int|float|string $b Second Amount
     * @return bool
     */
    public function isGreater(int|float|string $a, int|float|string $b): bool
    {
        return $this->compare($a, $b) > 0;
    }

    /**
     * Round An Amount To The Display Scale
     *
     * What gets written to a money column at the end of a calculation: the
     * intermediate steps run at SCALE so nothing is lost on the way, and this
     * is the single place that decides where the result is cut off.
     * @param int|float|string $amount Amount
     * @return string
     */
    public function round(int|float|string $amount): string
    {
        return Math::round($this->normalize($amount), self::DISPLAY_SCALE);
    }

    /**
     * The Larger Of Two Amounts
     * @param int|float|string $a First Amount
     * @param int|float|string $b Second Amount
     * @return string
     */
    public function max(int|float|string $a, int|float|string $b): string
    {
        return $this->compare($a, $b) >= 0 ? $this->normalize($a) : $this->normalize($b);
    }

    /**
     * Forget Cached Currencies
     *
     * The Settings screen edits rates; without this the old rate keeps applying
     * for the rest of the request.
     * @return void
     */
    public function flush(): void
    {
        $this->currencies = null;
        $this->default = null;
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * The Stored Rate For a Currency
     * @param int|string|null $currency Currency Id or ISO code
     * @return string
     */
    private function rateOf(int|string|null $currency): string
    {
        $row = $this->get($currency);

        return $this->normalize($row['exchange_rate'] ?? '1');
    }

    /**
     * Coerce Any Amount To a Decimal String
     *
     * A float argument is cast with a fixed scale rather than string-cast,
     * because (string) 0.1 + 0.2 would carry the binary representation error
     * straight into bcmath.
     * @param int|float|string $amount Amount
     * @return string
     */
    private function normalize(int|float|string $amount): string
    {
        if (is_float($amount)) {
            return number_format($amount, self::SCALE, '.', '');
        }

        $amount = trim((string) $amount);

        return $amount === '' ? '0' : $amount;
    }
}
