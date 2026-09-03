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
use Laika\Service\Uid;
use LBM\Support\Paginator;

/**
 * Base class for every LBM action.
 *
 * Actions hold the business logic. Controllers are thin wrappers: they read the
 * request, call an action, and render - so the same operation is reachable from
 * a controller, a CLI command, a queue job or a module without being written
 * three times.
 *
 * Everything here is built from model methods only (instruction 9). There is no
 * execute(), no raw SQL string and no driver-specific function, so the whole
 * application runs unchanged on any grammar laika-model supports.
 *
 * Three conventions the subclasses rely on:
 *
 *  - model() returns a *fresh* model every call. laika-model resets its builder
 *    inside a finally after each terminal method, but a query abandoned before
 *    its terminal call would leave wheres attached - and browse() deliberately
 *    builds the same filter set twice, once to count and once to page.
 *  - Records are addressed by uid from the outside and by primary key inside.
 *    key() accepts either, so a controller can hand over a URL segment directly.
 *  - Timestamps are written explicitly rather than left to the column default.
 *    Blueprint::timestamps() emits MySQL's ON UPDATE CURRENT_TIMESTAMP, which
 *    no other driver honours, so relying on it would make updated_at silently
 *    stop moving on PgSQL or SQLite.
 */
abstract class Action
{
    /** @var string Sort Newest First */
    public const DESC = 'DESC';

    /** @var string Sort Oldest First */
    public const ASC = 'ASC';

    ####################################################################################
    /*================================== CONTRACT ====================================*/
    ####################################################################################

    /**
     * A Fresh Model
     *
     * Fresh, never memoised - see the class docblock.
     * @return Model
     */
    abstract public function model(): Model;

    /**
     * Columns a Search Term Is Matched Against
     *
     * Empty means the resource is not searchable and a search term is ignored
     * rather than silently matching everything.
     * @return string[]
     */
    protected function searchable(): array
    {
        return [];
    }

    /**
     * The Uid Column, If The Table Has One
     *
     * Model defaults $uid to the literal 'uid' whether or not the table carries
     * such a column, so a table without one - laika-core's `activities`, for
     * instance - has to say so here or create() would insert a column that does
     * not exist.
     * @return ?string
     */
    protected function uidColumn(): ?string
    {
        $uid = $this->model()->uid;

        return $uid === '' ? null : $uid;
    }

    /**
     * The Created-At Column, If The Table Has One
     * @return ?string
     */
    protected function createdColumn(): ?string
    {
        return null;
    }

    /**
     * The Updated-At Column, If The Table Has One
     * @return ?string
     */
    protected function updatedColumn(): ?string
    {
        return null;
    }

    ####################################################################################
    /*==================================== READS =====================================*/
    ####################################################################################

    /**
     * Find One Record By Primary Key Or Uid
     * @param int|string|null $key Primary Key Or Uid
     * @return ?array
     */
    public function find(int|string|null $key): ?array
    {
        if ($key === null || $key === '' || $key === 0) {
            return null;
        }

        $model = $this->model();
        $row = $this->key($model, $key)->first();

        return is_array($row) ? $row : null;
    }

    /**
     * Find One Record By Arbitrary Conditions
     * @param array $where Conditions. See where()
     * @return ?array
     */
    public function first(array $where): ?array
    {
        if ($where === []) {
            return null;
        }

        $row = $this->conditions($this->model(), $where)->first();

        return is_array($row) ? $row : null;
    }

    /**
     * Get Every Matching Record
     *
     * Unpaginated on purpose: this is for the small bounded sets that fill a
     * select box - currencies, departments, statuses. Anything that grows with
     * the business goes through browse().
     * @param array $where Conditions
     * @param string $direction ASC or DESC
     * @param ?string $order Order Column. Defaults to the primary key
     * @return array
     */
    public function all(array $where = [], string $direction = self::ASC, ?string $order = null): array
    {
        $model = $this->model();

        return $this->conditions($model, $where)
            ->order($order ?? $model->id, $direction)
            ->get();
    }

    /**
     * Count Matching Records
     * @param array $where Conditions
     * @return int
     */
    public function count(array $where = []): int
    {
        return $this->conditions($this->model(), $where)->count();
    }

    /**
     * Count Records Created In a Window
     *
     * Here rather than in each report, because every report that asks "how many
     * of these appeared between two dates" was otherwise going to write the same
     * three branches - and one of them would eventually use > where the others
     * used >=, so the same month would total differently on two screens.
     *
     * An action with no createdColumn() has nothing to bound, so it answers the
     * unbounded count rather than silently returning zero.
     * @param ?string $from Datetime, Inclusive
     * @param ?string $to Datetime, Inclusive
     * @param array $where Further Conditions
     * @return int
     */
    public function countBetween(?string $from = null, ?string $to = null, array $where = []): int
    {
        $column = $this->createdColumn();

        if ($column === null) {
            return $this->count($where);
        }

        $model = $this->conditions($this->model(), $where);

        if ($from !== null && $to !== null) {
            $model->between($column, $from, $to);
        } elseif ($from !== null) {
            $model->where([$column => $from], '>=');
        } elseif ($to !== null) {
            $model->where([$column => $to], '<=');
        }

        return $model->count();
    }

