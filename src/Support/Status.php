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

use Laika\Model\Model;
use Laika\Service\Infra;

/**
 * The status lookup tables (instruction 7).
 *
 * Every status column on a parent table is a `*_relid` pointing at a lookup
 * table that carries both a name and a colour, so a screen never hardcodes
 * either. There are fourteen of them: twelve spell the columns
 * `status_name` / `status_color`, `support_priorities` uses `priority_*` and
 * `provisioning_results` uses `result_*`.
 *
 * Nothing here hardcodes a table-to-model map. Infra::getModelClasses() is
 * already keyed by table name, and the name/colour columns are found by their
 * `_name` / `_color` suffix - so a new status table works the moment its
 * schema and model exist, with no edit here.
 *
 * Each table is read once per request and held whole. They are tiny - the
 * largest ships nine rows - so one query beats a join on every list row.
 */
class Status
{
    /** @var string Fallback Colour When a Row Has None */
    public const FALLBACK_COLOR = '#6c757d';

    /** @var array<string,array<int,array{name:string,color:string}>> Rows, keyed by table then id */
    private array $cache = [];

    /**
     * Get One Status
     * @param string $table Lookup Table Name. Example: 'invoice_statuses'
     * @param int|string|null $id The parent row's *_relid
     * @return array{name:string,color:string,id:int}|null
     */
    public function get(string $table, int|string|null $id): ?array
    {
        if ($id === null || $id === '') {
            return null;
        }

        return $this->all($table)[(int) $id] ?? null;
    }

    /**
     * Get a Status Name
     * @param string $table Lookup Table Name
     * @param int|string|null $id The parent row's *_relid
     * @param string $default Returned When The Id Does Not Resolve
     * @return string
     */
    public function name(string $table, int|string|null $id, string $default = ''): string
    {
        return $this->get($table, $id)['name'] ?? $default;
    }

    /**
     * Get a Status Colour
     * @param string $table Lookup Table Name
     * @param int|string|null $id The parent row's *_relid
     * @return string Hex colour, never empty
     */
    public function color(string $table, int|string|null $id): string
    {
        $color = $this->get($table, $id)['color'] ?? '';

        return $color !== '' ? $color : self::FALLBACK_COLOR;
    }

    /**
     * Every Row In a Lookup Table, Keyed By Id
     *
     * Also what the status filter dropdown on each list screen renders from.
     * @param string $table Lookup Table Name
     * @return array<int,array{name:string,color:string,id:int}>
     */
    public function all(string $table): array
    {
        if (array_key_exists($table, $this->cache)) {
            return $this->cache[$table];
        }

        $model = $this->model($table);

        if ($model === null) {
            return $this->cache[$table] = [];
        }

        $id = $model->id;
        $rows = [];

        foreach ($model->order($id, 'ASC')->get() as $row) {
            $rows[(int) $row[$id]] = [
                'id'    =>  (int) $row[$id],
                'name'  =>  (string) ($row[$this->column($row, '_name')] ?? ''),
                'color' =>  (string) ($row[$this->column($row, '_color')] ?? ''),
            ];
        }

        return $this->cache[$table] = $rows;
    }

    /**
     * Resolve a Status Id By Name
     *
     * Lets application code say 'paid' rather than carrying the integer a seed
     * happened to assign.
     * @param string $table Lookup Table Name
     * @param string $name Status Name. Example: 'paid'
     * @return ?int
     */
    public function idOf(string $table, string $name): ?int
    {
        foreach ($this->all($table) as $id => $row) {
            if (strcasecmp($row['name'], $name) === 0) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Forget Cached Rows
     *
     * Call after the Settings screen edits a status, or the old colour keeps
     * rendering for the rest of the request.
     * @param ?string $table Lookup Table Name. Null clears every table
     * @return void
     */
    public function flush(?string $table = null): void
    {
        if ($table === null) {
            $this->cache = [];
            return;
        }

        unset($this->cache[$table]);
    }

    ##############################################################################
    /*============================== INTERNAL API ==============================*/
    ##############################################################################

    /**
     * Instantiate The Model For a Table
     * @param string $table Lookup Table Name
     * @return ?Model
     */
    private function model(string $table): ?Model
    {
        $class = Infra::getModelClasses()[$table] ?? null;

        return $class ? new $class() : null;
    }

    /**
     * Find The Column Ending In a Suffix
     *
     * Keeps this working across `status_name`, `priority_name` and
     * `result_name` without naming any of them.
     * @param array $row A Fetched Row
     * @param string $suffix '_name' or '_color'
     * @return ?string
     */
    private function column(array $row, string $suffix): ?string
    {
        foreach (array_keys($row) as $column) {
            if (str_ends_with((string) $column, $suffix)) {
                return (string) $column;
            }
        }

        return null;
    }
}
