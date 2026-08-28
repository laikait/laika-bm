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
use LBM\Model\CountryModel;

/**
 * Countries - a read-only reference list, seeded once and left alone.
 *
 * Every address form and every tax rule needs it, and a client's country is
 * usually rendered several times on one page, so the whole table is memoised on
 * first use. It is a couple of hundred rows that never change during a request;
 * loading it once beats a lookup per rendered row by a wide margin.
 */
class Country extends Action
{
    /** @var array<int,array>|null Every Country, Keyed By Id */
    private ?array $countries = null;

    public function model(): Model
    {
        return new CountryModel();
    }

    protected function searchable(): array
    {
        return ['country_name', 'iso2', 'iso3'];
    }

    ####################################################################################
    /*================================= EXTERNAL API =================================*/
    ####################################################################################

    /**
     * Every Country, Keyed By Id
     * @return array<int,array>
     */
    public function listing(): array
    {
        if ($this->countries !== null) {
            return $this->countries;
        }

        $model = $this->model();
        $id = $model->id;

        $countries = [];

        foreach ($model->order('country_name', self::ASC)->get() as $row) {
            $countries[(int) $row[$id]] = $row;
        }

        return $this->countries = $countries;
    }

    /**
     * One Country
     * @param int|string|null $key Country ID
     * @return ?array
     */
    public function get(int|string|null $key): ?array
    {
        if ($key === null || $key === '' || $key === 0) {
            return null;
        }

        return $this->listing()[(int) $key] ?? null;
    }

    /**
     * A Country's Name
     * @param int|string|null $key Country ID
     * @param string $default Fallback
     * @return string
     */
    public function name(int|string|null $key, string $default = ''): string
    {
        $row = $this->get($key);

        return $row === null ? $default : (string) ($row['country_name'] ?? $default);
    }

    /**
     * Find a Country By Its ISO Code
     *
     * Accepts either the two- or three-letter form, since address imports use
     * both and the caller rarely knows which it has.
     * @param string $iso ISO 3166 Code
     * @return ?array
     */
    public function findByIso(string $iso): ?array
    {
        $iso = strtoupper(trim($iso));
        $column = strlen($iso) === 3 ? 'iso3' : 'iso2';

        foreach ($this->listing() as $row) {
            if (strtoupper((string) ($row[$column] ?? '')) === $iso) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Country Choices For a Select Box, Keyed By Id
     * @return array<int,string>
     */
    public function choices(): array
    {
        $choices = [];

        foreach ($this->listing() as $id => $row) {
            $choices[$id] = (string) ($row['country_name'] ?? '');
        }

        return $choices;
    }

    /**
     * A Country's International Dialling Code
     * @param int|string|null $key Country ID
     * @return string
     */
    public function phoneCode(int|string|null $key): string
    {
        $row = $this->get($key);

        return $row === null ? '' : (string) ($row['phone_code'] ?? '');
    }

    /**
     * Forget The Cached List
     * @return void
     */
    public function flush(): void
    {
        $this->countries = null;
    }
}