    /**
     * Whether Any Record Matches
     * @param array $where Conditions
     * @return bool
     */
    public function exists(array $where): bool
    {
        return $this->count($where) > 0;
    }

    /**
     * Get One Page Of a Listing
     *
     * Keyset pagination (instruction 10): the cursor is read from the `after`
     * query parameter and the page is fetched with `WHERE id > :after LIMIT n`,
     * never with OFFSET.
     *
     * The filter set is applied twice - once for the count, once for the page -
     * because a terminal model call consumes the builder. Two cheap indexed
     * queries, rather than one query whose builder cannot be reused.
     * @param array $where Conditions
     * @param ?string $search Search Term. Matched against searchable()
     * @param ?int $limit Rows Per Page. Defaults to the data_limit option
     * @param string $direction ASC or DESC
     * @return array{rows:array, total:int, limit:int, cursor:?int, next:?int, next_url:?string, previous:?string, has_more:bool}
     */
    public function browse(
        array $where = [],
        ?string $search = null,
        ?int $limit = null,
        string $direction = self::DESC
    ): array {
        $counted = $this->model();
        $this->search($this->conditions($counted, $where), $search);

        $listed = $this->model();
        $this->search($this->conditions($listed, $where), $search);

        return $this->paginate($listed, $counted, $limit, $direction);
    }

    /**
     * Page Two Prepared Models
     *
     * For listings that need a join or a condition the shorthand cannot express.
     * Both models must carry the same filters - one is consumed by the count,
     * the other by the page - and only the listed one should carry the join, so
     * a one-to-many join cannot inflate the total.
     * @param Model $listed Model For The Page
     * @param Model $counted Model For The Total
     * @param ?int $limit Rows Per Page
     * @param string $direction ASC or DESC
     * @return array{rows:array, total:int, limit:int, cursor:?int, next:?int, next_url:?string, previous:?string, has_more:bool}
     */
    protected function paginate(
        Model $listed,
        Model $counted,
        ?int $limit = null,
        string $direction = self::DESC
    ): array {
        $paginator = new Paginator();

        $total = $counted->count();

        $page = $paginator->page($listed, $limit, $direction);
        $page['total']    = $total;
        $page['next_url'] = $paginator->nextUrl($page['next']);

        return $page;
    }

    ####################################################################################
    /*==================================== WRITES ====================================*/
    ####################################################################################

    /**
     * Create a Record
     *
     * The uid and the created/updated timestamps are filled in here, so no
     * caller has to remember them and no two callers can disagree about the
     * format.
     * @param array $data Column Values
     * @return int The new primary key
     */
    public function create(array $data): int
    {
        $model = $this->model();
        $id = $model->insert($this->stamp($data, true));

        return (int) $id;
    }

    /**
     * Update a Record By Primary Key Or Uid
     * @param int|string $key Primary Key Or Uid
     * @param array $data Column Values
     * @return int Affected rows
     */
    public function update(int|string $key, array $data): int
    {
        if ($data === []) {
            return 0;
        }

        $model = $this->model();

        return $this->key($model, $key)->update($this->stamp($data, false));
    }

    /**
     * Update Every Record Matching Conditions
     * @param array $where Conditions. Never empty - laika-model refuses an unbounded UPDATE
     * @param array $data Column Values
     * @return int Affected rows
     */
    public function updateWhere(array $where, array $data): int
    {
        if ($where === [] || $data === []) {
            return 0;
        }

        return $this->conditions($this->model(), $where)->update($this->stamp($data, false));
    }

    /**
     * Delete a Record By Primary Key Or Uid
     * @param int|string $key Primary Key Or Uid
     * @return int Affected rows
     */
    public function delete(int|string $key): int
    {
        $model = $this->model();

        return $this->key($model, $key)->delete();
    }

