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

namespace LBM\Install;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Throwable;
use Laika\Model\Schema\Schema;
use Laika\Model\Connection;
use Laika\Service\Config;
use Laika\Service\Infra;
use Laika\Service\Option;
use LBM\Contract\MigrationAbstract;
use LBM\Model\MigrationModel;
use LBM\Model\StaffModel;
use LBM\Model\StaffRoleModel;
use LBM\Model\CurrencyModel;
use LBM\Pipeline\Install as InstallGate;
use LBM\Service\Password;
use LBM\Service\Permission;
use LBM\Support\Permission as PermissionSupport;
use LBM\Support\Version;
use Laika\Service\Uid;

/**
 * The install engine.
 *
 * One class holds every step as a callable method, so the web wizard and the
 * CLI command drive the same code and cannot drift apart.
 *
 * Every step is idempotent and the wizard's position is derived from real state
 * - is the config written, do the tables exist, is there an admin account -
 * rather than from a counter in the session. That is what makes a refresh or a
 * back-button harmless.
 */
class Installer
{
    /** @var string Connection Name Used To Probe Credentials */
    public const PROBE = 'lbm_install_probe';

    /** @var string Option Key That Marks The Settings Step As Applied */
    public const SETTINGS_MARKER = 'app_email';

    /** @var string Credential Type For Staff Passwords */
    public const STAFF = 'staff';

    /**
     * @var array<string,array> What The Migration Pass Did In THIS Request
     *
     * Kept in memory as well as in the migration_report option, because a read
     * of that option in the same request that wrote it can return the previous
     * value - option() memoises per key for the whole request.
     */
    private array $lastRun = [];

    ####################################################################################
    /*=================================== STATE ======================================*/
    ####################################################################################

    /**
     * Whether The App Has Been Installed
     * @return bool
     */
    public function isInstalled(): bool
    {
        return InstallGate::isInstalled();
    }

    /**
     * Which Steps Are Already Done
     *
     * Derived from actual state, never from a session counter. Each check is
     * wrapped because an earlier step being incomplete makes the later ones
     * throw rather than return false - no database means no tables to count.
     * @return string[]
     */
    public function completed(): array
    {
        $done = [];

        if ((new Requirements())->passed()) {
            $done[] = 'requirements';
        }

        if (!$this->databaseReady()) {
            return $done;
        }

        $done[] = 'database';

        if (!$this->tablesExist()) {
            return $done;
        }

        $done[] = 'migrate';

        if ($this->settingsSaved()) {
            $done[] = 'settings';
        }

        if ($this->hasAdmin()) {
            $done[] = 'admin';
        }

        if ($this->isInstalled()) {
            $done[] = 'finish';
        }

        return $done;
    }

    /**
     * The Step The Operator Should Be On
     * @return string
     */
    public function currentStep(): string
    {
        $done = $this->completed();

        foreach (['requirements', 'database', 'migrate', 'settings', 'admin', 'finish'] as $step) {
            if (!in_array($step, $done, true)) {
                return $step;
            }
        }

        return 'finish';
    }

