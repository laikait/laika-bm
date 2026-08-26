<?php

/**
 * Laika PHP MVC Framework
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: MIT
 * This file is part of the Laika PHP MVC Framework.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

use LBM\Service\Activity;

/**
 * Get Single Activity
 * @param int|string $id
 * @return array
 */
function get_activity(int|string $id): array
{
    // Validate Parameter
    if (!is_numeric($id) || (int) $id < 1) return [];

    static $activities = [];
    $id = (int) $id;

    if (!isset($activities[$id])) $activities[$id] = Activity::single($id);
    return $activities[$id];
}

/**
 * Get Latest Activities
 * @param ?string $limit
 * @return array
 */
function get_latest_activities(?string $limit): array
{
    static $activities = [];

    if (is_null($limit)) {
        if (!isset($activities['latest'])) $activities['latest'] = Activity::latest();
        return $activities['latest'];
    }

    $limit = (int) $limit;
    // Validate Parameter
    if ($limit < 1) return [];
    $key = "latest_$limit";
    if (!isset($activities[$key])) $activities[$key] = Activity::latest($limit);
    return $activities[$key];
}

/**
 * Get Activities By Author Type
 * @param string $type Activity Creator Type. Accepted Values: 'client', 'staff', 'system'
 * @return array
 */
function get_activities_by_author_type(string $type): array
{
    static $activities = [];
    $type = strtolower($type);
    // Validate Parameter
    if (!in_array($type, ['client', 'staff', 'system'])) return [];
    // Get Activities By Author Type
    if (!isset($activities[$type])) $activities[$type] = Activity::byType($type);
    return $activities[$type];
}

/**
 * Get Activities By Author
 * @param string $type Activity Type. Accepted Values: 'client', 'staff', 'system'
 * @param ?int $id Creator ID. Example: Client ID, Staff ID, Default is Null for System Activities
 * @return array
 */
function get_activities_by_author(string $type, ?int $id = null): array
{
    static $activities = [];
    $type = strtolower($type);
    // Validate Parameter
    if (!in_array($type, ['client', 'staff', 'system'])) throw new InvalidArgumentException("Invalid Type [{$type}]. Accepted Types Are ['client', 'staff', 'system']");

    $key = $type . '_' . ($id ?? null);
    if (!isset($activities[$key])) $activities[$key] = Activity::byTypeAndId($type, $id);

    return $activities[$key];
}
