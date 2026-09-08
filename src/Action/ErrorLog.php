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

use Laika\Model\Model;
use LBM\Model\ErrorLogModel;
use LBM\Support\ErrorLog as Logger;

/**
 * Reading the error log.
 *
 * The WRITING half is `LBM\Support\ErrorLog`, and the two are deliberately
 * separate classes rather than one. Writing happens from inside an exception
 * handler, from a shutdown function, and from the middle of a cron run - places
 * where constructing an Action, resolving a relay and touching the container
 * are all things that can themselves fail. The writer is static, has no
 * dependencies it does not import, and does nothing that can throw.
 *
 * This half is an ordinary Action: it reads, it pages, and it is allowed to
 * fail like any other screen.
 */
class ErrorLog extends Action
{
    /** @var string[] Where an entry can come from */
    public const SOURCES = ['app', 'module', 'cron', 'job'];

    /** @var string[] How bad it was */
    public const LEVELS = ['error', 'warning', 'critical'];

    /** @var int Days Of Entries Kept When The Operator Has Not Said */
    public const DEFAULT_RETENTION = 30;

    public function model(): Model
    {
        return new ErrorLogModel();
    }

    protected function createdColumn(): ?string
    {
        return 'log_created_at';
    }

    /**
     * @var string[] Columns a Search Box Looks In
     *
     * Not `trace`, which is a longText holding every frame of every entry - a
     * LIKE over it turns the log screen into a table scan on the one table that
     * grows fastest.
     */
    protected function searchable(): array
    {
        return ['message', 'exception_class', 'file', 'url'];
    }

    ####################################################################################
    /*================================= EXTERNAL API =================================*/
    ####################################################################################

    /**
     * The Log, Newest First
     *
     * @param ?string $source One of SOURCES, or null for all
     * @param ?string $level One of LEVELS, or null for all
     * @param ?string $search Search term
     * @param ?int $limit Rows per page
     * @return array
     */
    public function browseLog(
        ?string $source = null,
        ?string $level = null,
        ?string $search = null,
        ?int $limit = null
    ): array {
        $where = [];

        // Validated against the lists rather than passed through: these arrive
        // from a query string, and a value the enum does not hold would return
        // an empty page that reads exactly like "nothing has gone wrong".
        if ($source !== null && in_array($source, self::SOURCES, true)) {
            $where['source'] = $source;
        }

        if ($level !== null && in_array($level, self::LEVELS, true)) {
            $where['level'] = $level;
        }

        return $this->browse($where, $search, $limit, self::DESC);
    }

    /**
     * How Many Entries There Are, By Source
     *
     * For the summary row. Counted rather than derived from the page, because
     * the page is one screenful and the question is about the whole table.
     * @return array<string,int>
     */
    public function countsBySource(): array
    {
        $counts = [];

        foreach (self::SOURCES as $source) {
            $counts[$source] = $this->count(['source' => $source]);
        }

        return $counts;
    }

    /**
     * The Newest Entry's Timestamp, Or Null
     *
     * The dashboard question - "has anything gone wrong lately" - answered
     * without reading the table.
     * @return ?string
     */
    public function lastAt(): ?string
    {
        $model = $this->model();
        $row = $model->order($model->id, self::DESC)->limit(1)->get()[0] ?? null;

        return is_array($row) ? (string) ($row['log_created_at'] ?? '') ?: null : null;
    }

    /**
     * How Many Days Of Entries Are Kept
     *
     * Zero means keep everything, and an operator who sets that has chosen it.
     * The DEFAULT is thirty rather than zero, which is the opposite of the way
     * Phase 23 and Phase 24 defaulted their sweeps - and for a reason that
     * points the other way: those destroy customer services, this deletes rows
     * about faults that have already been dealt with. A log nobody prunes is
     * one somebody eventually truncates by hand, losing the entry they wanted
     * along with everything else.
     * @return int
     */
    public function retentionDays(): int
    {
        $days = option_int('error_log_days', self::DEFAULT_RETENTION);

        return $days > 0 ? $days : 0;
    }

    /**
     * Delete Entries Past The Retention Window
     *
     * Called from cron. Forwards to the writer, which is where the delete lives
     * so that nothing has to construct an Action to tidy up after itself.
     * @return string What happened, for the cron log
     */
    public function prune(): string
    {
        $days = $this->retentionDays();

        if ($days === 0) {
            return 'kept everything (retention is off)';
        }

        return Logger::prune($days) . ' removed, keeping ' . $days . ' days';
    }
}
