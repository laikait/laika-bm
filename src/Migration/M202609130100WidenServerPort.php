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
use Laika\Model\Connection;
use LBM\Contract\MigrationAbstract;

/**
 * The eighth real migration: Phase 42 lets a server listen on any port.
 *
 * `servers.port` shipped as a signed SMALLINT - at most 32767 - and MySQL's
 * default non-strict mode stores anything larger AS 32767, with no error. A
 * control panel on port 40000 was saved on the wrong port, and every call to it
 * went somewhere else. A port is 1 to 65535. INTEGER holds that on both engines;
 * PostgreSQL has no unsigned types, so an unsigned smallint is not an option.
 *
 * A TYPE CHANGE, not a new column, so applies() cannot ask hasColumn(): the
 * column is there either way. It asks the database's own catalogue for the
 * column's type. Schema::pdo() is private and laika-model has no column-type
 * reader, so the catalogue is read here - inside src/Migration, the one place
 * raw SQL is allowed.
 *
 * A port already stored as 32767 cannot be recovered: the value typed is gone,
 * and nothing here guesses at it.
 *
 * `ServerSchema` declares `integer`, so a fresh install baselines this and
 * `run()` is never called there - migrationwalk narrows the column back and
 * forces it on both engines.
 */
class M202609130100WidenServerPort extends MigrationAbstract
{
    /** @var string Ledger Key. Written once, never edited */
    protected string $id = '20260913_0100_widen_server_port';

    /** @var string What This Does */
    protected string $description
        = 'Widen servers.port from SMALLINT to INTEGER, so a server can be on any port.';

    /**
     * Whether This Install Needs It
     * @return bool
     */
    public function applies(): bool
    {
        return $this->hasTable('servers')
            && $this->hasColumn('servers', 'port')
            && $this->portType() === 'smallint';
    }

    /**
     * Widen The Column
     *
     * The default and NOT NULL are kept: MySQL's MODIFY restates them, and
     * PostgreSQL's ALTER COLUMN ... TYPE leaves both alone. statement() returns
     * false on success - DDL affects no rows - so nothing branches on it. A real
     * failure arrives as a PDOException, the ledger records nothing, and the
     * next update tries again.
     * @return void
     */
    public function run(): void
    {
        match ($this->driver()) {
            'mysql' => $this->schema()->statement(
                'ALTER TABLE `servers` MODIFY `port` INT NOT NULL DEFAULT 2083'
            ),
            'pgsql' => $this->schema()->statement(
                'ALTER TABLE "servers" ALTER COLUMN "port" TYPE INTEGER'
            ),
            default => throw new RuntimeException(
                'Unsupported driver for ' . $this->id() . ': ' . $this->driver()
            ),
        };
    }

    /**
     * The Column's Type, As The Catalogue Names It
     * @return string Lower case - 'smallint' before, 'int' or 'integer' after
     */
    private function portType(): string
    {
        $pdo = Connection::get($this->connection);

        if ($this->driver() === 'mysql') {
            $stmt = $pdo->prepare(
                'SELECT DATA_TYPE FROM information_schema.columns '
                . 'WHERE table_schema = ? AND table_name = ? AND column_name = ?'
            );
            $stmt->execute([(string) (Connection::config($this->connection)['database'] ?? ''), 'servers', 'port']);
        } else {
            $stmt = $pdo->prepare(
                'SELECT data_type FROM information_schema.columns '
                . 'WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?'
            );
            $stmt->execute(['servers', 'port']);
        }

        return strtolower((string) $stmt->fetchColumn());
    }
}
