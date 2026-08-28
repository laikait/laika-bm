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

namespace LBM\Action;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Throwable;
use Laika\Model\Model;
use Laika\Service\Visitor;
use LBM\Model\ActivityModel;

/**
 * The audit trail: who changed what, and what it was before.
 *
 * The table belongs to laika-core, and its builder - Laika\Core\Log\Activity -
 * is deliberately not used to write it. That builder fills `from_ip` with
 * Visitor::ip(), which is null outside a web request, and the column is NOT
 * NULL: every entry written from a queue worker or a CLI command therefore fails
 * on the insert. Since the jobs that generate invoices and chase overdue ones
 * are exactly the things whose audit trail matters most, LBM writes the row
 * itself through ActivityModel and supplies an IP it can defend.
 *
 * The column shape is unchanged, so anything else writing through the framework
 * builder still produces rows this reads.
 *
 * Logging never throws. An audit entry failing to write must not take down the
 * operation it was recording: losing the note that an invoice was raised is bad,
 * failing to raise the invoice because the note could not be written is worse.
 * Failures are swallowed here and surface in the framework's own error log.
 */
class Activity extends Action
{
    /** @var string Recorded Against Staff */
    public const STAFF = 'staff';

    /** @var string Recorded Against a Client */
    public const CLIENT = 'client';

    /** @var string Recorded Against a Client Contact */
    public const CONTACT = 'contact';

    /** @var string Recorded Against Nobody In Particular */
    public const SYSTEM = 'system';

    /**
     * @var bool Whether Anything Has Been Recorded This Request
     *
     * Read by LBM\Filter\ActivityFilter, which writes a generic entry for a
     * mutation that recorded nothing specific. Instance state, and this class is
     * bound as a singleton, so it is per-request by construction.
     */
    private bool $recorded = false;

    public function model(): Model
    {
        return new ActivityModel();
    }

    protected function searchable(): array
    {
        return ['event', 'log'];
    }

    /**
     * The Table Has No Uid Column
     *
     * It belongs to laika-core, which numbers rows by log_id alone.
     * @return ?string
     */
    protected function uidColumn(): ?string
    {
        return null;
    }

    protected function createdColumn(): ?string
    {
        return 'created_at';
    }

    ####################################################################################
    /*================================= EXTERNAL API =================================*/
    ####################################################################################

    /**
     * Record An Activity
     *
     * @param string $event Event Name. Example: 'invoice.created'
     * @param string $log What Happened, In Words
     * @param string $authorType staff, client, contact or system
     * @param ?int $authorId Author ID
     * @param array $changes Changed Fields. ['field' => ['old' => ..., 'new' => ...]]
     * @return bool Whether it was written
     */
    public function record(
        string $event,
        string $log,
        string $authorType = self::SYSTEM,
        ?int $authorId = null,
        array $changes = []
    ): bool {
        try {
            // changes is a `serialize` column. The model's cast decodes on read
            // but never encodes on write, so this has to serialize() itself or
            // the column would store the word "Array".
            $written = (new ActivityModel())->insert([
                'author_type' =>  $this->authorType($authorType),
                'author_id'   =>  $authorId,
                'event'       =>  strtolower(trim($event)),
                'log'         =>  trim($log),
                'changes'     =>  serialize($changes),
                'from_ip'     =>  $this->ip(),
                'created_at'  =>  $this->now(),
            ]);

            if ($written !== false) {
                $this->recorded = true;

                return true;
            }

            return false;
        } catch (Throwable) {
            // Deliberately swallowed - see the class docblock.
            return false;
        }
    }

    /**
     * Whether Anything Has Been Recorded During This Request
     *
     * Lets ActivityFilter fall back to a generic entry for a mutation that
     * logged nothing of its own, rather than duplicating one that did.
     * @return bool
     */
    public function recorded(): bool
    {
        return $this->recorded;
    }

    /**
     * Work Out What Changed Between a Stored Row And Submitted Input
     *
     * Only the keys present in both are compared, so a form that posts a subset
     * of the columns does not report every absent one as cleared.
     * @param array $existing The Row As It Was
     * @param array $input Submitted Data
     * @param string[] $ignore Columns Never Worth Recording
     * @return array
     */
    public function changes(array $existing, array $input, array $ignore = []): array
    {
        $ignore = array_merge(
            ['_csrf', 'password', 'password_confirm', 'permissions'],
            $ignore
        );

        $changes = [];

        foreach ($existing as $column => $old) {
            if (in_array($column, $ignore, true) || !array_key_exists($column, $input)) {
                continue;
            }

            $new = $input[$column];

            // Loose comparison on purpose: a form posts everything as a string,
            // so a column cast to int would otherwise read as changed on every
            // save even when the value is identical.
            if ((string) $old !== (string) $new) {
                $changes[$column] = ['old' => $old, 'new' => $new];
            }
        }

        return $changes;
    }

