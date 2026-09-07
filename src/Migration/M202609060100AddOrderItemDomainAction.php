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
 * The second real migration: Phase 27.3 needs an order line to say what is being
 * done to the domain it names.
 *
 * ---------------------------------------------------------------------------
 * WHY A COLUMN, AND WHY NOT THE `type` ENUM BESIDE IT
 * ---------------------------------------------------------------------------
 * `order_items.type` already distinguishes product, addon and domain, so adding
 * `transfer` to it looks like the smaller change. It is not. An enum is written
 * differently by the two supported engines - MySQL has a real ENUM, PostgreSQL
 * gets a CHECK constraint - so widening one means two dialect-specific
 * statements that have to find and rewrite an existing constraint by a name the
 * grammar chose. Adding a nullable VARCHAR is one statement, spelled the same
 * way twice.
 *
 * It is also the better data model. `type` says what KIND of thing the line is;
 * a register and a transfer are both domains, priced from the same `tlds` row,
 * carrying the same name, and differing only in what happens at the registry.
 *
 * ---------------------------------------------------------------------------
 * WHY NOT A SIDE TABLE
 * ---------------------------------------------------------------------------
 * A new table would have needed no migration at all - 26.2 used exactly that
 * escape twice. It was rejected here because the fact is a scalar property of
 * one line, not a set of rows hanging off it: a table whose presence-or-absence
 * IS the answer is a boolean hidden where nobody looks for it, and every reader
 * of order_items would need a join to ask a question the line should answer
 * itself.
 *
 * NO INDEX. Unlike Phase 24's `cancel_at`, nothing sweeps on this column -
 * the transfer sweep reads `domains`, not `order_items` - and an index on a
 * column that is NULL for most of the table earns nothing.
 */
class M202609060100AddOrderItemDomainAction extends MigrationAbstract
{
    /** @var string Ledger Key. Written once, never edited */
    protected string $id = '20260906_0100_add_order_item_domain_action';

    /** @var string What This Does */
    protected string $description
        = 'Add order_items.domain_action, so a domain line can say whether it is a registration or a transfer.';

    /**
     * Whether This Install Needs It
     *
     * A fresh install created the table from OrderItemSchema, which carries the
     * column, so this answers false there and is recorded `baselined` without
     * run() ever being called. An install that predates Phase 27.3 answers true
     * exactly once.
     * @return bool
     */
    public function applies(): bool
    {
        return $this->hasTable('order_items')
            && !$this->hasColumn('order_items', 'domain_action');
    }

    /**
     * Add The Column
     *
     * NULLABLE WITH NO DEFAULT, and that is the meaningful part. Every line that
     * already exists is either not a domain at all or a registration made before
     * transfers existed - and NULL reads as "not stated" in both cases, which
     * the code treats as `register`. Backfilling every historic domain line with
     * a value it never had would be writing a fact nobody recorded.
     * @return void
     * @throws RuntimeException
     */
    public function run(): void
    {
        // statement() returns false on success - it is (bool) PDO::exec() and
        // DDL returns 0 - so nothing here branches on the return value. A real
        // failure arrives as a PDOException and stops the run.
        match ($this->driver()) {
            'mysql' => $this->schema()->statement(
                'ALTER TABLE `order_items` ADD COLUMN `domain_action` VARCHAR(20) NULL DEFAULT NULL'
            ),
            'pgsql' => $this->schema()->statement(
                'ALTER TABLE "order_items" ADD COLUMN "domain_action" VARCHAR(20) NULL DEFAULT NULL'
            ),
            default => throw new RuntimeException(
                'Unsupported driver for ' . $this->id() . ': ' . $this->driver()
            ),
        };
    }
}
