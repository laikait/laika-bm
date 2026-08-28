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

use RuntimeException;
use Laika\Model\Model;
use LBM\Model\ClientModel;
use LBM\Model\CurrencyModel;
use LBM\Model\InvoiceModel;
use LBM\Service\Money;

/**
 * Currencies and their exchange rates (instruction 8).
 *
 * Rates are held against one base currency - the row flagged `is_default` -
 * rather than pairwise, so adding a currency needs one rate rather than one per
 * existing currency. Conversion between two non-base currencies goes through
 * the base, which is what LBM\Support\Money::rate() does.
 *
 * The default currency's own rate is forced to 1. It is the unit everything
 * else is measured in, and a base rate of anything else silently rescales the
 * entire price list.
 *
 * Every write here flushes Money's cache. Without that, a rate edited in
 * Settings keeps applying at its old value for the rest of the request - and
 * the screen that shows the result of the edit is in that same request.
 */
class Currency extends Action
{
    /** @var string[] Columns a Form May Write */
    public const FIELDS = [
        'currency_code', 'prefix_symbol', 'suffix_symbol', 'exchange_rate', 'is_active',
    ];

    public function model(): Model
    {
        return new CurrencyModel();
    }

    protected function searchable(): array
    {
        return ['currency_code'];
    }

    protected function createdColumn(): ?string
    {
        return 'currency_created_at';
    }

    protected function updatedColumn(): ?string
    {
        return 'currency_updated_at';
    }

    ####################################################################################
    /*================================= EXTERNAL API =================================*/
    ####################################################################################

    /**
     * Every Currency
     * @param bool $activeOnly Only Currencies Currently Offered
     * @return array
     */
    public function listing(bool $activeOnly = false): array
    {
        $model = $this->model();

        if ($activeOnly) {
            $model->where(['is_active' => 'yes']);
        }

        return $model->order('currency_code', self::ASC)->get();
    }

    /**
     * Find a Currency By Its ISO Code
     * @param string $code ISO 4217 Code
     * @return ?array
     */
    public function findByCode(string $code): ?array
    {
        $row = $this->model()->where(['currency_code' => strtoupper(trim($code))])->first();

        return is_array($row) ? $row : null;
    }

    /**
     * The Default Currency
     * @return ?array
     */
    public function default(): ?array
    {
        $row = $this->model()->where(['is_default' => 'yes'])->first();

        return is_array($row) ? $row : null;
    }

    /**
     * Create Or Update a Currency
     *
     * One method because the currencies screen is a single form: a row per
     * currency plus a blank one, saved together.
     * @param array $input Submitted Data
     * @param int|string|null $key Currency ID Or Uid. Null creates
     * @return int The currency ID
     * @throws RuntimeException
     */
    public function save(array $input, int|string|null $key = null): int
    {
        $data = $this->fields($input);
        $code = (string) ($data['currency_code'] ?? '');

        if ($code === '') {
            throw new RuntimeException('A currency needs an ISO code.');
        }

        $existing = $key !== null && $key !== '' && $key !== 0 ? $this->find($key) : null;
        $ignore = $existing !== null ? (int) $existing['currency_id'] : null;

        if ($this->codeTaken($code, $ignore)) {
            throw new RuntimeException("The currency {$code} already exists.");
        }

        $id = 0;

        if ($existing !== null) {
            $id = (int) $existing['currency_id'];

            // The base currency is the unit; its rate is 1 by definition and an
            // edit that changed it would rescale every other currency at once.
            if (($existing['is_default'] ?? 'no') === 'yes') {
                $data['exchange_rate'] = '1';
            }

            $this->update($id, $data);
        } else {
            $data['exchange_rate'] = $data['exchange_rate'] ?? '1';
            $data['is_default'] = 'no';

            $id = $this->create($data);
        }

        Money::flush();

        return $id;
    }

