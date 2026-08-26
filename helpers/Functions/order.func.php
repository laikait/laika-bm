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

use LBM\Service\Order;

/**
 * Get Orders By Page Number
 * @return array
 */
function get_orders(): array
{
    static $orders = null;
    if ($orders === null) $orders = Order::limit();
    return $orders;
}

/**
 * Get Single Order
 * @param int|string $entity
 * @return array
 */
function get_order(int|string $entity): array
{
    static $orders = null;
    if (is_numeric($entity)) $entity = (int) $entity;
    if (!isset($orders[$entity])) $orders[$entity] = Order::single($entity);
    return $orders[$entity];
}

/**
 * Get Client Orders
 * @param int|string $id
 * @return array
 */
function get_client_orders(int|string $relid): array
{
    static $orders = [];
    // Validate Relid
    if (!is_numeric($relid)) return [];
    $relid = (int) $relid;
    if (!array_key_exists($relid, $orders)) $orders[$relid] = Order::clientOrders($relid);
    return $orders[$relid];
}

/**
 * Count Total Orders
 * @return int
 */
function count_orders(): int
{
    static $count = null;
    if ($count === null) $count = Order::count();
    return $count;
}

/**
 * Count Total Orders By Query
 * @return int
 */
function count_orders_by_query(): int
{
    static $count = null;
    if ($count === null) $count = Client::countByQuery();
    return $count;
}

/**
 * Order Status List
 * @return array<int,array>
 */
function order_statuses(): array
{
    static $list = [];
    if ($list === []) $list = Order::statusList();
    return $list;
}

/**
 * Order Statuses With Color
 * @return array<string,string>
 * Example: ['active'=>'#000000', 'inactive'=>'#000000'...]
 */
function order_status_name_color_list(): array
{
    static $list = [];
    if ($list === []) $list = array_column(order_statuses(), 'status_color', 'status_name');
    return $list;
}