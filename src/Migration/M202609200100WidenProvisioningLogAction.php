<?php
/**
 * Laika Bill Manager
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: Proprietary - see LICENSE
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
use LBM\Schema\ProvisioningLogSchema;

/**
 * Phase 53: the provisioning log can record the new module operations.
 *
 * `provisioning_logs.action` shipped as an enum of the four lifecycle verbs.
 * Changing a package, setting a password, a single sign-on and a usage sync are
 * all calls to somebody's control panel, and the log is where staff look when
 * one goes wrong - so they are logged too, which the enum refused.
 *
 * The column's shape differs by engine, because laika-model writes an enum as
 * MySQL's ENUM but as `VARCHAR(255) CHECK (...)` elsewhere, and PostgreSQL
 * names that inline check `provisioning_logs_action_check`. So MySQL gets a
 * MODIFY with the full list and PostgreSQL gets its check replaced. The list is
 * ProvisioningLogSchema::ACTIONS either way, which is also what a fresh install
 * creates - so a fresh install baselines this and run() never happens there.
 */
class M202609200100WidenProvisioningLogAction extends MigrationAbstract
{
    /** @var string Ledger Key. Written once, never edited */
    protected string $id = '20260920_0100_widen_provisioning_log_action';

    /** @var string What This Does */
    protected string $description
        = 'Let the provisioning log record package changes, password changes, single sign-on and usage syncs.';

    /** @var string PostgreSQL's Name For The Inline Check */
    private const CHECK = 'provisioning_logs_action_check';

    /**
     * Whether This Install Needs It
     * @return bool
     */
    public function applies(): bool
    {
        if (!$this->hasTable('provisioning_logs') || !$this->hasColumn('provisioning_logs', 'action')) {
            return false;
        }

        $definition = $this->definition();

        // No definition at all means no enum and no check - nothing to widen.
        return $definition !== '' && !str_contains($definition, 'sync_usage');
    }

    /**
     * Widen The Column
     * @return void
     */
    public function run(): void
    {
        $values = implode(', ', array_map(
            static fn(string $v): string => "'" . $v . "'",
            ProvisioningLogSchema::ACTIONS
        ));

        match ($this->driver()) {
            'mysql' => $this->schema()->statement(
                "ALTER TABLE `provisioning_logs` MODIFY `action` ENUM({$values}) NOT NULL"
                . " COMMENT 'What was asked of the module'"
            ),
            'pgsql' => $this->schema()->statement(
                'ALTER TABLE "provisioning_logs" DROP CONSTRAINT IF EXISTS "' . self::CHECK . '", '
                . 'ADD CONSTRAINT "' . self::CHECK . '" CHECK ("action" IN (' . $values . '))'
            ),
            default => throw new RuntimeException(
                'Unsupported driver for ' . $this->id() . ': ' . $this->driver()
            ),
        };
    }

    /**
     * What The Database Says The Column Allows
     * @return string MySQL's COLUMN_TYPE, or PostgreSQL's check definition; '' when neither exists
     */
    private function definition(): string
    {
        $pdo = Connection::get($this->connection);

        if ($this->driver() === 'mysql') {
            $stmt = $pdo->prepare(
                'SELECT COLUMN_TYPE FROM information_schema.columns '
                . 'WHERE table_schema = ? AND table_name = ? AND column_name = ?'
            );
            $stmt->execute([(string) (Connection::config($this->connection)['database'] ?? ''), 'provisioning_logs', 'action']);

            $type = strtolower((string) $stmt->fetchColumn());

            return str_starts_with($type, 'enum(') ? $type : '';
        }

        $stmt = $pdo->prepare('SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = ?');
        $stmt->execute([self::CHECK]);

        return strtolower((string) $stmt->fetchColumn());
    }
}