    /**
     * Make a Currency The Default
     *
     * Demotes the previous default and pins the new one's rate to 1, in one
     * transaction. Two defaults, or none, would leave Money with no base to
     * convert through.
     *
     * Existing amounts are not rescaled: an invoice raised in USD stays an
     * invoice for that many dollars whatever the base becomes.
     * @param int|string $key Currency ID Or Uid
     * @return int The currency ID
     * @throws RuntimeException
     */
    public function makeDefault(int|string $key): int
    {
        $currency = $this->find($key);

        if ($currency === null) {
            throw new RuntimeException('That currency no longer exists.');
        }

        $id = (int) $currency['currency_id'];

        $this->model()->transaction(function (CurrencyModel $m) use ($id): void {
            $m->whereNot([$m->id => $id])
                ->update(['is_default' => 'no', 'currency_updated_at' => $this->now()]);

            $m->where([$m->id => $id])->update([
                'is_default'          =>  'yes',
                'is_active'           =>  'yes',
                'exchange_rate'       =>  '1',
                'currency_updated_at' =>  $this->now(),
            ]);
        });

        // The option is what the rest of the app reads when nothing names a
        // currency explicitly, so the two have to move together.
        (new Setting())->put('default_currency', (string) $currency['currency_code']);

        Money::flush();

        return $id;
    }

    /**
     * Update Only a Currency's Exchange Rate
     * @param int|string $key Currency ID Or Uid
     * @param int|float|string $rate Exchange Rate Against The Base
     * @return int Affected rows
     * @throws RuntimeException
     */
    public function setRate(int|string $key, int|float|string $rate): int
    {
        $currency = $this->find($key);

        if ($currency === null) {
            return 0;
        }

        if (($currency['is_default'] ?? 'no') === 'yes') {
            throw new RuntimeException('The default currency is the base rate and is always 1.');
        }

        if (!Money::isGreater((string) $rate, '0')) {
            throw new RuntimeException('An exchange rate must be greater than zero.');
        }

        $affected = $this->update((int) $currency['currency_id'], ['exchange_rate' => (string) $rate]);

        Money::flush();

        return $affected;
    }

    /**
     * Delete a Currency
     *
     * Refuses for the default, and for any currency still attached to a client
     * or an invoice - an invoice whose currency_relid points at nothing has no
     * meaningful total.
     * @param int|string $key Currency ID Or Uid
     * @return int Affected rows
     * @throws RuntimeException
     */
    public function remove(int|string $key): int
    {
        $currency = $this->find($key);

        if ($currency === null) {
            return 0;
        }

        if (($currency['is_default'] ?? 'no') === 'yes') {
            throw new RuntimeException('The default currency cannot be deleted. Make another one default first.');
        }

        $id = (int) $currency['currency_id'];

        $invoices = (new InvoiceModel())->where(['currency_relid' => $id])->count();
        $clients = (new ClientModel())->where(['currency_relid' => $id])->count();

        if ($invoices > 0 || $clients > 0) {
            throw new RuntimeException(
                "This currency is used by {$invoices} invoice(s) and {$clients} client(s). "
                . 'Deactivate it instead of deleting it.'
            );
        }

        $affected = $this->delete($id);

        Money::flush();

        return $affected;
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Whether An ISO Code Is Already Registered
     * @param string $code ISO Code
     * @param ?int $ignore Currency ID To Exclude, When Editing
     * @return bool
     */
    private function codeTaken(string $code, ?int $ignore = null): bool
    {
        $model = $this->model();
        $model->where(['currency_code' => strtoupper(trim($code))]);

        if ($ignore !== null) {
            $model->whereNot([$model->id => $ignore]);
        }

        return $model->count() > 0;
    }

    /**
     * Reduce Submitted Input To Writable Columns
     * @param array $input Submitted Data
     * @return array
     */
    private function fields(array $input): array
    {
        $data = $this->only($input, self::FIELDS);

        if (array_key_exists('currency_code', $data)) {
            $data['currency_code'] = strtoupper((string) $data['currency_code']);
        }

        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = $this->flag($data['is_active']);
        }

        // The symbol columns are NOT NULL, so a currency with no suffix stores
        // an empty string rather than a null.
        foreach (['prefix_symbol', 'suffix_symbol'] as $symbol) {
            if (array_key_exists($symbol, $data) && $data[$symbol] === null) {
                $data[$symbol] = '';
            }
        }

        return $data;
    }
}
