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
use LBM\Model\ClientNoteModel;
use LBM\Model\StaffModel;

/**
 * Notes staff leave on a client account.
 *
 * Append-only by design: a note records what somebody knew at a point in time,
 * so there is no edit path - a correction is a new note. Deleting one is
 * allowed because a note posted to the wrong client has to be removable.
 */
class ClientNote extends Action
{
    /** @var string[] Columns a Form May Write */
    public const FIELDS = ['note'];

    public function model(): Model
    {
        return new ClientNoteModel();
    }

    protected function searchable(): array
    {
        return ['note'];
    }

    protected function createdColumn(): ?string
    {
        return 'note_created_at';
    }

    ####################################################################################
    /*================================= EXTERNAL API =================================*/
    ####################################################################################

    /**
     * Every Note On a Client, Newest First, With Its Author
     *
     * The staff name is joined rather than looked up per row: a client with
     * forty notes would otherwise cost forty extra queries to render one page.
     * @param int $clientId Client ID
     * @param ?int $limit Row Limit
     * @return array
     */
    public function forClient(int $clientId, ?int $limit = null): array
    {
        $model = new ClientNoteModel();
        $staff = new StaffModel();

        $notes = $model->table;
        $staffs = $staff->table;

        return $model
            ->select([
                "{$notes}.*",
                "{$staffs}.first_name AS staff_first_name",
                "{$staffs}.last_name AS staff_last_name",
            ])
            ->join($staffs, "{$staffs}.{$staff->id}", '=', "{$notes}.staff_relid")
            ->where(["{$notes}.client_relid" => $clientId])
            ->order("{$notes}.{$model->id}", self::DESC)
            ->limit($limit && $limit > 0 ? $limit : data_limit())
            ->get();
    }

    /**
     * Add a Note
     * @param int $clientId Client ID
     * @param int $staffId Author Staff ID
     * @param string $note Note Body
     * @return int New Note ID
     */
    public function store(int $clientId, int $staffId, string $note): int
    {
        return $this->create([
            'client_relid' =>  $clientId,
            'staff_relid'  =>  $staffId,
            'note'         =>  trim($note),
        ]);
    }

    /**
     * Delete a Note, Scoped To Its Client
     * @param int|string $key Note ID Or Uid
     * @param int $clientId Client ID
     * @return int Affected rows
     */
    public function removeForClient(int|string $key, int $clientId): int
    {
        $model = $this->model();

        return $this->key($model, $key)->where(['client_relid' => $clientId])->delete();
    }
}
