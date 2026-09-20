<?php
/**
 * Laika Bill Manager
 * Author: Showket Ahmed
 * Email: riyadhtayf@gmail.com
 * License: Proprietary - see LICENSE
 * This file is part of Laika Bill Manager.
 * For the full copyright and license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace LBM\Module;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

/**
 * Account names and passwords for server modules - Phase 54.
 *
 * Every control panel wants a username LBM has to invent and a password strong
 * enough for its own policy. Written once here so the cPanel, DirectAdmin and
 * Plesk modules cannot drift apart on either.
 *
 * THE USERNAME IS DETERMINISTIC. It is letters from the domain plus the service
 * id in base 36 - `example` + `1c` for service 48 - never random. A create call
 * can succeed on the panel and still be retried (the reply was lost, cron was
 * killed), and a retry that invented a fresh name would build a second account.
 * The same name instead gets "already exists", which the module can check is
 * its own account and report as the success it was.
 */
final class Accounts
{
    /** @var string Letters And Digits For Passwords - No Look-Alikes */
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** @var string Symbols Every Panel Accepts, And That Survive Shells And URLs */
    private const SYMBOLS = '!@#%^*-_=+';

    /**
     * A Username For a Service
     *
     * Lower-case letters and digits, starting with a letter, at most $max long -
     * the rule cPanel (16), DirectAdmin (10) and Plesk all accept. A name that
     * would start with `test` gets a leading `u`: cPanel refuses those outright.
     * @param string $domain The Service's Domain, Or ''
     * @param int $serviceId Makes The Name Unique And Repeatable
     * @param int $max Longest The Panel Allows
     * @return string
     */
    public static function username(string $domain, int $serviceId, int $max = 16): string
    {
        $suffix = base_convert((string) max(1, $serviceId), 10, 36);
        $label = strtolower(explode('.', trim($domain))[0] ?? '');
        $letters = ltrim((string) preg_replace('/[^a-z0-9]/', '', $label), '0123456789');

        if ($letters === '') {
            $letters = 'user';
        }

        if (str_starts_with($letters, 'test')) {
            $letters = 'u' . $letters;
        }

        $room = max(1, min(8, $max - strlen($suffix)));

        return substr($letters, 0, $room) . $suffix;
    }

    /**
     * A Password Every Panel Accepts
     *
     * At least one digit, one upper and one lower case letter and two symbols,
     * guaranteed rather than hoped for - panels refuse a password missing any.
     * @param int $length At Least 12
     * @return string
     */
    public static function password(int $length = 20): string
    {
        $length = max(12, $length);
        $chars = [];

        for ($i = 0; $i < $length - 2; $i++) {
            $chars[] = self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        for ($i = 0; $i < 2; $i++) {
            $chars[] = self::SYMBOLS[random_int(0, strlen(self::SYMBOLS) - 1)];
        }

        $chars[0] = (string) random_int(2, 9);
        $chars[1] = 'ABCDEFGHJKLMNPQRSTUVWXYZ'[random_int(0, 23)];
        $chars[2] = 'abcdefghjkmnpqrstuvwxyz'[random_int(0, 22)];

        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }
}
