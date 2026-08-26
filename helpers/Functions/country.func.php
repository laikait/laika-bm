<?php
/**
 * Laika PHP MVC Framework
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: MIT
 * This file is part of the Laika PHP MVC Framework.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use LBM\Service\Country;

/**
 * Get Countries
 * @return array
 */
function get_countries(): array
{
    static $countries = [];
    if ($countries === []) $countries = Country::list();
    return $countries;
}

/**
 * Get Single Country
 * @param int|string $entity
 * @return array
 */
function get_country(int|string $entity): array
{
    static $country = [];
    if (is_numeric($entity)) $entity = (int) $entity;
    if (!isset($country[$entity])) $country = Country::single($entity);
    return $country[$entity];
}

/**
 * Country Phone Code List With ISO2 Country Key 
 * @return array
 */
function iso2_phone_code_list(): array
{
    static $list = [];
    if ($list === []) {
        $list = array_column(get_countries(), 'phone_code', 'iso2');
        ksort($list);
    }
    return $list;
}

/**
 * Country Phone Code List With ISO3 Country Key 
 * @return array
 */
function iso3_phone_code_list(): array
{
    static $list = [];
    if ($list === []) {
        $list = array_column(get_countries(), 'phone_code', 'iso3');
        ksort($list);
    }
    return $list;
}