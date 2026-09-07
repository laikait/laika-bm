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

namespace LBM\Migration;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use RuntimeException;
use LBM\Contract\MigrationAbstract;

/**
 * The third real migration: Phase 28 needs a credit note to say which invoice it
 * credits.
 *
 * ---------------------------------------------------------------------------
 * THE TABLE SHIPPED WITHOUT THE ONE COLUMN THAT MAKES IT USABLE
 * ---------------------------------------------------------------------------
 * `credit_notes` has existed since Phase 0 carrying a client, a currency, an
 * amount, how much of it has been spent, a reason and a status - and no link to
 * an invoice at all. That is why the table was never written to by anything: a
 * credit note that cannot name the document it corrects is a number in a list.
 *
 * ---------------------------------------------------------------------------
 * WHY A COLUMN AND NOT THE `reason` TEXT
 * ---------------------------------------------------------------------------
 * The invoice number could be written into `reason`, which needs no migration
 * at all. It is the wrong answer for the reason 27.3 gave about side tables,
 * from the other direction: a fact stored as prose cannot be queried, so the
 * invoice screen could never show its own credit notes, and nothing could stop
 * an invoice being credited past its own total.
 *
 * ---------------------------------------------------------------------------
 * INDEXED, UNLIKE 27.3's COLUMN
 * ---------------------------------------------------------------------------
 * `order_items.domain_action` got no index because nothing sweeps on it. This
 * one is read the other way round on every invoice screen - "what has been
 * credited against this document" - which is a lookup by invoice and therefore
 * an index or a table scan.
 */
class M202609070100AddCreditNoteInvoice extends MigrationAbstract
{
    /** @var string Ledger Key. Written once, never edited */
    protected string $id = '20260907_0100_add_credit_note_invoice';

    /** @var string What This Does */
    protected string $description
        = 'Add credit_notes.invoice_relid, so a credit note can name the invoice it corrects.';

    /**
     * Whether This Install Needs It
     *
     * A fresh install created the table from CreditNoteSchema, which carries the
     * column, so this answers false there and is recorded `baselined` without
     * run() ever being called.
     * @return bool
     */
    public function applies(): bool
    {
        return $this->hasTable('credit_notes')
            && !$this->hasColumn('credit_notes', 'invoice_relid');
    }

    /**
     * Add The Column And Its Index
     *
     * NULLABLE WITH NO DEFAULT. Nothing has ever written a row to this table, so
     * there is no existing data to backfill - but nullable is also what the
     * column means for good: a goodwill credit belongs to a client and to no
     * invoice, and zero would be a made-up invoice id that reads as real.
     *
     * The index is created as a separate statement rather than inline, because
     * the two engines spell an inline index differently in ALTER and spell a
     * CREATE INDEX the same way.
     * @return void
     * @throws RuntimeException
     */
    public function run(): void
    {
        // statement() returns false on success - it is (bool) PDO::exec() and
        // DDL returns 0 - so nothing here branches on the return value. A real
        // failure arrives as a PDOException and stops the run, and the ledger
        // records nothing, so the next migrate tries again.
        match ($this->driver()) {
            'mysql' => $this->schema()->statement(
                'ALTER TABLE `credit_notes` ADD COLUMN `invoice_relid` BIGINT UNSIGNED NULL DEFAULT NULL'
            ),
            'pgsql' => $this->schema()->statement(
                'ALTER TABLE "credit_notes" ADD COLUMN "invoice_relid" BIGINT NULL DEFAULT NULL'
            ),
            default => throw new RuntimeException(
                'Unsupported driver for ' . $this->id() . ': ' . $this->driver()
            ),
        };

        match ($this->driver()) {
            'mysql' => $this->schema()->statement(
                'CREATE INDEX `credit_notes_invoice_relid_index` ON `credit_notes` (`invoice_relid`)'
            ),
            'pgsql' => $this->schema()->statement(
                'CREATE INDEX "credit_notes_invoice_relid_index" ON "credit_notes" ("invoice_relid")'
            ),
            default => throw new RuntimeException(
                'Unsupported driver for ' . $this->id() . ': ' . $this->driver()
            ),
        };
    }
}
