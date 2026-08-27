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

        // No currency is flagged default - fall back to the first active one so
        // a half-configured install still renders totals instead of fatalling.
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
