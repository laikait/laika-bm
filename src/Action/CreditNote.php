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
use LBM\Model\CreditNoteModel;
use LBM\Service\Money;
use LBM\Service\Status;
use RuntimeException;

/**
 * Credit notes - the document that says a customer does not owe this.
 *
 * `credit_notes` and `credit_note_statuses` have existed since Phase 0 with no
 * reader and no writer anywhere. The status table is seeded with open, partial,
 * used and voided, and all four were unreachable.
 *
 * ---------------------------------------------------------------------------
 * A CREDIT NOTE IS NOT A REFUND, AND THE DIFFERENCE IS WHERE THE MONEY GOES
 * ---------------------------------------------------------------------------
 * Phase 24 made the same argument about services: cancel and terminate look
 * like one act until you notice they fail in opposite directions. So do these.
 *
 *   - A REFUND sends money back out. It needs the gateway, it can fail at the
 *     processor, and it leaves the customer's bank account better off. See
 *     Action\Refund.
 *   - A CREDIT NOTE moves no money at all. It says the customer does not owe
 *     something, and it settles against what they buy next.
 *
 * Folding them into one verb gets you a button that sometimes moves money and
 * sometimes does not, which is the one thing an accounts screen may not do.
 *
 * ---------------------------------------------------------------------------
 * ONE BALANCE, AND THE NOTE IS THE DOCUMENT BEHIND PART OF IT
 * ---------------------------------------------------------------------------
 * `clients.credit_balance` is the money and stays the only place it is counted.
 * Issuing a note writes a `credit` transaction through Action\Transaction -
 * which is what moves the balance - and the note is the paperwork explaining
 * that movement: which invoice, why, and how much of it has since been spent.
 *
 * A second balance living on `credit_notes.amount` would be a number that
 * agrees with the client's balance right up until the day it does not, and no
 * screen could say which was right.
 *
 * `used_amount` is therefore NOT a balance either - it is how much of THIS
 * document has been consumed, drawn down oldest-first by Invoice::applyCredit()
 * as the balance is actually spent. Which is why credit that was never granted
 * by a note - an operator adding some by hand - is spent last and marks nothing:
 * there is no document to mark.
 *
 * ---------------------------------------------------------------------------
 * TAX, WHICH PHASE 25 DEFERRED TO HERE
 * ---------------------------------------------------------------------------
 * `credit_notes` has an amount and no line items, no rate and no tax column, so
 * a note is a single GROSS figure - what the customer is not being asked for,
 * tax included. That is the only reading a one-column document can carry, and
 * it is the right one: the customer's side of a credit note is the money.
 *
 * The tax inside it is derived, not stored, exactly as `Invoice::taxAmount()`
 * is: the note's share of the invoice's own tax, at the invoice's blended rate.
 * Storing a rate here would give a second answer to a question the invoice has
 * already settled, and Phase 25 found what that costs when the two disagree.
 *
 * A note against no invoice states no tax, because there is no document behind
 * it to take a rate from and inventing one would put a number on a tax return.
 *
 * ---------------------------------------------------------------------------
 * A NOTE NEVER CREDITS MORE THAN THE INVOICE IS WORTH
 * ---------------------------------------------------------------------------
 * Two notes for 60 against a 100 invoice is 120 of credit for a document that
 * only ever billed 100, and the operator finds out at the year end. What is
 * left to credit counts every note already issued against that invoice, voided
 * ones excepted - a voided note credited nothing.
 *
 * @see \LBM\Action\Refund for money actually going back
 */
class CreditNote extends Action
{
    /** @var string The Status Lookup Table */
    public const STATUSES = 'credit_note_statuses';

    /** @var string[] Columns This Accepts From a Form */
    public const FIELDS = [
        'client_relid', 'invoice_relid', 'currency_relid', 'amount', 'reason',
    ];

    public function model(): Model
    {
        return new CreditNoteModel();
    }

    protected function searchable(): array
    {
        return ['reason'];
    }

    protected function createdColumn(): ?string
    {
        return 'credit_created_at';
    }

    /**
     * No Updated Column On This Table
     *
     * Deliberate rather than an omission: a credit note is a document that has
     * been issued. It is voided, never edited, so there is no second timestamp
     * for anything to move.
     * @return ?string
     */
    protected function updatedColumn(): ?string
    {
        return null;
    }

    ####################################################################################
    /*=================================== READING ====================================*/
    ####################################################################################

    /**
     * The Status Lookup Table This Resource Uses
     *
     * A method rather than the STATUSES constant, because a relay facade
     * forwards method calls and not constants - so a controller reaching this
     * through LBM\Service\CreditNote has no way to read the constant.
     * @return string
     */
    public function statusTable(): string
    {
        return self::STATUSES;
    }

    /**
     * Every Credit Note Status
     * @return array
     */
    public function statuses(): array
    {
        return Status::all(self::STATUSES);
    }

