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

namespace LBM\Controller\Admin;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use RuntimeException;
use Laika\Core\Exceptions\HttpException;
use Laika\Service\Request;
use LBM\Install\Installer;
use LBM\Service\Setting;
use LBM\Service\Todo;
use LBM\Support\Health;
use LBM\Support\Version;

/**
 * Utilities: update, status, automation and a to-do list.
 *
 * These are the screens an operator opens when something is wrong, or when they
 * want to know whether anything is. That shapes two decisions.
 *
 * First, they are gated on `settings`, not on a new permission group. Adding a
 * group to Permission::GROUPS would make these screens invisible on every
 * install that already exists - allows() answers false for a permission a role
 * has never seen, and a role is only granted what it was created with. Shipping
 * a feature nobody can reach until they tick a box they do not know about is
 * worse than reusing the gate that already covers system administration, which
 * is exactly what these are. Reading is `settings.read`; anything that changes
 * the installation is `settings.update`.
 *
 * Second, the update screen tells the truth about what it cannot do. Applying
 * an update here runs the migration in process, and migration only ever calls
 * createIfNotExists - so a release that adds a table arrives, and a release that
 * changes a column does not. Saying so on the screen is the difference between
 * an operator who knows to read the release notes and one who finds out later.
 */
class UtilController extends AdminController
{
    /** @var string Option Holding The URL A Version Check Reads */
    public const FEED_OPTION = 'update_feed_url';

    /** @var int Seconds Before a Version Check Gives Up */
    public const FEED_TIMEOUT = 10;

    protected function nav(): string
    {
        return 'utils';
    }

    /**
     * The Utilities Index
     * @return string
     */
    public function index(): string
    {
        $health = new Health();

        return $this->screen('utils', local('utilities'), [
            'version'     =>  Version::CURRENT,
            'system'      =>  $health->system(),
            'outstanding' =>  Todo::outstanding((int) $this->staffId()),
        ]);
    }

    ####################################################################################
    /*=================================== UPDATE =====================================*/
    ####################################################################################

    /**
     * What Version This Is, And Whether There Is a Newer One
     * @return string
     */
    public function update(): string
    {
        // Read here rather than in the view. There is no `option` Twig filter -
        // the registered ones are icon, money, status, date_app, number and
        // local, plus laika-core's hook and asset - so a template reaching for
        // an option would be an unknown-filter error at render time.
        $seen = trim((string) option('update_last_seen', ''));

        $installer = new Installer();

        // What the last run did. Written to an option by Installer::recordRun()
        // rather than carried back through the redirect, because attempt()
        // redirects and a flash cannot carry structure - the same route check()
        // already takes for the version it finds.
        $report = json_decode((string) option('migration_report', ''), true);

        // The database's version, which is not the same question as the code's.
        // When they differ the files were updated and the migration was not run,
        // and that is exactly the state that produces "the update said it worked
        // and one screen is broken".
        $schema = trim((string) option('schema_version', ''));

        return $this->screen('util-update', local('update_app'), [
            'version'   =>  Version::CURRENT,
            'product'   =>  Version::PRODUCT,
            'feed'      =>  trim((string) option(self::FEED_OPTION, '')),
            'seen'      =>  $seen === '' ? null : $seen,
            'newer'     =>  $seen !== '' && version_compare($seen, Version::CURRENT, '>'),
            'checked'   =>  trim((string) option('update_checked_at', '')) ?: null,
            'pending'   =>  $installer->pendingMigrations(),
            'problem'   =>  $installer->migrationProblem(),
            'schema'    =>  $schema === '' ? null : $schema,
            'behind'    =>  $schema !== '' && version_compare($schema, Version::CURRENT, '<'),
            'report'    =>  is_array($report) ? $report : null,
            'ran'       =>  trim((string) option('migration_ran_at', '')) ?: null,
        ]);
    }

    /**
     * Ask The Release Feed What The Current Version Is
     *
     * POST, because it reaches out to another machine. A GET that made a network
     * call every time somebody opened the screen would hang the utilities page
     * whenever the feed was slow or gone, and would call out on every refresh.
     * @return ?string
     */
    public function check(): ?string
    {
        $feed = trim((string) option(self::FEED_OPTION, ''));

        return $this->attempt(
            function () use ($feed): void {
                if ($feed === '') {
                    throw new RuntimeException(local('no_update_source'));
                }

                $latest = $this->latest($feed);

                if ($latest === null) {
                    throw new RuntimeException(local('update_check_failed'));
                }

                // Stored rather than passed through the redirect, because
                // attempt() redirects and a flash cannot carry structure.
                Setting::put('update_last_seen', $latest);
                Setting::put('update_checked_at', date('Y-m-d H:i:s'));
            },
            'staff.util.update',
            local('update_check_done')
        );
    }

