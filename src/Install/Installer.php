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
use LBM\Model\StaffModel;
use LBM\Model\StaffRoleModel;
use LBM\Model\CurrencyModel;
use LBM\Pipeline\Install as InstallGate;
use LBM\Service\Password;
use LBM\Service\Permission;
use LBM\Support\Permission as PermissionSupport;
use LBM\Support\Uid;

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
     * Create Every Table, Then Seed
     *
     * The in-process equivalent of `php laika app:migrate`. Foreign key checks
     * are disabled per schema because the tables are created in discovery order,
     * which is not dependency order.
     *
     * Re-runnable: every schema uses createIfNotExists and every seed is guarded
     * by a row count.
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

        return $results;
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

        $written = file_put_contents($lock, sprintf(
            "Installed %s\nVersion 1.0.0\n\nDelete this file to re-run the installer.\n",
            gmdate('Y-m-d H:i:s') . ' UTC'
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
        try {
            $schema = new $class();
            $on = Schema::on($schema->connection);

            // Tables are created in discovery order, which is not dependency
            // order, so a foreign key may point at a table that does not exist
            // yet.
            $on->disableForeignKeyChecks();
            $schema->{$method}();
            $on->enableForeignKeyChecks();

            return ['ok' => true, 'state' => $state, 'error' => ''];
        } catch (Throwable $e) {
            return ['ok' => false, 'state' => 'failed', 'error' => $e->getMessage()];
        }
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
        $existing = $model->select($model->id)->where(['role_name' => 'Superadmin'])->first();

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
            'role_name'   =>  'Superadmin',
            'permissions' =>  $permissions,
        ]);

        $row = (new StaffRoleModel())
            ->select($model->id)
            ->where(['role_name' => 'Superadmin'])
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
     * Both trees: laika-core compiles templates under TEMPLATE_CACHE_PATH,
     * which is not below lf-cache, so emptying that one alone would leave a
     * compiled view from a previous install in place.
     * @return void
     */
    private function clearCache(): void
    {
        foreach ([APP_PATH . '/lf-cache', TEMPLATE_CACHE_PATH] as $cache) {
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
