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

namespace LBM\Controller\Client;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use LBM\Service\Client;
use LBM\Service\CreditNote;
use LBM\Service\Invoice;

/**
 * A client's credit notes.
 *
 * READ ONLY, and there is nothing here to do. A credit note is issued by the
 * operator and spent automatically against the next thing the customer buys -
 * there is no button a customer could press that would not be a way to ask for
 * money.
 *
 * It is here at all because a credit note is the CUSTOMER'S document. It is
 * what their own accounts need to explain why an invoice was settled for less
 * than it says, and a billing system that issues one and shows it only to the
 * operator has issued half a document.
 *
 * Ownership is scoped through `CreditNote::forClientKey()`, so another
 * account's note is not found rather than found and refused - the 20.4 rule.
 */
class CreditNoteController extends ClientController
{
    protected function nav(): string
    {
        return 'credit_notes';
    }

    /**
     * The Client's Credit Notes
     * @return string
     */
    public function index(): string
    {
        $this->allow('invoice');

        $clientId = $this->owner();
        $page = CreditNote::browseForClient($clientId);

        foreach ($page['rows'] as $index => $row) {
            $page['rows'][$index]['number'] = CreditNote::number((int) $row['credit_note_id']);
            $page['rows'][$index]['remaining'] = CreditNote::remainingOn($row);
        }

        $client = Client::find($clientId);

        return $this->screen('credit-notes', local('my_credit_notes'), [
            'pager'    =>  $page,
            'statuses' =>  CreditNote::statuses(),

            // The balance, not the sum of the notes. They are different numbers
            // whenever an operator has put credit on by hand, and the one the
            // customer can actually spend is this one.
            'balance'  =>  (string) ($client['credit_balance'] ?? '0'),
        ]);
    }

    /**
     * One Credit Note
     * @param string $note Credit Note Uid
     * @return string
     */
    public function show(string $note): string
    {
        $this->allow('invoice');

        $row = $this->mine(
            static fn(int|string $key, int $clientId): ?array
                => CreditNote::forClientKey($key, $clientId),
            $note,
            'credit note'
        );

        $invoiceId = (int) ($row['invoice_relid'] ?? 0);

        return $this->screen('credit-note', local('credit_note_numbered', CreditNote::number(
            (int) $row['credit_note_id']
        )), [
            'note'      =>  $row,
            'number'    =>  CreditNote::number((int) $row['credit_note_id']),
            'remaining' =>  CreditNote::remainingOn($row),
            'tax'       =>  CreditNote::taxOn($row),

            // Their own invoice, looked up by ownership again rather than by id
            // - a note is checked above, and the invoice on it has to be too.
            'invoice'   =>  $invoiceId > 0
                ? Invoice::forClientKey($invoiceId, $this->owner())
                : null,
        ]);
    }
}
