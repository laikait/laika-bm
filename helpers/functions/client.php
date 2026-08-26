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

use LBM\Service\Client;
use LBM\Service\ClientNote;

/**
 * Get Clients By Page number
 * @return array
 */
function get_clients(): array
{
    static $clients = null;
    if ($clients === null) $clients = Client::limit();
    return $clients;
}

/**
 * Get Single Client
 * @param int|string $entity
 * @return array
 */
function get_client(int|string $entity): array
{
    if (is_numeric($entity)) $entity = (int) $entity;
    static $client = [];
    if (!isset($client[$entity])) $client[$entity] = Client::single($entity);
    return $client[$entity];
}

/**
 * Check No Client Exists
 * @param int|string $entity
 * @return bool
 */
function has_no_client(int|string $entity): bool
{
    return get_client($entity) === [];
}

/**
 * Count Total Clients
 * @return int
 */
function count_clients(): int
{
    static $count = null;
    if ($count === null) $count = Client::count();
    return $count;
}

/**
 * Count Total Clients By Query
 * @return int
 */
function count_clients_by_query(): int
{
    static $count = null;
    if ($count === null) $count = Client::countByQuery();
    return $count;
}

/**
 * Count Total Clients By Status
 * @param string $status
 * @return int
 */
function client_count_by_status(string $status): int
{
    static $counts = [];
    $status = strtolower($status);
    if (!isset($counts[$status])) $counts[$status] = Client::countByStatus($status);
    return $counts[$status];
}

/**
 * Count Total Clients Created in Current Month
 * @return int
 */
function client_count_in_current_month(): int
{
    static $count = null;
    if ($count === null) $count = Client::countCurrentMonth();
    return $count;
}

/**
 * Client Status List
 * @return array
 */
function client_statuses(): array
{
    static $list = [];
    if ($list === []) $list = Client::statusList();
    return $list;
}

/**
 * Client Statuses With Color
 * @return array
 */
function client_status_name_color_list(): array
{
    static $list = [];
    if ($list === []) $list = array_column(client_statuses(), 'status_color', 'status_name');
    return $list;
}

#####################################################################################
/*================================== CLIENT NOTE ==================================*/
#####################################################################################
/**
 * get Single Note By ID
 * @param int|string $id
 * @return array
 */
function get_note(int|string $id): array
{
    // Validate Parameter
    if (!is_numeric($id) || (int) $id < 1) return [];

    static $notes = [];
    $id = (int) $id;

    if (!isset($notes[$id])) $notes[$id] = ClientNote::single($id);
    return $notes[$id];
}

/**
 * get Client Notes By Client ID
 * @param int|string $relid
 * @param string $orderBy
 * @return array
 */
function get_client_notes(int|string $relid, string $orderBy = 'DESC'): array
{
    // Validate Parameter
    if (!is_numeric($relid) || (int) $relid < 1) return [];

    static $notes = [];
    $relid = (int) $relid;
    $orderBy = strtoupper($orderBy) === 'DESC' ? 'DESC' : 'ASC';
    $key = "{$orderBy}_{$relid}";
    if (!isset($notes[$key])) $notes[$key] = ClientNote::getByClientId($relid, $orderBy);
    return $notes[$key];
}

/**
 * get Staff Notes By Staff ID
 * @param int|string $relid
 * @param string $orderBy
 * @return array
 */
function get_staff_notes(int $relid, string $orderBy = 'DESC'): array
{
    // Validate Parameter
    if (!is_numeric($relid) || (int) $relid < 1) return [];

    $relid = (int) $relid;
    $orderBy = strtoupper($orderBy) === 'DESC' ? 'DESC' : 'ASC';
    static $notes = [];
    $key = "{$orderBy}_{$relid}";
    if (!isset($notes[$key])) $notes[$key] = ClientNote::getByStaffId($relid, $orderBy);
    return $notes[$key];
}