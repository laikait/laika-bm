<?php

declare(strict_types=1);

use LBM\Model\StaffRoleModel;
use Laika\Auth\AuthManager;
use Laika\Session\Session;
use Laika\Service\Request;

/*=============================== ADMIN INFO ===============================*/
/**
 * Get Logged-in Staff Info
 * @return ?array
 */
function current_staff(): ?array
{
    static $staff = null;

    if ($staff === null) {
        $guard = (new AuthManager(config('auth')))->guard('staff');
        $token = Session::get(ADMIN . '_token', for:ADMIN);

        $staff = $guard->validateToken($token, (int) option('login_lifetime', '3600'), option_bool('strict_ip'));
    }
    return $staff;
}

/*================================= ACCESS =================================*/

/**
 * Check Staff Has Access
 * @param string $access Access Name. Example: 'product.read'
 */
function staff_has_access(string $access): bool
{
    static $accesses = null;

    // Validate Parameter
    if (!preg_match('/^[a-z]+\.[a-z]+$/i', $access)) {
        throw new InvalidArgumentException("Invalid Parameter [{$access}] in Function " . __FUNCTION__ . ". Example: 'staff.create'");
    }

    [$key, $action] = explode('.', $access, 2);

    if ($accesses === null) {
        $m = new StaffRoleModel();
        $accesses = $m->select('permissions')->find(current_staff()['role_relid'])['permissions'];
    }

    if (isset($accesses[$key]) && is_array($accesses[$key])) {
        return $accesses[$key][$action] ?? false;
    }
    return false;
}

/**
 * Match Database Columns with Queries
 * @param array<string:string> $keyValuePair [query_key => db_column...]. Example: ['id' => 'note_id']
 * @return array
 */
function query_to_columns(array $keyValuePair): array
{
    $keys = [];
    // Get Accepted Query Values
    foreach(Request::inputs() as $k => $v) {
        if (!$v) {
            continue;
        }
        if (in_array($k, array_keys($keyValuePair))) {
            $keys[$keyValuePair[$k]] = $v;
        }
    }
    return $keys;
}

/**
 * Make PI Chart From Data
 * @param array<int, array{label: string, total: int, color: string}> $data
 * Example: array(array('label'=>'paid', 'total'=>5, ));
 * @return array{circumf: float|int, offset: float|int, arc: array{color: string, dash: float|int, gap: float|int, offset: float|int}}
 */
function pi_chart(array $data) {
    $count = array_sum(array_column($data, 'total'));
    $circumf = 2 * M_PI * 50;
    $offset  = 0;
    $arcs = [];
    foreach ($data as $d) {
        $dash = (int) $d['total'] / $count * $circumf;
        $arcs[] = [
            'color'  => $d['color'],
            'dash'   => round($dash, 2),
            'gap'    => round($circumf - $dash, 2),
            'offset' => round(-$offset, 2),
        ];
        $offset += $dash;
    }
    return [
        'circumf' => $circumf,
        'offset' => $offset,
        'arc' => $arcs
    ];
};