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

namespace LBM\Command;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Throwable;
use Laika\Cli\Command\Argument;
use Laika\Cli\Command\Message;
use Laika\Cli\Contracts\CommandInterface;
use LBM\Install\Installer;
use LBM\Install\Requirements;

/**
 * Unattended install: php laika lbm:install
 *
 * Drives the same LBM\Install\Installer the web wizard does, so the two cannot
 * diverge. Exists for deployments where nobody is going to click through six
 * screens - a container image, a provisioning script, a CI fixture.
 */
class InstallCommand implements CommandInterface
{
    /**
     * Command Signature
     * @return string
     */
    public function signature(): string
    {
        return 'lbm:install';
    }

    /**
     * Command Name
     * @return string
     */
    public function command(): string
    {
        return 'lbm:install';
    }

    /**
     * Help Lines
     * @return array
     */
    public function help(): array
    {
        return [
            'signature'   =>  $this->signature(),
            'description' =>  'Install Laika Bill Manager without the web wizard',
            'command'     =>  $this->command(),
            'inputs'      =>  [],
            'params'      =>  [
                'db-driver'   =>  'Database driver: mysql, pgsql, sqlite or sqlsrv (default: mysql)',
                'db-host'     =>  'Database host (default: localhost)',
                'db-port'     =>  'Database port (default: 3306)',
                'db-name'     =>  'Database name — required',
                'db-user'     =>  'Database username (default: root)',
                'db-pass'     =>  'Database password',
                'app-name'    =>  'Company name',
                'app-url'     =>  'Application URL, e.g. https://billing.example.com',
                'app-email'   =>  'Billing email address — required',
                'timezone'    =>  'Timezone (default: UTC)',
                'currency'    =>  'Default currency ISO code (default: USD)',
                'admin-first' =>  'Administrator first name',
                'admin-last'  =>  'Administrator last name',
                'admin-user'  =>  'Administrator username — required',
                'admin-email' =>  'Administrator email address — required',
                'admin-pass'  =>  'Administrator password — required',
                'force'       =>  'Remove the install lock and run again',
            ],
        ];
    }

    /**
     * Run The Install
     * @param array $args Arguments
     * @param string $basePath App Root
     * @return int Exit code
     */
    public function handle(array $args, string $basePath): int
    {
        $installer = new Installer();
        $force = Argument::getBool('force', $args);

        if ($installer->isInstalled() && !$force) {
            Message::error('Already installed. Pass --force to remove the lock and run again.');
            return 1;
        }

        if ($force) {
            $installer->unlock();
            Message::info('Install lock removed.');
        }

        try {
            return $this->run($installer, $args);
        } catch (Throwable $e) {
            Message::error($e->getMessage());
            return 1;
        }
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Every Step, In Order
     * @param Installer $installer Installer
     * @param array $args Arguments
     * @return int
     */
    private function run(Installer $installer, array $args): int
    {
        /* ---- 1. Requirements ------------------------------------------- */
        $requirements = (new Requirements())->all();

        if (!$requirements['passed']) {
            Message::error("{$requirements['failed']} requirement(s) not met:");

            foreach (['php', 'extensions', 'paths'] as $group) {
                foreach ($requirements[$group] as $item) {
                    if ($item['required'] && !$item['ok']) {
                        echo '    ' . Message::txt_red('x') . " {$item['label']} — {$item['state']}\n";
                    }
                }
            }

            return 1;
        }

        Message::success('Requirements met.');

        /* ---- 2. Database ----------------------------------------------- */
        $config = $installer->databaseConfig([
            'db_driver' => Argument::getValue('db-driver', $args, 'mysql'),
            'db_host'   => Argument::getValue('db-host', $args, 'localhost'),
            'db_port'   => Argument::getValue('db-port', $args, 3306),
            'db_name'   => Argument::getValue('db-name', $args),
            'db_user'   => Argument::getValue('db-user', $args, 'root'),
            'db_pass'   => Argument::getValue('db-pass', $args, ''),
        ]);

        if (($config['database'] ?? '') === '') {
            Message::error('--db-name is required.');
            return 1;
        }

        $error = $installer->testConnection($config);

        if ($error !== null) {
            Message::error("Database connection failed: {$error}");
            return 1;
        }

        $installer->writeDatabaseConfig($config);
        Message::success("Connected to [{$config['database']}] and wrote lf-config/database.php.");

        /* ---- 3. Tables ------------------------------------------------- */
        $results = $installer->migrate();
        $failed = array_filter($results, static fn(array $r): bool => !$r['ok']);

        if ($failed !== []) {
            Message::error(count($failed) . ' of ' . count($results) . ' tables failed:');

            foreach ($failed as $table => $result) {
                echo '    ' . Message::txt_red('x') . " {$table} — {$result['error']}\n";
            }

            return 1;
        }

        Message::success(count($results) . ' tables created and seeded.');

        /* ---- 4. Settings ----------------------------------------------- */
        $installer->seedOptions();

        $email = (string) Argument::getValue('app-email', $args, '');

        if ($email === '') {
            Message::error('--app-email is required.');
            return 1;
        }

        $installer->saveSettings([
            'app_name'         => Argument::getValue('app-name', $args, 'Laika Bill Manager'),
            'app_host'         => Argument::getValue('app-url', $args, ''),
            'app_email'        => $email,
            'time_zone'        => Argument::getValue('timezone', $args, 'UTC'),
            'default_currency' => Argument::getValue('currency', $args, 'USD'),
        ]);

        Message::success('Settings saved.');

        /* ---- 5. Administrator ------------------------------------------ */
        if ($installer->hasAdmin()) {
            Message::info('A staff account already exists — skipping administrator creation.');
        } else {
            $password = (string) Argument::getValue('admin-pass', $args, '');
            $username = (string) Argument::getValue('admin-user', $args, '');
            $adminEmail = (string) Argument::getValue('admin-email', $args, '');

            foreach (['--admin-user' => $username, '--admin-email' => $adminEmail, '--admin-pass' => $password] as $flag => $value) {
                if ($value === '') {
                    Message::error("{$flag} is required.");
                    return 1;
                }
            }

            $installer->createAdmin([
                'first_name'       => Argument::getValue('admin-first', $args, 'Admin'),
                'last_name'        => Argument::getValue('admin-last', $args, 'User'),
                'username'         => $username,
                'email'            => $adminEmail,
                'password'         => $password,
                'password_confirm' => $password,
            ]);

            Message::success("Administrator [{$username}] created.");
        }

        /* ---- 6. Finish -------------------------------------------------- */
        $installer->finish();
        Message::success('Installation complete.');

        echo "\n  Admin panel: " . Message::txt_cyan('/' . ADMIN) . "\n";
        echo '  Client area: ' . Message::txt_cyan('/' . PANEL) . "\n\n";
        echo "  " . Message::txt_yellow('Next:') . " configure SMTP under Settings → Mail,\n";
        echo "  and start the queue worker with " . Message::txt_cyan('php worker default') . " — outgoing\n";
        echo "  email is queued, never sent during a web request.\n\n";

        return 0;
    }
}
