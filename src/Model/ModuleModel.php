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

namespace LBM\Model;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Model;

class ModuleModel extends Model
{
    // Table Name
    protected string $table = 'modules';

    // Primary Column Name
    protected string $id = 'module_id';

    /**
     * @var string UID Column Name
     *
     * The uid is DERIVED from where the module sits rather than generated, so
     * unlike every other table in this product it is not a Uid::make() value -
     * `gateways/Stripe` is `gateways-stripe` on every installation. That is
     * what makes it the join key the loader's cache can be projected from.
     */
    protected string $uid = 'uid';

    /** @var string Database Connection Name */
    protected string $connection = 'default';

    /** @var bool Soft Delete */
    protected bool $softDelete = false;

    /** @var string Deleted At Column */
    protected string $deletedAtColumn = 'deleted_at';

    /** @var array<string,string> Casts, derived from the column types in the schema */
    protected array $casts = [
        'module_id'  =>  'int',
    ];

    // Start Code From Here
}
