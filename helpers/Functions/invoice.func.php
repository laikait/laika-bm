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

use LBM\Service\Invoice;

/**
 * Get Invoices By Page Number
 * @return array
 */
function get_invoices(): array
{
    static $invoices = [];
    if ($invoices === []) $invoices = Invoice::limit();
    return $invoices;
}

/**
 * Get Single Invoice
 * @param int|string $entity
 * @return array
 */
function get_invoice(int|string $entity): array
{
    static $invoice = [];
    if (is_numeric($entity)) $entity = (int) $entity;
    if (!isset($invoice[$entity])) $invoice[$entity] = Invoice::single($entity);
    return $invoice[$entity];
}

/**
 * Get Group By Status
 * @return array
 */
function group_by_status(): array
{
    static $groups = null;
    if ($groups === null) $groups = Invoice::groupByStatus();
    return $groups;
}

/**
 * Get Total Spent By Client
 * @param int|string $relid
 * @return string
 */
function total_spent_by_client(int|string $relid): string
{
    // Validate Parameter
    if (!is_numeric($relid) || (int) $relid < 1) return '0.00';

    static $totals = [];
    $relid = (int) $relid;

    // Get Total Spent By Client
    if (!isset($totals[$relid])) $totals[$relid] = Invoice::totalSpentByClient($relid);
    return $totals[$relid];
}

/**
 * Get Total Outstanding By Client
 * @param int $relid
 * @return string
 */
function total_outstanding_by_client(int|string $relid): string
{
    // Validate Parameter
    if (!is_numeric($relid) || (int) $relid < 1) return '0.00';

    static $totals = [];
    $relid = (int) $relid;

    // Get Total Outstanding By Client
    if (!isset($totals[$relid])) $totals[$relid] = Invoice::totalOutstandingByClient($relid);
    return $totals[$relid];
}

/**
 * Get Client Invoices
 * @param int $relid
 * @return array
 */
function client_invoices(int|string $relid): array
{
    // Validate Parameter
    if (!is_numeric($relid) || (int) $relid < 1) return [];

    static $invoices = [];
    $relid = (int) $relid;

    // Get Client Invoices
    if (!isset($invoices[$relid])) $invoices[$relid] = Invoice::clientInvoices($relid);
    return $invoices[$relid];
}
