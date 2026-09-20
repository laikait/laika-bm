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

/**
 * The test suite's boot - Phase 51.
 *
 * Boots the real application, then points its `default` connection at a
 * THROWAWAY database, `lbm_test`, dropped and rebuilt by Installer::migrate() on
 * every run. Tests never see the development database, and a test whose
 * rollback fails leaves its rows in a database the next run deletes.
 *
 * That isolation is enforced, not assumed: before a single test runs, the
 * connection is asked which database it is on, and anything but lbm_test
 * stops the run.
 *
 * The app root is LBM_APP_ROOT, or the `cloud` app beside the package's own
 * checkout - the same default bin/release.php uses.
 */

$appRoot = rtrim((string) (getenv('LBM_APP_ROOT') ?: dirname(__DIR__, 3) . '/cloud'), '/');

if (!is_file($appRoot . '/lf-boot/app.php')) {
    fwrite(STDERR, "No app at {$appRoot}. Set LBM_APP_ROOT to the application root.\n");
    exit(2);
}

// Whatever the app would otherwise answer a request with - nothing here is one.
$_SERVER['HTTP_HOST'] ??= 'lbm.test';
$_SERVER['SCRIPT_NAME'] ??= '/index.php';
$_SERVER['REQUEST_URI'] ??= '/';

chdir($appRoot);
require $appRoot . '/lf-boot/app.php';

// Tests, autoloaded from tests/ under LBM\Tests\.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'LBM\\Tests\\')) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen('LBM\\Tests\\'))) . '.php';

        if (is_file($file)) {
            require $file;
        }
    }
});

use Laika\Model\Connection;
use Laika\Service\Config;

const LBM_TEST_DATABASE = 'lbm_test';

// ---------------------------------------------------------------------------
// A fresh database, from the application's own credentials.
// ---------------------------------------------------------------------------
$config = Config::get('database', 'default');

if (!is_array($config) || ($config['driver'] ?? 'mysql') !== 'mysql') {
    fwrite(STDERR, "The test suite needs a MySQL 'default' connection in lf-config/database.php.\n");
    exit(2);
}

$server = new PDO(
    sprintf('mysql:host=%s;port=%d', $config['host'] ?? 'localhost', (int) ($config['port'] ?? 3306)),
    (string) ($config['username'] ?? ''),
    (string) ($config['password'] ?? ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$server->exec('DROP DATABASE IF EXISTS `' . LBM_TEST_DATABASE . '`');
$server->exec('CREATE DATABASE `' . LBM_TEST_DATABASE . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

// Registered BEFORE anything else connects: Installer::connect() and
// Init::db() both keep a `default` that already exists.
//
// NOT Config::set(): laika-core's Config::set() WRITES lf-config/<name>.php to
// disk. It rewrote the application's database.php to point at the test
// database the first time this ran. The connection registry is enough, and
// the config file is fingerprinted below so nothing can do that again unseen.
$configFile = $appRoot . '/lf-config/database.php';
$configPrint = md5_file($configFile);

register_shutdown_function(static function () use ($configFile, $configPrint): void {
    clearstatcache();

    if (md5_file($configFile) !== $configPrint) {
        fwrite(STDERR, "\n!!! lf-config/database.php CHANGED during the test run. Check it now - the application may be pointed at the wrong database.\n");
        exit(3);
    }
});

$config['database'] = LBM_TEST_DATABASE;
Connection::add($config, 'default');

$on = (string) Connection::get('default')->query('SELECT DATABASE()')->fetchColumn();

if ($on !== LBM_TEST_DATABASE) {
    fwrite(STDERR, "Refusing to run: the test connection is on [{$on}], not [" . LBM_TEST_DATABASE . "].\n");
    exit(2);
}

// ---------------------------------------------------------------------------
// The schema, by the installer - the path a real install takes.
// ---------------------------------------------------------------------------
// option() first: laika-core creates `options` on first use, and on MySQL a
// CREATE TABLE commits any open transaction (plan U10). Doing it here means no
// test's transaction is ever the one it lands in.
option('app_name');

$failed = array_filter((new LBM\Install\Installer())->migrate(), static fn (array $r): bool => !$r['ok']);

if ($failed !== []) {
    foreach ($failed as $table => $result) {
        fwrite(STDERR, "Schema [{$table}] failed: {$result['error']}\n");
    }
    exit(2);
}

// The database queue driver's tables, for QueueRunner's tests - here, not in a
// test, because CREATE TABLE would commit that test's transaction (U10).
(new Laika\Queue\Schema\QueueModelSchema('default'))->up();
(new Laika\Queue\Schema\FailedJobModelSchema('default'))->up();

// One currency, with the columns the installer's settings step writes.
(new LBM\Model\CurrencyModel())->insert([
    'uid' => Laika\Service\Uid::make(), 'currency_code' => 'USD', 'exchange_rate' => '1.000000',
    'is_active' => 'yes', 'is_default' => 'yes', 'prefix_symbol' => '$', 'suffix_symbol' => '',
]);
