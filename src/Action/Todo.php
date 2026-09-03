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
use LBM\Model\TodoModel;
use Laika\Service\Uid;

/**
 * A staff member's own to-do list.
 *
 * Every method here takes the staff id and every query is scoped to it. That is
 * not a convenience: the ownership test lives inside the query, so a controller
 * cannot forget the second half of it and hand somebody another person's list -
 * the same rule ClientController::mine() enforces for the client area.
 *
 * There is deliberately no "all todos" reader. The moment one exists, a screen
 * will use it.
 */
class Todo extends Action
{
    /** @var string[] Columns a Form May Write */
    public const FIELDS = ['title', 'notes', 'due_on'];

    public function model(): Model
    {
        return new TodoModel();
    }

    protected function searchable(): array
    {
        return ['title', 'notes'];
    }

    protected function createdColumn(): ?string
    {
        return 'todo_created_at';
    }

    protected function updatedColumn(): ?string
    {
        return 'todo_updated_at';
    }

    ####################################################################################
    /*================================= EXTERNAL API =================================*/
    ####################################################################################

    /**
     * One Staff Member's Items
     *
     * Outstanding first, then by due date with undated items last, then newest
     * first. Sorted here rather than in the template because a list is only
     * useful in the order somebody would work through it, and a template that
     * sorted would have to be repeated in every theme.
     * @param int $staffId Staff ID
     * @param bool $includeDone Whether Finished Items Come Too
     * @return array
     */
    public function forStaff(int $staffId, bool $includeDone = true): array
    {
        $model = new TodoModel();
        $model->where(['staff_relid' => $staffId]);

        if (!$includeDone) {
            $model->where(['is_done' => 'no']);
        }

        $rows = $model->order($model->id, self::DESC)->get();

        usort($rows, static function (array $a, array $b): int {
            // Outstanding work first. A finished list that buries today's work
            // under last week's ticks is not a to-do list.
            $done = ($a['is_done'] === 'yes' ? 1 : 0) <=> ($b['is_done'] === 'yes' ? 1 : 0);

            if ($done !== 0) {
                return $done;
            }

            // An item with no due date is not overdue, so it sorts after every
            // dated one rather than to the top as an empty string would.
            $aDue = (string) ($a['due_on'] ?? '');
            $bDue = (string) ($b['due_on'] ?? '');

            if ($aDue !== $bDue) {
                if ($aDue === '') {
                    return 1;
                }

                if ($bDue === '') {
                    return -1;
                }

                return strcmp($aDue, $bDue);
            }

            return (int) $b['todo_id'] <=> (int) $a['todo_id'];
        });

        return $rows;
    }

    /**
     * Find One Of a Staff Member's Own Items
     *
     * The staff id is part of the query, so another person's item is not found
     * rather than found-and-refused. There is no way to call this that returns
     * a row belonging to somebody else.
     * @param int|string $key Todo ID Or Uid
     * @param int $staffId Staff ID
     * @return ?array
     */
    public function forStaffKey(int|string $key, int $staffId): ?array
    {
        $row = $this->key($this->model(), $key)->where(['staff_relid' => $staffId])->first();

        return is_array($row) ? $row : null;
    }

    /**
     * Add an Item
     * @param int $staffId Staff ID
     * @param array $input Submitted Data
     * @return int Todo ID, or 0 when there was nothing to add
     */
    public function add(int $staffId, array $input): int
    {
        $data = $this->fields($input);
        $title = trim((string) ($data['title'] ?? ''));

        if ($title === '' || $staffId <= 0) {
            return 0;
        }

        $notes = trim((string) ($data['notes'] ?? ''));
        $due = $this->due((string) ($data['due_on'] ?? ''));

        return (int) $this->model()->insert($this->stamp([
            'uid'         =>  Uid::make(),
            'staff_relid' =>  $staffId,
            'title'       =>  $title,
            'notes'       =>  $notes === '' ? null : $notes,
            'due_on'      =>  $due,
            'is_done'     =>  'no',
        ], true));
    }

    /**
     * Tick Or Untick An Item
     *
     * done_at is cleared on the way back so an item reopened and finished again
     * records when it was actually finished, not the first time somebody
     * thought it was.
     * @param array $todo Todo Row
     * @param ?bool $done Null flips whatever it is now
     * @return bool The state it ended up in
     */
    public function toggle(array $todo, ?bool $done = null): bool
    {
        $state = $done ?? ($todo['is_done'] !== 'yes');

        $this->update((int) $todo['todo_id'], [
            'is_done' =>  $state ? 'yes' : 'no',
            'done_at' =>  $state ? $this->now() : null,
        ]);

        return $state;
    }

    /**
     * How Many Items Are Still Outstanding
     * @param int $staffId Staff ID
     * @return int
     */
    public function outstanding(int $staffId): int
    {
        return $this->count(['staff_relid' => $staffId, 'is_done' => 'no']);
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * The Columns a Form May Write, And Nothing Else
     *
     * Each action carries its own, over the shared only() - the base class has
     * no fields() to inherit.
     * @param array $input Submitted Data
     * @return array
     */
    private function fields(array $input): array
    {
        return $this->only($input, self::FIELDS);
    }

    /**
     * Read a Due Date From The Form
     *
     * Anything unparseable becomes no date at all rather than today. A typo in
     * a date field should leave the item undated, not silently due now.
     * @param string $value Submitted Value
     * @return ?string Y-m-d, or null
     */
    private function due(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $time = strtotime($value);

        return $time === false ? null : date('Y-m-d', $time);
    }
}
