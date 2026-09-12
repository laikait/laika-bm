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
 * The seventh real migration: Phase 40 gives a registrar a Live/Test switch.
 *
 * A module now manages its own live and test API, and the operator chooses
 * which one it calls. A gateway has always had `payment_gateways.test_mode`; a
 * registrar had nowhere to say it, so this adds the same column beside it.
 *
 * A COLUMN, not a key inside `credentials`. That column is sealed values under
 * names an operator or a module chose, and a mode hidden among them would be a
 * flag nothing can read without opening every secret on the row - the
 * registrars list could not say which ones are in test mode without decrypting
 * API keys to find out.
 *
 * Defaults to `no`, so every existing registrar stays live: the only safe
 * answer for a registrar that was, until this release, always live.
 *
 * `DomainRegistrarSchema` carries the column too, so a fresh install baselines
 * this and `run()` is never called there - migrationwalk drops the column and
 * forces it on both engines.
 */
class M202609120200AddRegistrarTestMode extends MigrationAbstract
{
    /** @var string Ledger Key. Written once, never edited */
    protected string $id = '20260912_0200_add_registrar_test_mode';

    /** @var string What This Does */
    protected string $description
        = 'Add domain_registrars.test_mode, so a registrar module can be pointed at its sandbox.';

    /**
     * Whether This Install Needs It
     * @return bool
     */
    public function applies(): bool
    {
        return $this->hasTable('domain_registrars')
            && !$this->hasColumn('domain_registrars', 'test_mode');
    }

    /**
     * Add The Column
     *
     * statement() returns false on success - DDL affects no rows - so nothing
     * branches on it. A real failure arrives as a PDOException, the ledger
     * records nothing, and the next update tries again.
     * @return void
     */
    public function run(): void
    {
        match ($this->driver()) {
            'mysql' => $this->schema()->statement(
                "ALTER TABLE `domain_registrars` ADD COLUMN `test_mode` ENUM('yes','no') NOT NULL DEFAULT 'no'"
            ),
            'pgsql' => $this->schema()->statement(
                'ALTER TABLE "domain_registrars" ADD COLUMN "test_mode" VARCHAR(3) NOT NULL DEFAULT \'no\''
            ),
            default => throw new RuntimeException(
                'Unsupported driver for ' . $this->id() . ': ' . $this->driver()
            ),
        };
    }
}
