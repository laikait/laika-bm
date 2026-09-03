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

namespace LBM\Support;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Throwable;
use Laika\Model\Connection;
use LBM\Install\Requirements;
use LBM\Service\Mail;

/**
 * What state this installation is actually in.
 *
 * Two audiences read the same facts. The dashboard shows one line about cron;
 * the utilities area shows the whole picture. They used to be different code -
 * the dashboard computed staleness itself - which is the arrangement where the
 * dashboard eventually says cron is fine while the status screen says it is
 * not. cron() lives here now and the dashboard calls it.
 *
 * Nothing here calls local(). It is read by admin screens today, but the same
 * facts are the obvious thing for a future CLI or health endpoint to want, and
 * a catalogue is only loaded for a rendered area - see the note in Cron for the
 * same reasoning.
 *
 * Requirements is not duplicated. That class answers "can this machine run the
 * application", which is an install-time question and already written; this one
 * answers "is this installation working", which is only askable afterwards.
 */
class Health
{
    /** @var int Minutes Before a Cron Run Counts as Stale */
    public const CRON_STALE_MINUTES = 60;

    /** @var string[] Directories The Application Writes To While Running */
    public const WRITABLE = [
        'lf-storage',
        'lf-logs',
        'uploads',
        'modules',
    ];

    /**
     * When Cron Last Ran, And Whether That Is Recent Enough
     *
     * The dashboard and the automation screen both read this. An unreadable
     * timestamp counts as ancient rather than as fine: a stamp nobody can parse
     * is not evidence that anything ran.
     * @return array{last:?string,never:bool,stale:bool,hours:int,minutes:int,command:string}
     */
    public function cron(): array
    {
        $last = trim((string) option('cron_last_run', ''));
        $minutes = option_int('cron_stale_minutes', self::CRON_STALE_MINUTES);
        $minutes = $minutes > 0 ? $minutes : self::CRON_STALE_MINUTES;

        // The exact command for *this* install, not a documentation example an
        // operator has to adapt. The path is the single detail they get wrong.
        $command = APP_PATH . DS . 'cron.php';

        if ($last === '') {
            return [
                'last'    =>  null,
                'never'   =>  true,
                'stale'   =>  true,
                'hours'   =>  0,
                'minutes' =>  $minutes,
                'command' =>  $command,
            ];
        }

        $age = time() - (int) strtotime($last);

        return [
            'last'    =>  $last,
            'never'   =>  false,
            'stale'   =>  $age > $minutes * 60,
            'hours'   =>  (int) floor($age / 3600),
            'minutes' =>  $minutes,
            'command' =>  $command,
        ];
    }

    /**
     * Cron, The Lock And The Mail Queue, In One Place
     * @return array
     */
    public function automation(): array
    {
        // Cron::LOCK is written with forward slashes and APP_PATH with the
        // platform separator, so the two joined read as a mixed-separator path
        // on Windows. It resolves either way; this is so the screen shows an
        // operator a path they can paste.
        $lock = str_replace(['/', '\\'], DS, APP_PATH . Cron::LOCK);
        $held = is_file($lock);

        return [
            'cron'  =>  $this->cron(),
            'daily' =>  [
                'last'  =>  trim((string) option('cron_last_daily', '')) ?: null,
                'today' =>  trim((string) option('cron_last_daily', '')) === date('Y-m-d'),
            ],
            'lock'  =>  [
                'path'    =>  $lock,
                'held'    =>  $held,
                // A lock left behind by a killed run is the reason cron looks
                // like it is running and nothing happens, so its age is the
                // number worth showing rather than just "locked".
                'age'     =>  $held ? max(0, time() - (int) filemtime($lock)) : 0,
                'stale'   =>  $held && (time() - (int) filemtime($lock)) > option_int('cron_lock_minutes', Cron::LOCK_MINUTES) * 60,
            ],
            'mail'  =>  $this->mailQueue(),
            'tasks' =>  [
                'raise due invoices',
                'invoice reminders',
                'prune expired tokens',
                'prune sent mail',
            ],
        ];
    }

