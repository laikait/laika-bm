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

use LBM\Service\Status;

####################################################################################
/*------------------------------------ STATUSES ----------------------------------*/
####################################################################################
//
// Instruction 7: every status column has a companion table carrying the status
// name and its colour, so no screen hardcodes either. These are the render path
// for that - a list row calls status_badge() and gets back everything it needs
// to paint the pill.

/**
 * Everything Needed To Render a Status Pill
 * @param string $table Lookup Table Name. Example: 'invoice_statuses'
 * @param int|string|null $relid The parent row's *_relid
 * @return array{name:string,label:string,color:string,text:string}
 */
function status_badge(string $table, int|string|null $relid): array
{
    $name = Status::name($table, $relid);
    $color = Status::color($table, $relid);

    return [
        'name'  =>  $name,
        'label' =>  status_label($name),
        'color' =>  $color,
        'text'  =>  contrast_color($color),
    ];
}

/**
 * A Status Name
 * @param string $table Lookup Table Name
 * @param int|string|null $relid The parent row's *_relid
 * @param string $default Returned When The Id Does Not Resolve
 * @return string
 */
function status_name(string $table, int|string|null $relid, string $default = ''): string
{
    return Status::name($table, $relid, $default);
}

/**
 * A Status Colour
 * @param string $table Lookup Table Name
 * @param int|string|null $relid The parent row's *_relid
 * @return string Hex colour
 */
function status_color(string $table, int|string|null $relid): string
{
    return Status::color($table, $relid);
}

/**
 * Every Row In a Lookup Table
 *
 * What the status filter dropdown on each list screen is built from.
 * @param string $table Lookup Table Name
 * @return array<int,array{id:int,name:string,color:string}>
 */
function status_list(string $table): array
{
    return Status::all($table);
}

/**
 * Resolve a Status Id By Name
 *
 * Lets code say 'paid' instead of carrying whatever integer the seed assigned.
 * @param string $table Lookup Table Name
 * @param string $name Status Name
 * @return ?int
 */
function status_id(string $table, string $name): ?int
{
    return Status::idOf($table, $name);
}

/**
 * Humanise a Status Name For Display
 *
 * Status names are stored lowercase and underscored ('customer_reply'), which is
 * what code should match on. This is only for the label.
 * @param string $name Status Name
 * @return string
 */
function status_label(string $name): string
{
    return ucwords(str_replace('_', ' ', $name));
}

/**
 * Pick Readable Text For a Background Colour
 *
 * Status colours are operator-editable, so a pill can end up amber one day and
 * dark red the next. Choosing the text colour from the background's perceived
 * brightness keeps the label readable either way, instead of hardcoding white
 * and hoping.
 * @param string $hex Background Colour. Example: '#ffc107'
 * @return string '#000000' or '#ffffff'
 */
function contrast_color(string $hex): string
{
    $hex = ltrim(trim($hex), '#');

    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }

    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
        return '#ffffff';
    }

    // ITU-R BT.601 luma - green dominates perceived brightness, so a plain
    // average of the channels would call amber dark and print white on it.
    $luma = (
        0.299 * hexdec(substr($hex, 0, 2))
        + 0.587 * hexdec(substr($hex, 2, 2))
        + 0.114 * hexdec(substr($hex, 4, 2))
    );

    return $luma > 150 ? '#000000' : '#ffffff';
}