    /**
     * Apply What The Update Brought
     *
     * Runs Installer::migrate() in process. Nothing shells out - `laika` is not
     * shipped, so there is nothing to shell out to.
     *
     * Three passes happen inside that call: tables that do not exist are
     * created, lookup data is re-seeded, and any outstanding migration is
     * applied. The third is the one that can change a table that already
     * exists, which is what the first two have never been able to do.
     * @return ?string
     */
    public function migrate(): ?string
    {
        return $this->attempt(
            function (): void {
                $installer = new Installer();

                $before = $installer->pendingMigrations();
                $results = $installer->migrate();

                $failed = [];

                foreach ($results as $table => $result) {
                    if (!($result['ok'] ?? false)) {
                        $failed[] = $table;
                    }
                }

                // From the in-memory record, not the migration_report option -
                // option() memoises per key for the whole request, so reading
                // back what this request just wrote can return the value from
                // before it. $results is no use either: by design it carries
                // only the failures, so that a fresh install's wizard does not
                // list a row per migration all saying there was nothing to do.
                $applied = 0;
                $baselined = 0;

                foreach ($installer->lastMigrationRun() as $result) {
                    if (($result['state'] ?? '') === 'applied') {
                        $applied++;
                    } elseif (($result['state'] ?? '') === 'baselined') {
                        $baselined++;
                    }
                }

                $this->log(
                    'app.migrated',
                    'Ran the database migration from the utilities screen. '
                        . count($results) . ' schemas, ' . count($failed) . ' failed. '
                        . 'Migrations: ' . count($before) . ' outstanding, ' . $applied . ' applied, '
                        . $baselined . ' already present.'
                );

                if ($failed !== []) {
                    throw new RuntimeException(local('migrate_failed', implode(', ', array_slice($failed, 0, 5))));
                }
            },
            'staff.util.update',
            local('migrate_done')
        );
    }

    ####################################################################################
    /*=================================== STATUS =====================================*/
    ####################################################################################

    /**
     * Is Anything Wrong With This Installation
     * @return string
     */
    public function system(): string
    {
        return $this->screen('util-system', local('system_status'), [
            'status' =>  (new Health())->system(),
        ]);
    }

    /**
     * Is Anything Actually Running
     * @return string
     */
    public function automation(): string
    {
        return $this->screen('util-automation', local('automation_status'), [
            'automation' =>  (new Health())->automation(),
        ]);
    }

    ####################################################################################
    /*==================================== TO-DO =====================================*/
    ####################################################################################

    /**
     * The Signed-In Staff Member's Own List
     * @return string
     */
    public function todos(): string
    {
        return $this->screen('util-todos', local('todo_list'), [
            'todos' =>  Todo::forStaff((int) $this->staffId()),
        ]);
    }

    /**
     * Add An Item
     * @return ?string
     */
    public function addTodo(): ?string
    {
        $staffId = (int) $this->staffId();
        $input = Request::inputs();

        return $this->attempt(
            function () use ($staffId, $input): void {
                if (Todo::add($staffId, $input) === 0) {
                    throw new RuntimeException(local('todo_needs_a_title'));
                }
            },
            'staff.util.todos',
            local('todo_added')
        );
    }

    /**
     * Tick Or Untick An Item
     * @param string $todo Todo Uid
     * @return ?string
     */
    public function toggleTodo(string $todo): ?string
    {
        $row = $this->ownTodo($todo);

        return $this->attempt(
            static function () use ($row): void {
                Todo::toggle($row);
            },
            'staff.util.todos',
            local('todo_updated')
        );
    }

    /**
     * Remove An Item
     * @param string $todo Todo Uid
     * @return ?string
     */
    public function deleteTodo(string $todo): ?string
    {
        $row = $this->ownTodo($todo);

        return $this->attempt(
            static function () use ($row): void {
                Todo::delete((int) $row['todo_id']);
            },
            'staff.util.todos',
            local('todo_removed')
        );
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * One Of The Signed-In Staff Member's Own Items, Or 404
     *
     * Scoped through forStaffKey(), so another staff member's item is not found
     * rather than found and then refused. A to-do list is a private note to
     * yourself; one staff member reading another's would be a surprise even
     * though both can reach this screen.
     * @param string $uid Todo Uid
     * @return array
     */
    private function ownTodo(string $uid): array
    {
        $row = Todo::forStaffKey($uid, (int) $this->staffId());

        if ($row === null) {
            throw new HttpException(404, local('todo_not_found'));
        }

        return $row;
    }

    /**
     * Read a Version Out Of The Release Feed
     *
     * The feed is whatever the operator pointed the option at, so it is treated
     * as untrusted: a JSON body with a `version` key, matched against a strict
     * semver pattern and nothing else. Anything that does not match is no
     * answer rather than a version - a feed that returned an HTML error page
     * must not end up displayed as the latest release.
     * @param string $url Feed URL
     * @return ?string
     */
    private function latest(string $url): ?string
    {
        if (!preg_match('~^https://~i', $url)) {
            return null;
        }

        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'timeout'       => self::FEED_TIMEOUT,
                'ignore_errors' => true,
                'header'        => "Accept: application/json\r\nUser-Agent: " . Version::PRODUCT . '/' . Version::CURRENT . "\r\n",
            ],
            'ssl'  => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        // NOT @file_get_contents(). The framework's error handler promotes
        // warnings to ErrorException whatever the @ says, so suppressing a
        // failed connection here would take the whole request down instead of
        // returning false - the same trap that has bitten every harness in
        // this project.
        try {
            $body = file_get_contents($url, false, $context);
        } catch (\Throwable) {
            return null;
        }

        if (!is_string($body) || $body === '') {
            return null;
        }

        $data = json_decode($body, true);

        if (!is_array($data)) {
            return null;
        }

        $version = trim((string) ($data['version'] ?? ''));

        return preg_match('/^\d+\.\d+\.\d+$/', $version) === 1 ? $version : null;
    }
}