    /**
     * One Page Of The Audit Trail
     * @param array $where Conditions
     * @param ?string $search Search Term
     * @param ?int $limit Rows Per Page
     * @return array
     */
    public function browseTrail(array $where = [], ?string $search = null, ?int $limit = null): array
    {
        return $this->browse($where, $search, $limit, self::DESC);
    }

    /**
     * Everything One Person Has Done
     * @param string $authorType staff, client, contact or system
     * @param int $authorId Author ID
     * @param ?int $limit Rows Per Page
     * @return array
     */
    public function forAuthor(string $authorType, int $authorId, ?int $limit = null): array
    {
        return $this->browse([
            'author_type' =>  $this->authorType($authorType),
            'author_id'   =>  $authorId,
        ], null, $limit, self::DESC);
    }

    /**
     * Record Something a Staff Member Did
     *
     * A named wrapper rather than making callers pass self::STAFF, for the same
     * reason as forStaff(): a relay facade forwards method calls and not
     * constants, so a controller reaching this through LBM\Service\Activity
     * cannot name the author type itself.
     * @param string $event Event Name. Example: 'client.created'
     * @param string $log What Happened, In Words
     * @param ?int $staffId Staff ID. Null for something the system did unattended
     * @param array $changes Changed Fields
     * @return bool
     */
    public function recordStaff(string $event, string $log, ?int $staffId, array $changes = []): bool
    {
        return $this->record($event, $log, self::STAFF, $staffId, $changes);
    }

    /**
     * Record Something a Client Did
     * @param string $event Event Name
     * @param string $log What Happened, In Words
     * @param ?int $clientId Client ID
     * @param array $changes Changed Fields
     * @return bool
     */
    public function recordClient(string $event, string $log, ?int $clientId, array $changes = []): bool
    {
        return $this->record($event, $log, self::CLIENT, $clientId, $changes);
    }

    /**
     * What One Staff Member Has Done
     *
     * A named wrapper rather than making callers pass self::STAFF: a relay
     * facade forwards method calls and not constants, so a controller reaching
     * this through LBM\Service\Activity cannot name the author type itself.
     * @param int $staffId Staff ID
     * @param ?int $limit Row Limit
     * @return array
     */
    public function forStaff(int $staffId, ?int $limit = null): array
    {
        return $this->forAuthor(self::STAFF, $staffId, $limit);
    }

    /**
     * What One Client Has Done
     * @param int $clientId Client ID
     * @param ?int $limit Row Limit
     * @return array
     */
    public function forClient(int $clientId, ?int $limit = null): array
    {
        return $this->forAuthor(self::CLIENT, $clientId, $limit);
    }

    /**
     * The Most Recent Entries, For a Dashboard Panel
     * @param int $limit Row Limit
     * @return array
     */
    public function recent(int $limit = 10): array
    {
        $model = $this->model();

        return $model->order($model->id, self::DESC)->limit($limit)->get();
    }

    /**
     * Every Distinct Event Name Recorded So Far
     *
     * Drives the filter dropdown on the activities screen, so it lists what
     * actually happened rather than a hardcoded guess at what might.
     * @return string[]
     */
    public function events(): array
    {
        // groupBy rather than distinct(): pluck() calls select() itself, which
        // overwrites the columns string DISTINCT was prepended to.
        $events = $this->model()->groupBy('event')->order('event', self::ASC)->pluck('event');

        return array_values(array_unique(array_map('strval', $events)));
    }

    /**
     * Delete Entries Older Than a Cutoff
     *
     * An audit trail that grows without limit eventually costs more to keep than
     * it is worth; the operator decides where that line is.
     * @param int $days Age In Days
     * @return int Deleted rows
     */
    public function prune(int $days): int
    {
        if ($days < 1) {
            return 0;
        }

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        return $this->model()->where(['created_at' => $cutoff], '<')->delete();
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Who An Entry Can Be Recorded Against, With Labels
     *
     * A method rather than the constants, because a relay facade forwards
     * method calls and not constants.
     * @return array<string,string>
     */
    public function authorTypes(): array
    {
        return [
            self::STAFF   =>  'Staff',
            self::CLIENT  =>  'Clients',
            self::CONTACT =>  'Contacts',
            self::SYSTEM  =>  'System',
        ];
    }

    /**
     * Where The Request Came From
     *
     * The column is NOT NULL and there is no IP in a queue worker or a CLI
     * command, so those record where they ran instead of failing the insert.
     * @return string
     */
    private function ip(): string
    {
        $ip = Visitor::ip();

        if (is_string($ip) && $ip !== '') {
            return mb_substr($ip, 0, 40);
        }

        return PHP_SAPI === 'cli' ? 'cli' : 'unknown';
    }

    /**
     * Constrain An Author Type To One This Class Recognises
     * @param string $type Author Type
     * @return string
     */
    private function authorType(string $type): string
    {
        $type = strtolower(trim($type));

        return in_array($type, [self::STAFF, self::CLIENT, self::CONTACT, self::SYSTEM], true)
            ? $type
            : self::SYSTEM;
    }
}
