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
use LBM\Service\Client;
use LBM\Service\CreditNote;
use LBM\Service\Currency;
use LBM\Service\Invoice;
use LBM\Service\Money;

/**
 * Credit notes.
 *
 * Behind `transaction`, which is where the money already lives - and 20.5's
 * rule means a `credit_note` group of its own would ship a screen invisible on
 * every installation that already exists, fixed by a checkbox nobody knows to
 * tick. A credit note IS a ledger document; whoever may see the transactions
 * screen may see these.
 *
 * THREE SCREENS, and no edit among them. A credit note that has been issued is
 * a document the customer may already hold a copy of, so it is voided rather
 * than corrected - and an edit form would be a way to change the amount on a
 * note somebody has already spent.
 */
class CreditNoteController extends AdminController
{
    protected function nav(): string
    {
        return 'credit_notes';
    }

    /**
     * The Credit Note List
     * @return string
     */
    public function index(): string
    {
        $where = $this->conditions(['status' => 'status_relid', 'client' => 'client_relid']);

        $page = CreditNote::listing($where, $this->search());

        // The number and what is left on it are worked out once here rather
        // than in the view: neither is a column, and Twig doing the subtraction
        // would be doing it in floating point on a decimal string.
        foreach ($page['rows'] as $index => $row) {
            $page['rows'][$index]['number'] = CreditNote::number((int) $row['credit_note_id']);
            $page['rows'][$index]['remaining'] = CreditNote::remainingOn($row);
        }

        return $this->screen('credit-notes', local('credit_notes'), [
            'pager'    =>  $page,
            'statuses' =>  CreditNote::statuses(),
        ]);
    }

    /**
     * Issue a Credit Note
     * @return ?string
     */
    public function create(): ?string
    {
        if (Request::isPost()) {
            $input = Request::inputs();

            return $this->attempt(
                function () use ($input): void {
                    $id = CreditNote::issue($input);

                    $this->log(
                        'credit_note.issued',
                        'Issued credit note ' . CreditNote::number($id)
                        . ' for ' . Money::format((string) ($input['amount'] ?? '0'))
                    );
                },
                'staff.credit.notes',
                local('credit_note_issued')
            );
        }

        return $this->form();
    }

    /**
     * One Credit Note
     * @param string $note Credit Note Uid
     * @return string
     */
    public function show(string $note): string
    {
        $row = $this->record(CreditNote::find($note), 'credit note');
        $invoiceId = (int) ($row['invoice_relid'] ?? 0);

        return $this->screen('credit-note', local('credit_note_numbered', CreditNote::number(
            (int) $row['credit_note_id']
        )), [
            'note'      =>  $row,
            'number'    =>  CreditNote::number((int) $row['credit_note_id']),
            'client'    =>  Client::find((int) $row['client_relid']),
            'invoice'   =>  $invoiceId > 0 ? Invoice::find($invoiceId) : null,
            'remaining' =>  CreditNote::remainingOn($row),

            // Null rather than zero when there is no invoice behind it, and the
            // template tells the two apart: zero is a claim about tax and null
            // is this document not being in a position to make one.
            'tax'       =>  CreditNote::taxOn($row),
            'voided'    =>  (int) $row['status_relid'] === CreditNote::statusId('voided'),
        ]);
    }

    /**
     * Void a Credit Note
     * @param string $note Credit Note Uid
     * @return ?string
     */
    public function void(string $note): ?string
    {
        $row = $this->record(CreditNote::find($note), 'credit note');
        $number = CreditNote::number((int) $row['credit_note_id']);

        return $this->attempt(
            function () use ($row, $number): void {
                CreditNote::void((int) $row['credit_note_id']);

                $this->log('credit_note.voided', "Voided credit note {$number}.");
            },
            'staff.credit.note',
            local('credit_note_voided'),
            ['note' => $row['uid']]
        );
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * The Issue Form
     *
     * Pre-filled from the query string when it is reached from an invoice, so
     * the operator does not retype a client and an invoice they were already
     * looking at - and, more to the point, cannot pick the wrong one.
     * @return string
     */
    private function form(): string
    {
        $invoiceUid = trim((string) Request::input('invoice', ''));
        $invoice = $invoiceUid !== '' ? Invoice::find($invoiceUid) : null;

        return $this->screen('credit-note-form', local('issue_a_credit_note'), [
            'invoice'    =>  $invoice,
            'creditable' =>  $invoice === null ? null : CreditNote::creditableOn($invoice),
            'clients'    =>  $this->clientChoices(),
            'currencies' =>  $this->currencyChoices(),
        ]);
    }

    /**
     * Who Can Be Credited
     * @return array<int,string>
     */
    private function clientChoices(): array
    {
        $choices = [];

        foreach (Client::all([], 'ASC', 'first_name') as $row) {
            $label = trim((string) ($row['company_name'] ?? '')) !== ''
                ? $row['company_name'] . ' (' . $row['first_name'] . ' ' . $row['last_name'] . ')'
                : $row['first_name'] . ' ' . $row['last_name'];

            $choices[(int) $row['cid']] = $label;
        }

        return $choices;
    }

    /**
     * Currency Choices
     * @return array<int,string>
     */
    private function currencyChoices(): array
    {
        $choices = [];

        foreach (Currency::listing(true) as $row) {
            $choices[(int) $row['currency_id']] = (string) $row['currency_code'];
        }

        return $choices;
    }
}
