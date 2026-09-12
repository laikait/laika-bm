<?php

declare(strict_types=1);

namespace LBM\Model;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403).die('403 Direct Access Denied!');

use Laika\Model\Model;

class ModuleSettingModel extends Model
{
    // Table Name
    protected string $table = 'module_settings';

    // Primary Column Name
    protected string $id = 'ms_id';

    protected string $connection = 'default';

    protected bool $softDelete = false;

    protected string $deletedAtColumn = 'deleted_at';

    protected array $casts = [
        'ms_id'        =>  'int',
        'module_relid' =>  'int',
    ];

    // Start Code From Here
}