    /**
     * A Status Id By Name
     * @param string $name Status Name
     * @return ?int
     */
    public function statusId(string $name): ?int
    {
        return Status::idOf(self::STATUSES, $name);
    }

    /**
     * A Human-Readable Number For a Credit Note
     *
     * Built from the primary key, exactly as `Invoice::number()` is and for the
     * same reason: counting rows to pick the next number is a race two notes
     * raised in the same second both lose, and the key is already unique.
     *
     * A separate prefix from the invoice one, because a credit note numbered
     * like an invoice is a credit note somebody files as an invoice.
     * @param int $creditNoteId Credit Note ID
     * @return string
     */
    public function number(int $creditNoteId): string
    {
        $prefix = option('credit_note_prefix', 'CN-') ?? 'CN-';

        return $prefix . str_pad((string) $creditNoteId, 6, '0', STR_PAD_LEFT);
    }

    /**
     * One Page Of Credit Notes
     * @param array $where Conditions
     * @param ?string $search Search Term
     * @param ?int $limit Rows Per Page
     * @return array
     */
    public function listing(array $where = [], ?string $search = null, ?int $limit = null): array
    {
        return $this->browse($where, $search, $limit, self::DESC);
    }

    /**
     * Every Credit Note For One Client
     * @param int $clientId Client ID
     * @param ?int $limit Rows Per Page
     * @return array
     */
    public function browseForClient(int $clientId, ?int $limit = null): array
    {
        return $this->browse(['client_relid' => $clientId], null, $limit, self::DESC);
    }

    /**
     * One Credit Note, But Only If It Belongs To This Client
     *
     * The ownership check is in the QUERY, not in a branch after the read - the
     * 20.4 rule. Another client's note is *not found*, which is the answer that
     * leaks nothing about whether it exists.
     * @param int|string $key Credit Note ID Or Uid
     * @param int $clientId Client ID
     * @return ?array
     */
    public function forClientKey(int|string $key, int $clientId): ?array
    {
        $model = $this->key($this->model(), $key);

        $rows = $model->where(['client_relid' => $clientId])->get();

        return $rows === [] ? null : $rows[0];
    }

    /**
     * Every Credit Note Raised Against One Invoice
     * @param int $invoiceId Invoice ID
     * @return array
     */
    public function forInvoice(int $invoiceId): array
    {
        return $invoiceId > 0 ? $this->all(['invoice_relid' => $invoiceId], self::DESC) : [];
    }

    /**
     * How Much Has Been Credited Against One Invoice
     *
     * Voided notes are not counted. A voided note credited nothing and took its
     * credit back, so counting it would leave an invoice permanently unable to
     * be credited for money nobody ever received.
     * @param int $invoiceId Invoice ID
     * @return string Decimal string
     */
    public function creditedAgainst(int $invoiceId): string
    {
        $voided = $this->statusId('voided');
        $total = '0';

        foreach ($this->forInvoice($invoiceId) as $row) {
            if ($voided !== null && (int) ($row['status_relid'] ?? 0) === $voided) {
                continue;
            }

            $total = Money::add($total, (string) ($row['amount'] ?? '0'));
        }

        return Money::round($total);
    }

    /**
     * What Is Left To Credit On An Invoice
     *
     * The invoice's own total, less everything already credited against it.
     * Never negative.
     * @param array $invoice Invoice Row
     * @return string Decimal string
     */
    public function creditableOn(array $invoice): string
    {
        $left = Money::sub(
            (string) ($invoice['total'] ?? '0'),
            $this->creditedAgainst((int) ($invoice['invoice_id'] ?? 0))
        );

        return Money::isGreater($left, '0') ? Money::round($left) : '0';
    }

    /**
     * What Is Left Unspent On One Credit Note
     * @param array $note Credit Note Row
     * @return string Decimal string. Never negative
     */
    public function remainingOn(array $note): string
    {
        $left = Money::sub(
            (string) ($note['amount'] ?? '0'),
            (string) ($note['used_amount'] ?? '0')
        );

        return Money::isGreater($left, '0') ? Money::round($left) : '0';
    }

