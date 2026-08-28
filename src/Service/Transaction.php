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
 * The money ledger: payments, refunds and credits.
 *
 * @see \LBM\Action\Transaction
 * @method static array all(array $where = [], string $direction = 'ASC', ?string $order = null)
 * @method static array browse(array $where = [], ?string $search = null, ?int $limit = null, string $direction = 'DESC')
 * @method static array browseForClient(int $clientId, ?int $limit = null)
 * @method static array browseWithClients(array $where = [], ?string $search = null, ?int $limit = null)
 * @method static int count(array $where = [])
 * @method static int create(array $data)
 * @method static int credit(int $clientId, int|float|string $amount, string $description = '', ?int $currencyId = null)
 * @method static int delete(int|string $key)
 * @method static int deleteWhere(array $where)
 * @method static bool exists(array $where)
 * @method static ?array find(int|string|null $key)
 * @method static ?array first(array $where)
 * @method static array forInvoice(int $invoiceId)
 * @method static string income(?string $from = null, ?string $to = null)
 * @method static Model model()
 * @method static int pay(array $input)
 * @method static int recordGatewayData(int $transactionId, array $data)
 * @method static int refund(int|string $key, int|float|string|null $amount = null, string $reason = '')
 * @method static string refundedAgainst(int $transactionId)
 * @method static int remove(int|string $key)
 * @method static ?int statusId(string $name)
 * @method static string statusTable()
 * @method static array statuses()
 * @method static array types()
 * @method static int update(int|string $key, array $data)
 * @method static int updateWhere(array $where, array $data)
 */
class Transaction extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'action.transaction';
    }
}