    /**
     * Delete Every Record Matching Conditions
     * @param array $where Conditions. Never empty
     * @return int Affected rows
     */
    public function deleteWhere(array $where): int
    {
        if ($where === []) {
            return 0;
        }

        return $this->conditions($this->model(), $where)->delete();
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Constrain a Model To One Record
     *
     * Not Model::find(), which branches on is_numeric() - a uid that happens to
     * be all digits would be looked up as a primary key. Callers know which
     * they hold; this respects that.
     * @param Model $model Model
     * @param int|string $key Primary Key Or Uid
     * @return Model
     */
    protected function key(Model $model, int|string $key): Model
    {
        return is_int($key) || ctype_digit((string) $key)
            ? $model->where([$model->id => (int) $key])
            : $model->where([$model->uid => (string) $key]);
    }

    /**
     * Apply Conditions To a Model
     *
     * A shorthand rather than a query language:
     *   'status_relid' => 3          column = value
     *   'status_relid' => [1, 2, 3]  column IN (...)
     *   'closed_at'    => null       column IS NULL
     *
     * Anything more involved - a join, a range, a nested OR - is written out in
     * the action that needs it, where it can be read.
     * @param Model $model Model
     * @param array $where Conditions
     * @return Model
     */
    protected function conditions(Model $model, array $where): Model
    {
        foreach ($where as $column => $value) {
            if ($value === null) {
                $model->isNull((string) $column);
                continue;
            }

            if (is_array($value)) {
                // An empty IN () is a syntax error on most drivers, and means
                // "match nothing" anyway - say so with a condition that cannot
                // be true rather than emitting broken SQL.
                if ($value === []) {
                    $model->isNull($model->id);
                    continue;
                }

                $model->whereIn((string) $column, array_values($value));
                continue;
            }

            $model->where([$column => $value]);
        }

        return $model;
    }

    /**
     * Apply a Search Term
     *
     * Wrapped in whereGroup() so the OR-joined LIKEs bind tighter than whatever
     * filters are already on the model. Without the parentheses a status filter
     * plus a search would read as `status = 3 OR name LIKE '%x%'` and return
     * every row that matched either.
     * @param Model $model Model
     * @param ?string $search Search Term
     * @return Model
     */
    protected function search(Model $model, ?string $search): Model
    {
        $columns = $this->searchable();
        $search = $search === null ? '' : trim($search);

        if ($search === '' || $columns === []) {
            return $model;
        }

        $term = '%' . $search . '%';

        return $model->whereGroup(static function (Model $group) use ($columns, $term): void {
            $group->where(array_fill_keys($columns, $term), 'LIKE', 'OR');
        });
    }

    /**
     * Fill In Uid And Timestamps
     * @param array $data Column Values
     * @param bool $creating Whether This Is An Insert
     * @return array
     */
    protected function stamp(array $data, bool $creating): array
    {
        $now = $this->now();
        $created = $this->createdColumn();
        $updated = $this->updatedColumn();

        if ($creating) {
            $uid = $this->uidColumn();

            // Only when the table actually carries the column, and only when
            // the caller has not supplied one - imports arrive with their uid
            // already assigned.
            if ($uid !== null && !array_key_exists($uid, $data)) {
                $data[$uid] = Uid::make();
            }

            if ($created !== null && !array_key_exists($created, $data)) {
                $data[$created] = $now;
            }
        }

        if ($updated !== null && !array_key_exists($updated, $data)) {
            $data[$updated] = $now;
        }

        return $data;
    }

    /**
     * The Current Time, In The Format Every Timestamp Column Expects
     *
     * The application timezone is applied to the connection by GlobalPipeline,
     * so a database NOW() and this agree.
     * @return string
     */
    protected function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * Normalise a Yes/No Flag
     *
     * The schemas use enum('yes','no') for booleans. A checkbox that is off is
     * absent from the POST body entirely, so this reads "missing" as "no"
     * rather than as "leave it alone".
     * @param mixed $value Submitted Value
     * @return string 'yes' or 'no'
     */
    protected function flag(mixed $value): string
    {
        return $this->boolean($value) ? 'yes' : 'no';
    }

    /**
     * What a Submitted Value Means As a Boolean
     *
     * Never empty(), and this is the reason: the checkbox macro pairs a hidden
     * field carrying the string "false" with a checkbox carrying "true", so an
     * unticked box submits the literal string 'false' - and empty('false') is
     * false, meaning !empty() reads it as ON. Unticking a setting would switch
     * it on. Only an explicit affirmative counts.
     * @param mixed $value Submitted Value
     * @return bool
     */
    protected function boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }

    /**
     * Keep Only Recognised Columns From Submitted Input
     *
     * A form post carries _csrf and whatever else the browser felt like adding;
     * handing that straight to insert() would be an unknown-column error at
     * best and a mass-assignment hole at worst.
     * @param array $input Submitted Data
     * @param string[] $columns Allowed Columns
     * @return array
     */
    protected function only(array $input, array $columns): array
    {
        $data = [];

        foreach ($columns as $column) {
            if (array_key_exists($column, $input)) {
                $value = $input[$column];
                $data[$column] = is_string($value) ? trim($value) : $value;
            }
        }

        return $data;
    }

    /**
     * Turn Empty Strings Into Nulls
     *
     * An untouched optional text field posts '', and '' in a nullable foreign
     * key column is a failed constraint rather than "not set".
     * @param array $data Column Values
     * @param string[] $columns Nullable Columns
     * @return array
     */
    protected function nullable(array $data, array $columns): array
    {
        foreach ($columns as $column) {
            if (array_key_exists($column, $data) && $data[$column] === '') {
                $data[$column] = null;
            }
        }

        return $data;
    }
}
