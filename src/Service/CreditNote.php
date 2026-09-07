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
 * Credit notes - the document that says a customer does not owe this.
 *
 * A relay forwards method calls, not constants: `CreditNote::STATUSES` fatals
 * here. Reach it through `statusTable()`, or through the action.
 *
 * @see \LBM\Action\CreditNote
 * @method static array all(array $where = [], string $direction = 'ASC', ?string $order = null)
 * @method static array browseForClient(int $clientId, ?int $limit = null)
 * @method static int count(array $where = [])
 * @method static string creditableOn(array $invoice)
 * @method static string creditedAgainst(int $invoiceId)
 * @method static ?array find(int|string|null $key)
 * @method static ?array forClientKey(int|string $key, int $clientId)
 * @method static array forInvoice(int $invoiceId)
 * @method static int issue(array $input)
 * @method static array listing(array $where = [], ?string $search = null, ?int $limit = null)
 * @method static Model model()
 * @method static string number(int $creditNoteId)
 * @method static int remove(int|string $key)
 * @method static string remainingOn(array $note)
 * @method static array spendable(int $clientId)
 * @method static string spend(int $clientId, int|float|string $amount)
 * @method static ?int statusId(string $name)
 * @method static string statusTable()
 * @method static array statuses()
 * @method static ?string taxOn(array $note)
 * @method static int void(int|string $key)
 */
class CreditNote extends Relay
{
    protected static function getRelayAccessor(): string
    {
        return 'action.credit.note';
    }
}
