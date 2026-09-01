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

namespace LBM\Controller\Admin;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Laika\Service\Request;
use LBM\Service\Currency;

/**
 * Currencies and their exchange rates.
 *
 * One screen, one table: every rate is quoted against the default currency, so
 * seeing them together is the only way to tell whether they make sense. The
 * default's own rate is fixed at 1 and cannot be edited - it is the unit
 * everything else is measured in.
 */
class CurrencyController extends AdminController
{
    protected function nav(): string
    {
        return 'currencies';
    }

    /**
     * The Currency List
     * @return string
     */
    public function index(): string
    {
        return $this->screen('currencies', 'Currencies', [
            'currencies' =>  Currency::listing(),
            'default'    =>  Currency::default(),
        ]);
    }

    /**
     * Add Or Update a Currency
     *
     * One POST for both: the screen is a single form with a row per currency
     * and a blank one at the bottom.
     * @return ?string
     */
    public function save(): ?string
    {
        $input = Request::inputs();
        $code = strtoupper(trim((string) ($input['currency_code'] ?? '')));

        if ($code === '') {
            return $this->done('staff.currencies', local('currency_needs_iso'), false);
        }

        $key = trim((string) ($input['currency'] ?? ''));

        return $this->attempt(
            function () use ($input, $key, $code): void {
                Currency::save($input, $key !== '' ? $key : null);

                $this->log(
                    'currency.saved',
                    ($key !== '' ? 'Updated' : 'Added') . " currency {$code}."
                );
            },
            'staff.currencies',
            local($key !== '' ? 'currency_updated' : 'currency_added', $code)
        );
    }

    /**
     * Make a Currency The Default
     * @param string $currency Currency Uid
     * @return ?string
     */
    public function makeDefault(string $currency): ?string
    {
        $row = $this->record(Currency::find($currency), 'currency');
        $code = (string) $row['currency_code'];

        return $this->attempt(
            function () use ($row, $code): void {
                Currency::makeDefault((int) $row['currency_id']);

                $this->log('currency.default', "Made {$code} the default currency.");
            },
            'staff.currencies',
            "{$code} is now the default currency. Existing amounts are unchanged."
        );
    }

    /**
     * Delete a Currency
     * @param string $currency Currency Uid
     * @return ?string
     */
    public function delete(string $currency): ?string
    {
        $row = $this->record(Currency::find($currency), 'currency');
        $code = (string) $row['currency_code'];

        return $this->attempt(
            function () use ($row, $code): void {
                Currency::remove((int) $row['currency_id']);

                $this->log('currency.deleted', "Deleted currency {$code}.");
            },
            'staff.currencies',
            local('deleted_named', $code)
        );
    }
}