    /**
     * The Tax Inside a Credit Note, In Money
     *
     * Derived from the invoice it credits, never stored - the same contract
     * `Invoice::taxAmount()` works to. A note is a GROSS figure, so the tax
     * inside it is the same proportion of it as the invoice's tax is of the
     * invoice's total.
     *
     * A note against no invoice states NO TAX rather than zero tax, and those
     * are different answers: zero is a claim about a transaction, and null is
     * this document not being in a position to make one. A number invented here
     * would end up on somebody's tax return.
     * @param array $note Credit Note Row
     * @return ?string Decimal string, or null when there is no invoice behind it
     */
    public function taxOn(array $note): ?string
    {
        $invoiceId = (int) ($note['invoice_relid'] ?? 0);

        if ($invoiceId <= 0) {
            return null;
        }

        $invoice = (new Invoice())->find($invoiceId);

        if ($invoice === null) {
            return null;
        }

        $total = (string) ($invoice['total'] ?? '0');

        if (Money::isZero($total)) {
            return '0';
        }

        $tax = (new Invoice())->taxAmount($invoice);

        if (Money::isZero($tax)) {
            return '0';
        }

        // amount * (tax / total). Multiplied before dividing, so the only
        // rounding is the one at the end.
        return Money::round(Money::div(
            Money::mul((string) ($note['amount'] ?? '0'), $tax),
            $total
        ));
    }

    /**
     * Every Note With Credit Still On It, Oldest First
     *
     * Oldest first because that is the order they are drawn down in: a customer
     * holding two credits spends the one they were given first, which is what
     * anybody reading the two documents would expect.
     * @param int $clientId Client ID
     * @return array
     */
    public function spendable(int $clientId): array
    {
        $voided = $this->statusId('voided');
        $used = $this->statusId('used');
        $out = [];

        foreach ($this->all(['client_relid' => $clientId], self::ASC, 'credit_note_id') as $row) {
            $status = (int) ($row['status_relid'] ?? 0);

            if (($voided !== null && $status === $voided) || ($used !== null && $status === $used)) {
                continue;
            }

            if (Money::isZero($this->remainingOn($row))) {
                continue;
            }

            $out[] = $row;
        }

        return $out;
    }

    ####################################################################################
    /*=================================== WRITING ====================================*/
    ####################################################################################

    /**
     * Issue a Credit Note
     *
     * Writes the document, records the money in the ledger, and moves the
     * client's balance - the ledger entry being what `Transaction::credit()`
     * already does. This adds the paperwork that says why, which is the half
     * `credit_notes` was built for and never given.
     *
     * @param array $input {
     *     @type int    $client_relid   Who is being credited. Required
     *     @type int    $invoice_relid  Which invoice, or 0/absent for none
     *     @type int    $currency_relid Currency. Defaults to the invoice's own
     *     @type string $amount         Gross amount. Required, greater than zero
     *     @type string $reason         Why, for the customer to read
     * }
     * @return int New Credit Note ID
     * @throws RuntimeException
     */
    public function issue(array $input): int
    {
        $clientId = (int) ($input['client_relid'] ?? 0);

        if ($clientId <= 0) {
            throw new RuntimeException('A credit note has to belong to a client.');
        }

        $client = (new Client())->find($clientId);

        if ($client === null) {
            throw new RuntimeException('That client no longer exists.');
        }

        $amount = Money::round((string) ($input['amount'] ?? '0'));

        if (!Money::isGreater($amount, '0')) {
            throw new RuntimeException('A credit note has to be for more than zero.');
        }

        $invoiceId = (int) ($input['invoice_relid'] ?? 0);
        $invoice = null;

        if ($invoiceId > 0) {
            $invoice = (new Invoice())->find($invoiceId);

            if ($invoice === null) {
                throw new RuntimeException('That invoice no longer exists.');
            }

            // An invoice belongs to one client, and a credit note against it
            // belongs to the same one. Crediting client B for client A's
            // invoice is a mis-key that hands out real money.
            if ((int) $invoice['client_relid'] !== $clientId) {
                throw new RuntimeException('That invoice belongs to a different client.');
            }

            $left = $this->creditableOn($invoice);

            if (Money::isGreater($amount, $left)) {
                throw new RuntimeException(
                    'That is more than is left to credit on this invoice (' . Money::format($left) . ').'
                );
            }
        }

        $currencyId = (int) ($input['currency_relid'] ?? 0);

        if ($currencyId <= 0) {
            // The invoice's currency before the client's: a note against a
            // document has to be in the document's money, and a client whose
            // default was changed since would otherwise credit the wrong one.
            $currencyId = (int) ($invoice['currency_relid'] ?? $client['currency_relid'] ?? 0);
        }

        $reason = trim((string) ($input['reason'] ?? ''));

        $id = $this->create([
            'client_relid'   =>  $clientId,
            'invoice_relid'  =>  $invoiceId > 0 ? $invoiceId : null,
            'currency_relid' =>  $currencyId,
            'amount'         =>  $amount,
            'used_amount'    =>  '0',
            'reason'         =>  $reason !== '' ? $reason : null,
            'status_relid'   =>  $this->statusId('open') ?? 1,
        ]);

        // The money, second. The document exists first so a ledger entry can
        // never point at a note that was not written - and a failure here
        // leaves a note with no credit behind it, which is visible on the
        // screen, rather than credit with no note, which is not.
        (new Transaction())->credit(
            $clientId,
            $amount,
            $reason !== '' ? $reason : ('Credit note ' . $this->number($id)),
            $currencyId > 0 ? $currencyId : null
        );

        return $id;
    }

