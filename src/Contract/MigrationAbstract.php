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

namespace LBM\Contract;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Laika\Model\Connection;
use Laika\Model\Schema\Schema;

/**
 * One change to a database that already exists.
 *
 * ------------------------------------------------------------------------
 * Why this exists
 * ------------------------------------------------------------------------
 * Every schema's up() calls createIfNotExists, so migrate() creates a table
 * that is missing and does nothing whatsoever to a table that is present. A new
 * table therefore reaches an installation that already exists and a new or
 * changed column never does - and the update reports success either way, which
 * is the dangerous half. The operator finds out days later, through one broken
 * screen, with nothing connecting it to the button they pressed.
 *
 * A migration is the other half: a single change, applied once, recorded in the
 * `migrations` table so it is never applied twice.
 *
 * ------------------------------------------------------------------------
 * Why this class is in Contract/ and not Migration/
 * ------------------------------------------------------------------------
 * Resource::getClasses() runs is_subclass_of($class, $contract) over every
 * class it finds in the registered directory, and an abstract base is not a
 * subclass of itself. A base sitting in src/Migration would fail that check and
 * take the whole discovery pass down with it - not merely exclude itself. This
 * is the same reason SchemaAbstract lives in Laika\Model\Contract rather than
 * beside the schemas.
 *
 * ------------------------------------------------------------------------
 * Four rules, all of which have teeth
 * ------------------------------------------------------------------------
 * 1. Schema::statement() returns FALSE on success. It is
 *    `(bool) $pdo->exec($sql)` and PDO::exec() returns 0 for DDL, which is
 *    falsy. Never branch on its return value. A genuine failure arrives as a
 *    PDOException, which the runner catches and reports.
 *
 * 2. run() must be idempotent statement by statement. There is no transaction
 *    around it - MySQL commits implicitly on every DDL statement, so a
 *    transaction would be false comfort there, and relying on PostgreSQL's real
 *    transactional DDL would make the same migration safe on one engine and not
 *    the other. Guard each statement, because a two-statement run() that dies
 *    halfway leaves a half-changed database and no ledger row, and the next
 *    migrate starts it again from the top.
 *
 * 3. One logical change per class. That is what bounds how much rule 2 can cost
 *    you.
 *
 * 4. applies() must probe the thing being changed, not merely that it exists.
 *    hasColumn() cannot tell a varchar(50) from a varchar(255), so a widening
 *    migration guarded on hasColumn() answers "already done" the first time it
 *    is asked and then never runs anywhere.
 *
 * ------------------------------------------------------------------------
 * applies() is what makes a fresh install both cheap and correct
 * ------------------------------------------------------------------------
 * On a fresh install up() has already produced the current shape, so there is
 * nothing to do; the runner records the migration as `baselined` without ever
 * calling run(). On an existing install applies() answers true and it runs.
 *
 * The default is true - "assume it is needed" - because a data backfill often
 * cannot be probed, and the ledger already stops the second run. Override it
 * whenever the change CAN be probed. It costs one query, once per install.
 *
 * The case that makes this non-negotiable rather than a nicety is a table LBM
 * does not own. `auth_tokens` belongs to laika-auth, a separate composer
 * package this codebase cannot edit, so a change to it has to keep applying to
 * brand new installs until laika-auth itself catches up. A migration that
 * probes the live database gets that right for free and starts baselining by
 * itself afterwards, with no code change here and no version arithmetic
 * anywhere.
 *
 * ------------------------------------------------------------------------
 * Ids
 * ------------------------------------------------------------------------
 * `YYYYMMDD_HHMM_snake_slug`, so lexicographic order is chronological order and
 * the runner can sort on it. Write it once and never edit it: the id IS the
 * ledger key, so changing it re-runs the migration on every installation in the
 * world.
 *
 * Reading the database inside applies() is an ordinary model query - use
 * Laika\Model\Model against the driver's catalogue the way any other read is
 * done. Raw DDL through statement() is permitted in src/Migration and nowhere
 * else in the product, and bin/verify-stage.php enforces that at build time.
 */
abstract class MigrationAbstract
{
    /**
     * @var string This Migration's Permanent Identity
     *
     * Format YYYYMMDD_HHMM_snake_slug. Never edited after it has shipped.
     */
    protected string $id = '';

    /**
     * @var string One Line, In The Operator's Words
     *
     * Shown on the update screen before the migration runs, and copied into the
     * ledger when it does, so the history still reads properly after the class
     * itself has been deleted.
     */
    protected string $description = '';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    /**
     * @param ?string $connection Overrides the declared connection
     */
    public function __construct(?string $connection = null)
    {
        // A subclass that declared its own connection keeps it. SchemaAbstract
        // hard-codes 'default' at this point instead, which would discard it.
        $this->connection = $connection ?: $this->connection;
    }

    ####################################################################################
    /*================================= EXTERNAL API =================================*/
    ####################################################################################

    /**
     * Apply The Change
     * @return void
     */
    abstract public function run(): void;

    /**
     * Whether This Database Still Needs The Change
     *
     * False means it is already present - a fresh install, or somebody applied
     * it by hand - and the runner records it without running it.
     * @return bool
     */
    public function applies(): bool
    {
        return true;
    }

    /**
     * @return string The Ledger Key
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * @return string One-line Summary
     */
    public function description(): string
    {
        return $this->description;
    }

    /**
     * @return string Database Connection Name
     */
    public function connection(): string
    {
        return $this->connection;
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * The Schema Builder For This Migration's Connection
     * @return Schema
     */
    protected function schema(): Schema
    {
        return Schema::on($this->connection);
    }

    /**
     * The Canonical Driver Name
     *
     * Connection::driver() normalises mariadb to mysql and postgres to pgsql,
     * so a match on this covers the aliases. Throw on an unrecognised driver
     * rather than guessing a dialect - Schema::grammar() takes the same line,
     * and a guessed dialect fails at the worst possible moment.
     * @return string
     */
    protected function driver(): string
    {
        return Connection::driver($this->connection);
    }

    /**
     * @param string $table Table Name
     * @return bool
     */
    protected function hasTable(string $table): bool
    {
        return $this->schema()->hasTable($table);
    }

    /**
     * @param string $table Table Name
     * @param string $column Column Name
     * @return bool
     */
    protected function hasColumn(string $table, string $column): bool
    {
        return $this->schema()->hasColumn($table, $column);
    }

    /**
     * Check if Property is Set
     * @param string $prop Property Name
     * @return bool
     */
    public function __isset($prop): bool
    {
        return isset($this->$prop);
    }

    /**
     * Get Property Value
     * @param string $prop Property Name
     * @return mixed
     */
    public function __get($prop): mixed
    {
        return $this->$prop;
    }
}
