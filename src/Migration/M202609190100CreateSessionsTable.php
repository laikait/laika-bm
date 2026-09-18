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

use LBM\Contract\MigrationAbstract;
use Laika\Session\Schema\SessionSchema;

/**
 * The ninth real migration: Phase 49 gives an install its session table.
 *
 * GlobalPipeline::boot() puts sessions in the database with `install` false -
 * rightly, no DDL on every request - and laika-session does not register its
 * schema as a resource, so the installer's schema pass never saw it. An install
 * made before Phase 49 therefore has every table but `sessions`, and every
 * request that touches the session dies on "Table 'sessions' doesn't exist".
 *
 * Not a change to an existing table, which is what a migration usually is: a
 * table that should have been there from the start. It lives here because this
 * is the path an installed site already runs - the Utilities screen, and
 * `lbm:install --force` - and neither runs the schema pass again.
 *
 * A fresh install creates the table in Installer::migrate() before this runs,
 * so applies() is false there and it is baselined.
 */
class M202609190100CreateSessionsTable extends MigrationAbstract
{
    /** @var string Ledger Key. Written once, never edited */
    protected string $id = '20260919_0100_create_sessions_table';

    /** @var string What This Does */
    protected string $description
        = 'Create the sessions table, which installs made before Phase 49 never got.';

    /**
     * Whether This Install Needs It
     * @return bool
     */
    public function applies(): bool
    {
        return !$this->hasTable('sessions');
    }

    /**
     * Create The Table
     *
     * laika-session's own schema, on this migration's connection, so the table
     * is exactly the one SessionModel reads - never a second copy of its shape.
     * createIfNotExists, so a table that appeared in the meantime is left alone.
     * @return void
     */
    public function run(): void
    {
        (new SessionSchema($this->connection))->up();
    }
}
