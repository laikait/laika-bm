<?php
/**
 * Laika Framework
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: MIT
 * This file is part of the Laika Framework.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace LBM\Model;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Model;

/**
 * The `activities` audit trail.
 *
 * The table itself belongs to laika-core (Laika\Core\Schema\ActivitySchema) and
 * is written through Laika\Service\Activity, which builds its rows by hand on a
 * bare Model. This exists only so LBM can *read* the trail with the same casts,
 * pagination and search as everything else - there is no LBM schema for the
 * table, because Infra::discover() keys schemas by table name and a second one
 * would silently replace the framework's.
 *
 * Note the empty $uid: this is the one LBM table with no uid column, so the
 * default 'uid' would produce an unknown-column error the first time anything
 * tried to look a row up by one.
 */
class ActivityModel extends Model
{
    // Table Name
    protected string $table = 'activities';

    // Primary Column Name
    protected string $id = 'log_id';

    /** @var string UID Column Name. The table has none */
    protected string $uid = '';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    /** @var bool Soft Delete */
    protected bool $softDelete = false;

    /** @var string Deleted At Column */
    protected string $deletedAtColumn = 'deleted_at';

    /** @var array<string,string> Casts, derived from the column types in the schema */
    protected array $casts = [
        'log_id'    =>  'int',
        'author_id' =>  'int',
        'changes'   =>  'serialize',
    ];

    // Start Code From Here
}
