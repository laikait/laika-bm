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

namespace LBM\Service;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Laika\Relay\Relay;

/**
 * Invoices and their line items.
 *
 * @see \LBM\Action\Invoice
 * @method static int addItem(int $invoiceId, array $item)
 * @method static array all(array $where = [], string $direction = 'ASC', ?string $order = null)
 * @method static string applyCredit(int $invoiceId)
 * @method static bool applyPayment(int $invoiceId, int|float|string $amount)
 * @method static string balance(array $invoice)
 * @method static array browse(array $where = [], ?string $search = null, ?int $limit = null, string $direction = 'DESC')
 * @method static array browseForClient(int $clientId, array $where = [], ?int $limit = null)
 * @method static array browseUnpaid(?int $limit = null)
 * @method static array browseWithClients(array $where = [], ?string $search = null, ?int $limit = null)
 * @method static int cancel(int|string $key)
 * @method static int count(array $where = [])
 * @method static int create(array $data)
 * @method static int delete(int|string $key)
 * @method static int deleteWhere(array $where)
 * @method static bool exists(array $where)
 * @method static ?array find(int|string|null $key)
 * @method static ?array first(array $where)
 * @method static ?array forClientKey(int|string $key, int $clientId)
 * @method static bool isOverdue(array $invoice)
 * @method static bool isSettled(array $invoice)
 * @method static array items(int $invoiceId)
 * @method static int markPaid(int $invoiceId)
 * @method static Model model()
 * @method static int modify(int|string $key, array $input)
 * @method static string outstandingFor(int $clientId)
 * @method static array recalculate(int $invoiceId)
 * @method static int remove(int|string $key)
 * @method static int removeItem(int $invoiceId, int|string $key)
 * @method static void replaceItems(int $invoiceId, array $items)
 * @method static array settledStatusIds()
 * @method static ?int statusId(string $name)
 * @method static string statusTable()
 * @method static array statuses()
 * @method static int store(array $input, array $items = [])
 * @method static int update(int|string $key, array $data)
 * @method static int updateWhere(array $where, array $data)
 */
class Invoice extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'action.invoice';
    }
}