    /**
     * Whether The Configured Database Accepts a Connection
     * @return bool
     */
    public function databaseReady(): bool
    {
        try {
            $config = Config::get('database', 'default');

            if (empty($config) || empty($config['database'])) {
                return false;
            }

            return $this->testConnection($config) === null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Whether The Tables Have Been Created
     * @return bool
     */
    public function tablesExist(): bool
    {
        try {
            $this->connect();

            return Schema::on('default')->hasTable((new StaffModel())->table);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Whether The Settings Step Has Been Applied
     *
     * `app_email` is the marker because laika-core's OptionSchema does not seed
     * it - unlike app_name and time_zone, which arrive pre-filled and so cannot
     * distinguish "seeded" from "the operator chose this".
     * @return bool
     */
    public function settingsSaved(): bool
    {
        try {
            $this->connect();

            return !empty(Option::single(self::SETTINGS_MARKER));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Whether a Staff Account Exists
     * @return bool
     */
    public function hasAdmin(): bool
    {
        try {
            $this->connect();

            return (new StaffModel())->count() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    ####################################################################################
    /*================================== DATABASE ====================================*/
    ####################################################################################

    /**
     * Test Database Credentials
     *
     * Reports the driver's own message verbatim. "Access denied for user" and
     * "Unknown database" need different fixes, and paraphrasing them into
     * "could not connect" helps nobody.
     * @param array $config Connection Config
     * @return ?string Null when the connection works, otherwise the error
     */
    public function testConnection(array $config): ?string
    {
        try {
            // A named probe connection, so a bad attempt never replaces the
            // 'default' one a later step may still need.
            Connection::close(self::PROBE);
            Connection::add($config, self::PROBE);
            Schema::on(self::PROBE)->hasTable('lbm_probe_' . bin2hex(random_bytes(4)));

            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        } finally {
            try {
                Connection::close(self::PROBE);
            } catch (Throwable) {
                // Nothing to close - the connection never opened.
            }
        }
    }

    /**
     * Write lf-config/database.php
     * @param array $config Connection Config
     * @return void
     */
    public function writeDatabaseConfig(array $config): void
    {
        Config::set('database', 'default', $config);

        // The old handle, if any, still points at the previous database.
        try {
            Connection::close('default');
        } catch (Throwable) {
            // No connection was open.
        }
    }

    /**
     * Normalise Submitted Credentials
     * @param array $input Raw Input
     * @return array
     */
    public function databaseConfig(array $input): array
    {
        $driver = (string) ($input['db_driver'] ?? 'mysql');

        $config = [
            'driver'   =>  $driver,
            'host'     =>  trim((string) ($input['db_host'] ?? 'localhost')),
            'port'     =>  (int) ($input['db_port'] ?? 3306),
            'database' =>  trim((string) ($input['db_name'] ?? '')),
            'username' =>  (string) ($input['db_user'] ?? ''),
            'password' =>  (string) ($input['db_pass'] ?? ''),
        ];

        // SQLite is a file path, not a host and port - sending it either would
        // be meaningless and the driver ignores them anyway.
        if ($driver === 'sqlite') {
            return ['driver' => 'sqlite', 'database' => $config['database']];
        }

        return $config;
    }

    ####################################################################################
    /*=================================== MIGRATE ====================================*/
    ####################################################################################

    /**
     * Create Every Table, Seed It, Then Apply Any Outstanding Change
     *
     * The in-process equivalent of `php laika app:migrate`, and then some: the
     * CLI command has no third pass and knows nothing about LBM migrations, so
     * anything driving this feature must come through here.
     *
     * Foreign key checks are disabled per schema in pass one because the tables
     * are created in discovery order, which is not dependency order.
     *
     * Re-runnable: every schema uses createIfNotExists, every seed is guarded by
     * a row count, and every migration is recorded in the `migrations` table so
     * it is applied exactly once.
     *
     * ------------------------------------------------------------------------
     * The return shape is load-bearing - do not widen it
     * ------------------------------------------------------------------------
     * Four callers index ['ok'], ['state'] and ['error'], and
     * InstallController::migrate() does a bare !$r['ok'] with no null coalesce.
     * The framework's error handler promotes an undefined-array-key warning to
     * an ErrorException, so an entry missing a key is a fatal rather than a
     * missing badge on the wizard.
     *
     * Migration results therefore come back from runMigrations() separately and
     * only FAILURES are merged in here, under a key that cannot collide with a
     * table name. Successes would otherwise add a row per migration to the
     * wizard's table on every fresh install, all of them saying "nothing to do".
     * @return array<string,array{ok:bool,state:string,error:string}>
     */
    public function migrate(): array
    {
        $this->connect();

        $schemas = Infra::getSchemaClasses();
        $results = [];

        // Pass one: structure.
        foreach ($schemas as $table => $class) {
            $results[$table] = $this->step($class, 'up', 'created');
        }

        // Pass two: data. Separate, because a seed may reference a table that a
        // later schema in the same loop would not yet have created.
        foreach ($schemas as $table => $class) {
            if (!$results[$table]['ok']) {
                continue;
            }

            $seed = $this->step($class, 'seed', 'seeded');

            if (!$seed['ok']) {
                $results[$table] = $seed;
            }
        }

        // Pass three: changes to tables that already existed. After the seeds
        // and not between the two passes, because a migration may need to read
        // or rewrite seeded data.
        foreach ($this->runMigrations() as $id => $result) {
            if ($result['ok']) {
                continue;
            }

            // A space and a colon are not legal in a table identifier on any
            // supported engine, so this cannot shadow a real schema result.
            $results['migration: ' . $id] = [
                'ok'    =>  false,
                'state' =>  'failed',
                'error' =>  $result['error'],
            ];
        }

        return $results;
    }

    /**
     * Apply Every Outstanding Migration
     *
     * A migration is a change to a table that already exists - the one thing
     * up() cannot do, because createIfNotExists sees the table is present and
     * returns without looking inside it.
     *
     * For each discovered migration not already in the ledger, in id order:
     *
     *   applies() false  -> recorded `baselined`, run() is never called. This is
     *                       the fresh-install path: up() already produced the
     *                       current shape, so there is nothing to do.
     *   applies() true   -> run(), then recorded `applied`.
     *   either throws    -> reported, NOTHING recorded, so the next migrate
     *                       tries again.
     *
     * Deliberately not routed through step(). That helper returns a fixed
     * three-key array and, more importantly, its enableForeignKeyChecks() is not
     * in a finally - a throwing step leaves foreign key checks off on the same
     * PDO handle every later query in the request uses. Tolerable while creating
     * tables during an install; not something to extend over DDL against live
     * data. This pass does not disable them at all: a migration alters a table
     * whose referents already exist, so there is nothing to defer.
     *
     * @return array<string,array{ok:bool,state:string,error:string,description:string}>
     */
    public function runMigrations(): array
    {
        $this->connect();

        $results = [];

        // Never attempt a migration that cannot be recorded. Without the ledger
        // there is nothing to stop the next run applying the same change again,
        // and "applied twice" is worse than "not applied yet".
        if (!Schema::on('default')->hasTable('migrations')) {
            return ['migrations table' => [
                'ok'          =>  false,
                'state'       =>  'failed',
                'error'       =>  'The migrations table does not exist, so nothing can be recorded. '
                                      . 'Run the migration again - the pass that creates it runs first.',
                'description' =>  '',
            ]];
        }

        try {
            $migrations = $this->migrationClasses();
        } catch (Throwable $e) {
            return ['discovery' => [
                'ok'          =>  false,
                'state'       =>  'failed',
                'error'       =>  $e->getMessage(),
                'description' =>  '',
            ]];
        }

        $applied = $this->appliedMigrations();

        foreach ($migrations as $id => $migration) {
            if (isset($applied[$id])) {
                continue;
            }

            $results[$id] = $this->runMigration($migration);
        }

        $this->recordRun($results);

        return $this->lastRun = $results;
    }

    /**
     * What The Migration Pass In This Request Actually Did
     *
     * Read this rather than the migration_report option when you are still in
     * the request that ran the migration. option() memoises per key for the
     * whole request, so a read after a write in the same process can return the
     * value from before it - which is why every settings save in this
     * application ends in a redirect rather than a re-render.
     * @return array<string,array{ok:bool,state:string,error:string,description:string}>
     */
    public function lastMigrationRun(): array
    {
        return $this->lastRun;
    }

    /**
     * Everything Discovered That Has Not Been Recorded Yet
     *
     * Drives the update screen, so an operator can see what a migration will do
     * before pressing the button rather than afterwards.
     *
     * When the ledger table does not exist - every installation that has not yet
     * migrated since this feature shipped - everything is reported as pending,
     * because that is the truth.
     * @return array<string,string> Migration Id => Description
     */
    public function pendingMigrations(): array
    {
        $this->connect();

        try {
            $migrations = $this->migrationClasses();
        } catch (Throwable) {
            // Discovery is broken - a duplicate id, or a class that will not
            // load. Returning an empty list is correct here (there is nothing
            // this method can honestly name), but on its own it would render as
            // "nothing pending", which is the opposite of the truth. The screen
            // pairs this with migrationProblem() so the operator is told.
            return [];
        }

        $applied = Schema::on('default')->hasTable('migrations')
            ? $this->appliedMigrations()
            : [];

        $pending = [];

        foreach ($migrations as $id => $migration) {
            if (isset($applied[$id])) {
                continue;
            }

            $pending[$id] = $migration->description();
        }

        return $pending;
    }

    /**
     * Why Migration Discovery Cannot Run, If It Cannot
     *
     * Separate from pendingMigrations() on purpose. That method answers "what is
     * outstanding" and can only honestly answer "nothing I can name" when
     * discovery itself is broken - but a screen showing an empty pending list
     * says "you are up to date", which is precisely the wrong thing to tell
     * somebody whose migrations will not load. This is the other half, so the
     * screen can say what is actually wrong.
     *
     * Only a packaging fault reaches here: two migrations sharing an id, one
     * with no id at all, or a class that will not load.
     * @return ?string
     */
    public function migrationProblem(): ?string
    {
        try {
            $this->migrationClasses();

            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * How Many Schemas Will Be Migrated
     * @return int
     */
    public function schemaCount(): int
    {
        return count(Infra::getSchemaClasses());
    }

    ####################################################################################
    /*=================================== OPTIONS ====================================*/
    ####################################################################################

    /**
     * Seed LBM's Option Rows From The Config Files
     *
     * lf-config/app.php and lf-config/mail.php are read once, here, and never
     * again - after this, every setting is read from `options` through option()
     * (instruction 14). They survive only so a deployer can pre-fill SMTP
     * credentials in a file before running the wizard.
     * @return void
     */
    public function seedOptions(): void
    {
        $this->connect();

        $app = $this->config('app');
        $mail = $this->config('mail');

        foreach ($this->defaults($app, $mail) as $key => $value) {
            $this->put($key, $value);
        }
    }

    /**
     * Apply The Operator's Settings
     * @param array $input Submitted Settings
     * @return void
     */
    public function saveSettings(array $input): void
    {
        $this->connect();

        $settings = [
            'app_name'         =>  trim((string) ($input['app_name'] ?? '')),
            'app_host'         =>  rtrim(trim((string) ($input['app_host'] ?? '')), '/'),
            'app_email'        =>  trim((string) ($input['app_email'] ?? '')),
            'time_zone'        =>  (string) ($input['time_zone'] ?? 'UTC'),
            'date_format'      =>  (string) ($input['date_format'] ?? 'Y-m-d'),
            'datetime_format'  =>  (string) ($input['datetime_format'] ?? 'Y-m-d H:i'),
            'default_currency' =>  strtoupper((string) ($input['default_currency'] ?? 'USD')),
        ];

        foreach ($settings as $key => $value) {
            if ($value !== '') {
                $this->put($key, $value);
            }
        }

        // The chosen currency has to become the one every exchange rate is
        // quoted against, or conversions run against a currency nobody picked.
        $this->makeCurrencyDefault($settings['default_currency']);
    }

    /**
     * Write an Option, Whether or Not It Exists
     *
     * Option::insert() refuses a key that exists and Option::update() refuses
     * one that does not, so neither alone is enough. This matters more than it
     * looks: laika-core's OptionSchema pre-seeds app_name as 'Laika Framework'
     * and time_zone as the server's timezone, so an insert-only save would
     * silently discard the operator's company name and the UTC default.
     *
     * Update is tried FIRST, and the order is not interchangeable.
     * Option::insert() decides whether a key exists with `if ($this->single($key))`
     * - a truthiness test, not an existence one - so a key already stored with an
     * empty string looks absent to it and it runs a second INSERT, which dies on
     * the primary key. Option::update() tests existence properly with a where()
     * lookup, so leading with it is correct for both cases.
     * @param string $key Option Key
     * @param mixed $value Value. Booleans are stored as 'true'/'false'
     * @return void
     */
    public function put(string $key, mixed $value): void
    {
        // option_bool() matches the literal string 'true' and nothing else -
        // 1, 'yes' and 'on' all read back as false.
        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        }

        if (!Option::update($key, $value)) {
            Option::insert($key, $value);
        }
    }

    ####################################################################################
    /*==================================== ADMIN =====================================*/
    ####################################################################################

    /**
     * Create The First Staff Account
     *
     * Role, staff row and password in one transaction: a half-created admin -
     * a staff row with no password, or a password with no staff - would lock
     * the operator out of the app they just installed.
     *
     * No account ships in a seed. The schemas used to create a superadmin with
     * a known username and password, which meant every install started with a
     * publicly known credential.
     * @param array $input first_name, last_name, email, username, password
     * @return int The new staff id
     * @throws \InvalidArgumentException When the password fails the rules
     */
    public function createAdmin(array $input): int
    {
        $this->connect();

        $password = (string) ($input['password'] ?? '');
        $errors = Password::validate($password, $input['password_confirm'] ?? null);

        if ($errors !== []) {
            throw new \InvalidArgumentException(implode(' ', $errors));
        }

        $roleId = $this->superadminRole();
        $staff = new StaffModel();

        return (int) $staff->transaction(function (StaffModel $m) use ($input, $roleId): int {
            $m->insert([
                'uid'          =>  Uid::make(),
                'role_relid'   =>  $roleId,
                'first_name'   =>  trim((string) ($input['first_name'] ?? '')),
                'middle_name'  =>  '',
                'last_name'    =>  trim((string) ($input['last_name'] ?? '')),
                'username'     =>  trim((string) ($input['username'] ?? '')),
                'email'        =>  trim((string) ($input['email'] ?? '')),
                'status_relid' =>  $this->activeStaffStatus(),
                'is_restricted' => 'no',
            ]);

            $row = $m->select($m->id)
                ->where(['username' => trim((string) ($input['username'] ?? ''))])
                ->order($m->id, 'DESC')
                ->first();

            $id = (int) ($row[$m->id] ?? 0);

            if ($id === 0) {
                throw new \RuntimeException('The staff account was not created.');
            }

            // Credentials live in the shared `passwords` table keyed by
            // (rel_id, rel_type), not on staffs - staff, clients and contacts
            // all authenticate through one path.
            Password::put($id, self::STAFF, (string) ($input['password'] ?? ''));

            return $id;
        });
    }

    ####################################################################################
    /*==================================== FINISH ====================================*/
    ####################################################################################

    /**
     * Close The Installer
     *
     * The lock is a file rather than an option row because LBM\Pipeline\Install
     * consults it before the database is known to exist - which is the whole
     * situation it was invented for.
     * @return void
     */
    public function finish(): void
    {
        // Refuse to close the installer on an install nobody can sign in to.
        // The lock is what makes /install redirect away, so locking without an
        // administrator would leave the operator with an admin panel they
        // cannot reach and no wizard to fix it.
        if (!$this->hasAdmin()) {
            throw new \RuntimeException(
                'Create an administrator account before finishing the installation.'
            );
        }

        $lock = APP_PATH . InstallGate::LOCK;
        $dir = dirname($lock);

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Could not create [{$dir}] to write the install lock.");
        }

        // Version::CURRENT, not a literal. This said "Version 1.0.0" while the
        // product was at 1.1.0 - harmless, since nothing parses this file
        // (isInstalled() is an is_file() check), but it is a lie in one of the
        // few files an operator opens when they are already having a bad day.
        $written = file_put_contents($lock, sprintf(
            "Installed %s\nVersion %s\n\nDelete this file to re-run the installer.\n",
            gmdate('Y-m-d H:i:s') . ' UTC',
            Version::CURRENT
        ));

        if ($written === false) {
            throw new \RuntimeException("Could not write the install lock at [{$lock}].");
        }

        $this->clearCache();
    }

    /**
     * Remove The Lock, So The Installer Can Run Again
     * @return void
     */
    public function unlock(): void
    {
        $lock = APP_PATH . InstallGate::LOCK;

        if (is_file($lock)) {
            unlink($lock);
        }
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Open The Default Connection If One Is Configured
     *
     * Public because the wizard needs it on plain GETs too. GlobalPipeline runs
     * in its uninstalled mode for the whole of the install - the lock is not
     * written until the last step - so it never calls Init::db(), and a step
     * that reads an option would otherwise fail with "No connection config
     * registered for [default]".
     * @return bool Whether a connection is available
     */
    public function connect(): bool
    {
        try {
            if (Connection::has('default')) {
                return true;
            }

            $config = Config::get('database', 'default');

            if (empty($config) || empty($config['database'])) {
                return false;
            }

            Connection::add($config);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Run One Schema Method And Shape The Result
     * @param string $class Schema Class
     * @param string $method 'up' or 'seed'
     * @param string $state State Word On Success
     * @return array{ok:bool,state:string,error:string}
     */
    private function step(string $class, string $method, string $state): array
    {
        $on = null;

        try {
            $schema = new $class();
            $on = Schema::on($schema->connection);

            // Tables are created in discovery order, which is not dependency
            // order, so a foreign key may point at a table that does not exist
            // yet.
            $on->disableForeignKeyChecks();
            $schema->{$method}();

            return ['ok' => true, 'state' => $state, 'error' => ''];
        } catch (Throwable $e) {
            return ['ok' => false, 'state' => 'failed', 'error' => $e->getMessage()];
        } finally {
            // In a finally, because this used to sit on the happy path only - so
            // one throwing schema left foreign key checks OFF for the rest of the
            // request, on the same PDO handle every later query uses. A silent
            // loss of referential integrity is a bad way to be told a schema
            // failed, and the failure message above is a good one.
            if ($on !== null) {
                try {
                    $on->enableForeignKeyChecks();
                } catch (Throwable) {
                    // The connection is already gone. There is nothing left to
                    // re-enable, and throwing here would replace the real error.
                }
            }
        }
    }

    /**
     * Every Migration Class, Instantiated, Keyed And Ordered By Id
     *
     * Discovery order is Infra::get()'s sort() over fully qualified class names,
     * which is alphabetical by class name - a different order from the ids, and
     * not a contract anything should lean on. So they are re-sorted here on the
     * id itself, which is why the id format is dated: ksort on
     * YYYYMMDD_HHMM_slug is chronological order.
     * @return array<string,MigrationAbstract> Id => Migration
     * @throws RuntimeException When two migrations share an id
     */
    private function migrationClasses(): array
    {
        $found = [];

        foreach (Infra::get('migrations', MigrationAbstract::class) as $class) {
            /** @var MigrationAbstract $migration */
            $migration = new $class();
            $id = $migration->id();

            if ($id === '') {
                throw new \RuntimeException("Migration [{$class}] does not declare an id.");
            }

            // Caught before anything runs rather than left to the unique key,
            // which would only complain AFTER the first of the pair had already
            // applied - leaving a database in a state no id explains. Two
            // branches merging on the same timestamp is a real accident.
            if (isset($found[$id])) {
                throw new \RuntimeException(
                    "Two migrations share the id [{$id}]: [" . get_class($found[$id]) . "] and [{$class}]."
                );
            }

            $found[$id] = $migration;
        }

        ksort($found);

        return $found;
    }

    /**
     * Ids Already In The Ledger
     * @return array<string,true>
     */
    private function appliedMigrations(): array
    {
        $ids = [];

        foreach ((new MigrationModel())->pluck('migration_key') as $key) {
            $ids[(string) $key] = true;
        }

        return $ids;
    }

    /**
     * Apply One Migration And Record It
     *
     * Nothing is recorded when it throws, which is what makes the next migrate
     * try again. The driver's message is passed through verbatim - a migration
     * fails for a reason somebody has to act on, and a tidied-up message is a
     * message that has had the actionable part removed.
     * @param MigrationAbstract $migration
     * @return array{ok:bool,state:string,error:string,description:string}
     */
    private function runMigration(MigrationAbstract $migration): array
    {
        $description = $migration->description();

        try {
            // False means the change is already present - a fresh install, or
            // somebody applied it by hand. Recorded, never run.
            $state = $migration->applies() ? 'applied' : 'baselined';

            if ($state === 'applied') {
                $migration->run();
            }

            (new MigrationModel())->insert([
                'migration_key'   =>  $migration->id(),
                'state'           =>  $state,
                'description'     =>  $description,
                'product_version' =>  Version::CURRENT,
                'ran_at'          =>  $this->now(),
            ]);

            return ['ok' => true, 'state' => $state, 'error' => '', 'description' => $description];
        } catch (Throwable $e) {
            return [
                'ok'          =>  false,
                'state'       =>  'failed',
                'error'       =>  $e->getMessage(),
                'description' =>  $description,
            ];
        }
    }

    /**
     * Store What The Last Run Did, For The Update Screen
     *
     * attempt() redirects and a flash cannot carry structure, so the report goes
     * through the options table and is read back on the next request - the same
     * route UtilController::check() already takes for the version it finds.
     *
     * schema_version is written only when nothing failed, so it can never claim
     * a version the database did not actually reach. When it disagrees with
     * Version::CURRENT the operator has updated the files and not yet run the
     * migration, which is exactly the state that produces "the update said it
     * worked and the screen is broken".
     * @param array<string,array> $results
     * @return void
     */
    private function recordRun(array $results): void
    {
        try {
            $failed = 0;

            foreach ($results as $result) {
                if (!($result['ok'] ?? false)) {
                    $failed++;
                }
            }

            $this->put('migration_report', (string) json_encode($results));
            $this->put('migration_ran_at', $this->now());

            if ($failed === 0) {
                $this->put('schema_version', Version::CURRENT);
            }
        } catch (Throwable) {
            // A report that cannot be stored must never fail a migration that
            // succeeded. The screen falls back to showing nothing.
        }
    }

    /**
     * @return string Now, As The Database Wants It
     */
    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * Read a Config File, Tolerating Its Absence
     * @param string $name Config Name
     * @return array
     */
    private function config(string $name): array
    {
        try {
            $config = Config::get($name);

            return is_array($config) ? $config : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Every Option LBM Seeds, With Its Default
     * @param array $app lf-config/app.php
     * @param array $mail lf-config/mail.php
     * @return array<string,mixed>
     */
    private function defaults(array $app, array $mail): array
    {
        return [
            // App - instruction 11 puts the clock on UTC, not the server's zone.
            'app_name'            =>  $app['name'] ?? 'Laika Bill Manager',
            'app_host'            =>  rtrim((string) ($app['url'] ?? ''), '/'),
            'app_email'           =>  $app['email'] ?? '',
            'app_logo'            =>  'logo.png',
            'app_icon'            =>  'icon.png',
            'time_zone'           =>  'UTC',

            // Template
            'front_template'      =>  'bootstrap',
            'admin_template'      =>  'bootstrap',
            'panel_template'      =>  'bootstrap',

            // Locale and format
            'default_language'    =>  'en',
            'date_format'         =>  'Y-m-d',
            'datetime_format'     =>  'Y-m-d H:i',
            'time_format'         =>  'H:i',
            'data_limit'          =>  20,
            'decimal_symbol'      =>  '.',
            'thousand_separator'  =>  ',',
            'default_currency'    =>  'USD',

            // Billing. The three *_prefix keys are what LBM\Action\Invoice,
            // Order and Support build their document numbers from.
            'invoice_prefix'      =>  'INV-',
            'order_prefix'        =>  'ORD-',
            'ticket_prefix'       =>  'TKT-',
            'invoice_due_days'    =>  14,
            'late_fee_percent'    =>  '0',

            // How far ahead InvoiceGenerateJob raises renewals, and which days
            // InvoiceReminderJob chases on - offsets relative to the due date,
            // so -7 is a week before and 3 is three days after.
            'invoice_generate_days' =>  14,
            'invoice_reminder_days' =>  '-7,0,3',

            // Security. Booleans are strings - option_bool() matches only 'true'.
            'login_lifetime'      =>  3600,
            'password_min_length' =>  8,
            'strict_ip'           =>  'false',
            'allow_registration'  =>  'false',

            // Mail. These key names map 1:1 onto Laika\Mailman\Mailer's
            // constructor array, so MailerFactory is a straight rename.
            'mail_driver'         =>  $mail['driver'] ?? 'smtp',
            'mail_host'           =>  $mail['host'] ?? 'localhost',
            'mail_port'           =>  $mail['port'] ?? 587,
            'mail_username'       =>  $mail['username'] ?? '',
            'mail_password'       =>  $mail['password'] ?? '',
            'mail_encryption'     =>  $mail['encryption'] ?? 'tls',
            'mail_from'           =>  $mail['from'] ?? '',
            'mail_from_name'      =>  $mail['from_name'] ?? '',
            'mail_charset'        =>  $mail['charset'] ?? 'UTF-8',
            'mail_timeout'        =>  $mail['timeout'] ?? 30,
            'mail_debug'          =>  $mail['debug'] ?? 0,
            'mail_keepalive'      =>  $this->boolString($mail['keepalive'] ?? false),
            'mail_auto_tls'       =>  $this->boolString($mail['auto_tls'] ?? true),
            'mail_validate_cert'  =>  $this->boolString($mail['validate_cert'] ?? true),
        ];
    }

    /**
     * Render a Config Boolean The Way option_bool() Reads It
     * @param mixed $value Value
     * @return string
     */
    private function boolString(mixed $value): string
    {
        return $value ? 'true' : 'false';
    }

    /**
     * Find or Create The Superadmin Role
     * @return int Role Id
     */
    private function superadminRole(): int
    {
        $model = new StaffRoleModel();
        $existing = $model->select($model->id)->where(['role_name' => 'superadmin'])->first();

        if (!empty($existing)) {
            return (int) $existing[$model->id];
        }

        // Encoded here, not left to the model. `$casts` is a read-side feature:
        // Model::cast() decodes a fetched row, but Model::insert() binds
        // array_values($row) straight into the statement, so handing it an array
        // is an "Array to string conversion" rather than a JSON column.
        $permissions = json_encode(
            Permission::grantAll(PermissionSupport::GROUPS),
            JSON_THROW_ON_ERROR
        );

        $model->insert([
            'uid'         =>  Uid::make(),
            'role_name'   =>  'superadmin',
            'permissions' =>  $permissions,
        ]);

        $row = (new StaffRoleModel())
            ->select($model->id)
            ->where(['role_name' => 'superadmin'])
            ->first();

        return (int) ($row[$model->id] ?? 0);
    }

    /**
     * The Id Of The 'active' Staff Status
     * @return int
     */
    private function activeStaffStatus(): int
    {
        return status_id('staff_statuses', 'active') ?? 1;
    }

    /**
     * Flag One Currency As The Default
     * @param string $code ISO Code
     * @return void
     */
    private function makeCurrencyDefault(string $code): void
    {
        $model = new CurrencyModel();
        $row = $model->select($model->id)->where(['currency_code' => $code])->first();

        if (empty($row)) {
            $model->transaction(function (CurrencyModel $m) use ($code): void {
                $system_currencies = system_currencies();

                $m->where(['is_default' => 'yes'])->update(['is_default' => 'no']);

                // Every NOT NULL column with no default has to be named here.
                // This goes through the model directly rather than through
                // Action\Currency, so none of that class's normalisation runs -
                // and `currencies` has no seed, so this insert is the only way
                // the first currency is ever made.
                //
                // Two columns were missing, and MySQL hid both: a non-strict
                // server silently substitutes '' for a NOT NULL column with no
                // default, so the install "worked" while writing a currency with
                // a blank uid. That is not benign - `uid` is UNIQUE, so the
                // second currency written this way collides on the empty string.
                // PostgreSQL refuses the NULL and the install stops at step 4.
                //
                // suffix_symbol is deliberately '' rather than null: the column
                // is NOT NULL and a currency that only has a prefix genuinely
                // has no suffix. Action\Currency::normalise() does the same.
                $m->insert([
                    'uid'           => Uid::make(),
                    'currency_code' => $code,
                    'exchange_rate' => '1.000000',
                    'is_active'     => 'yes',
                    'is_default'    => 'yes',
                    'prefix_symbol' => $system_currencies[$code] ?? '$',
                    'suffix_symbol' => '',
                ]);
            });

            return;
        }

        $model->transaction(function (CurrencyModel $m) use ($row, $code): void {
            // Exactly one default, or exchange rates have no single base.
            $m->where(['is_default' => 'yes'])->update(['is_default' => 'no']);
            $m->where(['currency_code' => $code])->update(['is_default' => 'yes', 'exchange_rate' => '1.000000']);
        });
    }

    /**
     * Clear Compiled Templates and Cached Config
     *
     * Three trees: laika-core compiles templates under TEMPLATE_CACHE_PATH,
     * which is not below lf-cache, so emptying that one alone would leave a
     * compiled view from a previous install in place.
     *
     * lf-storage/cache is the parent of TEMPLATE_CACHE_PATH and also holds the
     * module loader's generated file and, in principle, Resource's compiled
     * manifest. Emptying the parent is belt-and-braces rather than a fix for
     * anything live: the manifest cannot hide a newly shipped class from LBM,
     * because Resource::register() unsets the resolved entry for every name
     * helpers/loader.php registers and forces a live scan of it. Nor can a
     * manifest reach an operator - verify-stage.php blocks a release whose
     * lf-storage/cache has contents, and app:cache, the only thing that writes
     * one, is not shipped. Clearing the module file is safe on the same terms:
     * GlobalPipeline rebuilds it when it is absent, costing one request of lag,
     * and at this point in an install there are no modules enabled anyway.
     * @return void
     */
    private function clearCache(): void
    {
        foreach ([APP_PATH . '/lf-cache', STORAGE_PATH . DS . 'cache'] as $cache) {
            $this->emptyDirectory($cache);
        }
    }

    /**
     * Delete Everything Below a Directory, Keeping The Directory
     * @param string $directory Directory Path
     * @return void
     */
    private function emptyDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
    }
}
