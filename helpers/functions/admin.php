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

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Laika\Service\Request;
use LBM\Pipeline\Auth;
use LBM\Service\Permission;

####################################################################################
/*--------------------------------- ADMIN IDENTITY -------------------------------*/
####################################################################################

/**
 * The Signed-In Staff Member
 *
 * Delegates to the Auth pipeline rather than re-reading the session and
 * re-validating the token. Auth owns the session key name, the TTL and the
 * strict-IP flag, and memoises the result per area - so a template calling this
 * twenty times costs one lookup, and the helper can never drift out of sync with
 * the pipeline that issued the token.
 * @return ?array
 */
function current_staff(): ?array
{
    return Auth::user(ADMIN);
}

/**
 * The Signed-In Staff Member's Role Id
 * @return ?int
 */
function current_staff_role(): ?int
{
    $staff = current_staff();

    return isset($staff['role_relid']) ? (int) $staff['role_relid'] : null;
}

/**
 * Whether Somebody Is Signed Into The Admin Area
 * @return bool
 */
function is_staff(): bool
{
    return current_staff() !== null;
}

####################################################################################
/*------------------------------------ ACCESS ------------------------------------*/
####################################################################################

/**
 * Whether The Signed-In Staff Member Holds an Access
 *
 * The one implementation lives in LBM\Support\Permission, which is also what the
 * Permission pipeline calls - so a screen that hides a button and the route that
 * refuses the request can never disagree.
 * @param string $access Access Name. Example: 'invoice.read'
 * @return bool
 */
function staff_has_access(string $access): bool
{
    return Permission::allows(current_staff_role(), $access);
}

####################################################################################
/*------------------------------------ LISTINGS ----------------------------------*/
####################################################################################

/**
 * Map Request Query Keys Onto Database Columns
 *
 * Search screens are GET (instruction 17), and this is what turns the query
 * string into a where() array - only for keys the caller explicitly allows, so a
 * crafted query cannot filter on a column the screen never offered.
 * @param array<string,string> $keyValuePair [query_key => db_column]. Example: ['id' => 'note_id']
 * @return array<string,mixed>
 */
function query_to_columns(array $keyValuePair): array
{
    $columns = [];

    foreach (Request::inputs() as $key => $value) {
        if ($value === null || $value === '' || !isset($keyValuePair[$key])) {
            continue;
        }

        $columns[$keyValuePair[$key]] = $value;
    }

    return $columns;
}

####################################################################################
/*------------------------------------- CHARTS -----------------------------------*/
####################################################################################

/**
 * Build The Arcs For a Donut Chart
 *
 * Returns stroke-dasharray/offset values for an SVG circle of radius 50, so the
 * dashboard draws its charts inline with no JavaScript charting library.
 * @param array<int,array{label:string,total:int|string,color:string}> $data Slices
 * @return array{circumf:float,total:int,arc:array<int,array{label:string,color:string,dash:float,gap:float,offset:float,percent:float}>}
 */
function pi_chart(array $data): array
{
    $circumf = 2 * M_PI * 50;
    $total = 0;

    foreach ($data as $slice) {
        $total += (int) ($slice['total'] ?? 0);
    }

    // A fresh install has no invoices and no orders, so every slice is zero.
    // Dividing by that total would fatal on the first dashboard render.
    if ($total === 0) {
        return ['circumf' => $circumf, 'total' => 0, 'arc' => []];
    }

    $offset = 0.0;
    $arcs = [];

    foreach ($data as $slice) {
        $value = (int) ($slice['total'] ?? 0);

        if ($value === 0) {
            continue;
        }

        $dash = $value / $total * $circumf;

        $arcs[] = [
            'label'   =>  (string) ($slice['label'] ?? ''),
            'color'   =>  (string) ($slice['color'] ?? '#6c757d'),
            'dash'    =>  round($dash, 2),
            'gap'     =>  round($circumf - $dash, 2),
            'offset'  =>  round(-$offset, 2),
            'percent' =>  round($value / $total * 100, 1),
        ];

        $offset += $dash;
    }

    return ['circumf' => $circumf, 'total' => $total, 'arc' => $arcs];
}