    /**
     * Spend Credit Against The Notes That Granted It
     *
     * Called by `Invoice::applyCredit()` once the balance has actually moved,
     * with the amount that was applied. Draws the notes down oldest first and
     * moves each one to `partial` or `used` as it goes.
     *
     * RETURNS WHAT IT COULD NOT ATTRIBUTE, and that is not an error: an
     * operator can put credit on an account by hand through the transactions
     * screen, and there is no document behind that to mark. Refusing to spend
     * it would strand real money on the account.
     * @param int $clientId Client ID
     * @param int|float|string $amount The amount actually applied
     * @return string What was left over with no note to charge it to
     */
    public function spend(int $clientId, int|float|string $amount): string
    {
        $left = Money::round((string) $amount);

        if (!Money::isGreater($left, '0')) {
            return '0';
        }

        $partial = $this->statusId('partial');
        $used = $this->statusId('used');

        foreach ($this->spendable($clientId) as $note) {
            if (!Money::isGreater($left, '0')) {
                break;
            }

            $available = $this->remainingOn($note);
            $taking = Money::isGreater($left, $available) ? $available : $left;

            $spent = Money::round(Money::add((string) ($note['used_amount'] ?? '0'), $taking));
            $data = ['used_amount' => $spent];

            $status = Money::isGreater((string) ($note['amount'] ?? '0'), $spent)
                ? $partial
                : $used;

            if ($status !== null) {
                $data['status_relid'] = $status;
            }

            $this->update((int) $note['credit_note_id'], $data);

            $left = Money::sub($left, $taking);
        }

        return Money::round($left);
    }

    /**
     * Void a Credit Note
     *
     * Takes the credit back and marks the document void. The row stays, because
     * a credit note that has been issued is a document somebody may already
     * hold a copy of - deleting it makes an operator's books and a customer's
     * filing cabinet disagree with no way to tell which is right.
     *
     * TWO REFUSALS, and they are different:
     *
     *   - a note with anything spent against it cannot be voided at all. That
     *     money has already settled an invoice, and taking it back would
     *     un-settle a document the customer has been told is paid.
     *   - a note whose credit is no longer on the account cannot be voided
     *     either. The customer spent it on something with no note behind it,
     *     and reversing it here would drive the balance negative - which every
     *     later `applyCredit()` would read as money available to spend.
     * @param int|string $key Credit Note ID Or Uid
     * @return int Affected rows
     * @throws RuntimeException
     */
    public function void(int|string $key): int
    {
        $note = $this->find($key);

        if ($note === null) {
            return 0;
        }

        $voided = $this->statusId('voided');

        if ($voided !== null && (int) ($note['status_relid'] ?? 0) === $voided) {
            return 0;
        }

        if (Money::isGreater((string) ($note['used_amount'] ?? '0'), '0')) {
            throw new RuntimeException(
                'Some of this credit has already been used against an invoice. It cannot be voided.'
            );
        }

        $clientId = (int) $note['client_relid'];
        $amount = Money::round((string) ($note['amount'] ?? '0'));

        $client = (new Client())->find($clientId);
        $balance = (string) ($client['credit_balance'] ?? '0');

        if (Money::isGreater($amount, $balance)) {
            throw new RuntimeException(
                'The client no longer holds this much credit (' . Money::format($balance)
                . '), so voiding this note would take their balance below zero.'
            );
        }

        $affected = $voided === null ? 0 : $this->update((int) $note['credit_note_id'], [
            'status_relid' =>  $voided,
        ]);

        // The ledger records the reversal as its own entry rather than deleting
        // the credit: Action\Transaction is append-only in spirit, and an
        // accounts history that can be quietly rewritten is not a history.
        (new Transaction())->credit(
            $clientId,
            '-' . $amount,
            'Credit note ' . $this->number((int) $note['credit_note_id']) . ' voided'
        );

        return $affected;
    }

    /**
     * A Credit Note Is Not Deleted
     *
     * Named so the base class's delete() is not the way in. An issued document
     * is voided, and void() is the method that says so - a number that vanishes
     * out of a sequence is a number an auditor asks about.
     * @param int|string $key Credit Note ID Or Uid
     * @return int Affected rows
     * @throws RuntimeException
     */
    public function remove(int|string $key): int
    {
        if ($this->find($key) === null) {
            return 0;
        }

        throw new RuntimeException(
            'A credit note that has been issued is not deleted. Void it instead, so the '
            . 'number stays accounted for.'
        );
    }

    /**
     * Reduce Submitted Input To Writable Columns
     * @param array $input Submitted Data
     * @return array
     */
    public function fields(array $input): array
    {
        return $this->only($input, self::FIELDS);
    }
}