    /**
     * Everything The Status Checker Reports
     * @return array
     */
    public function system(): array
    {
        $requirements = (new Requirements())->all();
        $runtime = $this->runtime();

        $failed = $requirements['failed'];

        foreach ($runtime as $check) {
            if ($check['required'] && !$check['ok']) {
                $failed++;
            }
        }

        return [
            'passed'       =>  $failed === 0,
            'failed'       =>  $failed,
            'requirements' =>  $requirements,
            'runtime'      =>  $runtime,
        ];
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * The Checks That Only Make Sense On a Running Install
     *
     * Requirements answers these for the machine before anything is installed.
     * These are the ones that can only go wrong afterwards - a directory whose
     * permissions changed, a key that was deleted, a cron nobody scheduled.
     * @return array<int,array{label:string,ok:bool,required:bool,state:string}>
     */
    private function runtime(): array
    {
        $checks = [];

        foreach (self::WRITABLE as $path) {
            $full = APP_PATH . DS . $path;
            $exists = is_dir($full);

            $checks[] = [
                'label'    =>  $path . ' is writable',
                'ok'       =>  $exists && is_writable($full),
                'required' =>  true,
                'state'    =>  $exists ? $full : 'missing',
            ];
        }

        // A FILE, not a config value. config('app', 'key') is valid syntax and
        // answers null, because lf-config/app.php holds name, url and
        // documentation and nothing else - so checking there reports every
        // healthy install as broken. Laika\Core\App\Key writes and reads
        // lf-storage/keys/app.key, and that is what has to exist.
        //
        // The directory is excluded from the release archive on purpose: every
        // installation generates its own, and a shipped key would be the same
        // key on every site running this software.
        $keyFile = APP_PATH . DS . 'lf-storage' . DS . 'keys' . DS . 'app.key';
        $hasKey = is_file($keyFile) && trim((string) file_get_contents($keyFile)) !== '';

        $checks[] = [
            'label'    =>  'application key is present',
            'ok'       =>  $hasKey,
            'required' =>  true,
            // Never the key itself. This screen is readable by anybody with
            // settings.read, and the key decrypts every stored secret.
            'state'    =>  $hasKey ? 'present' : 'missing from lf-storage/keys',
        ];

        $cron = $this->cron();

        $checks[] = [
            'label'    =>  'cron has run recently',
            'ok'       =>  !$cron['stale'],
            'required' =>  true,
            'state'    =>  $cron['never']
                ? 'never run'
                : 'last run ' . (string) $cron['last'],
        ];

        // Not required: an install that sends nothing is a valid, if quiet, one.
        // Reporting it as a failure would train operators to ignore this screen.
        $host = trim((string) option('mail_host', ''));

        $checks[] = [
            'label'    =>  'mail is configured',
            'ok'       =>  $host !== '',
            'required' =>  false,
            'state'    =>  $host === '' ? 'no mail host set' : $host,
        ];

        $checks[] = $this->database();

        return $checks;
    }

    /**
     * Which Driver Is Behind This Install, And What Version
     * @return array{label:string,ok:bool,required:bool,state:string}
     */
    private function database(): array
    {
        try {
            $pdo = Connection::get('default');

            $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
            $version = (string) $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);

            return [
                'label'    =>  'database',
                'ok'       =>  true,
                'required' =>  true,
                'state'    =>  $driver . ' ' . $version,
            ];
        } catch (Throwable $e) {
            // Reaching this screen at all means a connection worked a moment
            // ago, so a failure here is worth reporting rather than hiding.
            return [
                'label'    =>  'database',
                'ok'       =>  false,
                'required' =>  true,
                'state'    =>  $e->getMessage(),
            ];
        }
    }

    /**
     * How Much Mail Is Waiting, And How Much Gave Up
     *
     * The status names are the ones EmailQueueStatusSchema seeds - queued,
     * completed, failed, manual - and not the ones that read naturally. 'sent'
     * is not a status; asking for it returns null from statusId() and would
     * report a confident zero for a queue that had delivered thousands.
     * @return array{queued:int,failed:int,completed:int,manual:int}
     */
    private function mailQueue(): array
    {
        $count = static function (string $status): int {
            $id = Mail::statusId($status);

            return $id === null ? 0 : Mail::count(['status_relid' => $id]);
        };

        return [
            'queued'    =>  $count('queued'),
            'failed'    =>  $count('failed'),
            'completed' =>  $count('completed'),
            'manual'    =>  $count('manual'),
        ];
    }
}
