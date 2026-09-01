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
use Laika\Service\Option;
use LBM\Service\Mailer;
use LBM\Service\Money;
use LBM\Service\Status;

/**
 * Every system setting, stored in the `options` table (instruction 14).
 *
 * This is the single place a setting is written. Two things make that worth
 * enforcing rather than letting controllers call Option directly.
 *
 * First, booleans. option_bool() matches the literal string 'true' and nothing
 * else - 1, 'yes' and 'on' all read back as false - so a checkbox has to be
 * normalised on the way in. Doing that per-controller is how half the settings
 * screens end up silently unable to turn a flag on.
 *
 * Second, Option::insert() decides whether a key exists with a truthiness test
 * rather than a lookup, so a key already stored as an empty string looks absent
 * and a second INSERT dies on the primary key. put() tries UPDATE first, which
 * tests existence properly.
 *
 * Note what this class does *not* do: read caching. option() already memoises
 * per key for the whole request, which is why every save ends in a redirect
 * rather than a re-render - the value written here is not what option() would
 * hand back later in the same request.
 */
class Setting extends Action
{
    /**
     * @var string[] Settings Stored As 'true'/'false' Strings
     *
     * Every key here is one something actually reads. A setting nothing acts on
     * is worse than a missing one: the screen shows a switch, the operator flips
     * it, and nothing happens.
     */
    public const BOOLEANS = [
        'strict_ip', 'allow_registration', 'mail_keepalive', 'mail_auto_tls',
        'mail_validate_cert',
    ];

    /** @var array<string,string[]> Which Keys Belong To Which Settings Screen */
    public const GROUPS = [
        'general' => [
            'app_name', 'app_host', 'app_email', 'app_logo', 'app_icon',
            'front_template', 'admin_template', 'panel_template',
        ],
        'localisation' => [
            'time_zone', 'date_format', 'datetime_format', 'time_format',
            'default_language', 'decimal_symbol', 'thousand_separator',
            'data_limit', 'default_currency',
        ],
        'billing' => [
            'invoice_prefix', 'order_prefix', 'ticket_prefix', 'invoice_due_days',
            'late_fee_percent', 'invoice_generate_days', 'invoice_reminder_days',
        ],
        'security' => [
            'login_lifetime', 'strict_ip', 'password_min_length',
            'allow_registration',
        ],
        'mail' => [
            'mail_driver', 'mail_host', 'mail_port', 'mail_username', 'mail_password',
            'mail_encryption', 'mail_from', 'mail_from_name', 'mail_charset',
            'mail_timeout', 'mail_debug', 'mail_keepalive', 'mail_auto_tls',
            'mail_validate_cert',
        ],
    ];

    /**
     * The Options Table, As a Model
     *
     * Only so the base class has something to build on - nothing here paginates
     * or searches options. Reads go through option(), writes through put().
     * @return Model
     */
    public function model(): Model
    {
        return (new Model())->table('options');
    }

    ####################################################################################
    /*================================= EXTERNAL API =================================*/
    ####################################################################################

    /**
     * Read a Setting
     * @param string $key Option Key
     * @param ?string $default Fallback
     * @return ?string
     */
    public function get(string $key, ?string $default = null): ?string
    {
        return option($key, $default);
    }

    /**
     * Read a Setting As An Integer
     * @param string $key Option Key
     * @param int $default Fallback
     * @return int
     */
    public function int(string $key, int $default = 0): int
    {
        return option_int($key, $default);
    }

    /**
     * Read a Setting As a Boolean
     * @param string $key Option Key
     * @return bool
     */
    public function bool(string $key): bool
    {
        return option_bool($key);
    }

    /**
     * Write One Setting
     *
     * Update first, then insert. Option::insert() tests existence with a bare
     * truthiness check on the current value, so a key stored as '' looks absent
     * to it and the insert collides with the primary key already there.
     * @param string $key Option Key
     * @param mixed $value Value
     * @return void
     */
    public function put(string $key, mixed $value): void
    {
        $value = $this->encode($key, $value);

        if (!Option::update($key, $value)) {
            Option::insert($key, $value);
        }
    }

    /**
     * Write Several Settings At Once
     * @param array<string,mixed> $values Option Key => Value
     * @return int How many were written
     */
    public function putMany(array $values): int
    {
        $written = 0;

        foreach ($values as $key => $value) {
            $this->put((string) $key, $value);
            $written++;
        }

        $this->flushCaches();

        return $written;
    }

    /**
     * Save One Settings Screen
     *
     * Only the keys that screen owns are written, whatever else the POST body
     * carried. A submitted key that is not in the group is ignored rather than
     * silently creating an option nothing reads - which is also what stops a
     * crafted form from writing, say, mail_password from the general tab.
     *
     * Every boolean in the group is written whether or not it was submitted: an
     * unchecked box sends nothing at all, so skipping absent keys would make a
     * flag impossible to turn off.
     * @param string $group One of the GROUPS keys
     * @param array $input Submitted Data
     * @return int How many were written
     */
    public function saveGroup(string $group, array $input): int
    {
        $keys = self::GROUPS[$group] ?? [];

        if ($keys === []) {
            return 0;
        }

        $values = [];

        foreach ($keys as $key) {
            $isBoolean = in_array($key, self::BOOLEANS, true);

            if (!array_key_exists($key, $input) && !$isBoolean) {
                continue;
            }

            // boolean(), not !empty(): the checkbox macro submits the literal
            // string 'false' for an unticked box, and empty('false') is false -
            // so !empty() would read "off" as on, and unticking a setting would
            // switch it on.
            $values[$key] = $isBoolean
                ? $this->boolean($input[$key] ?? false)
                : $input[$key];
        }

        return $this->putMany($values);
    }

    /**
     * The Current Values For One Settings Screen
     * @param string $group One of the GROUPS keys
     * @return array<string,?string>
     */
    public function group(string $group): array
    {
        $values = [];

        foreach (self::GROUPS[$group] ?? [] as $key) {
            $values[$key] = option($key, '');
        }

        return $values;
    }

    /**
     * Drop Every Cache That Holds a Setting
     *
     * The support singletons memoise for the request - Money the currency list
     * and its rates, Status the lookup tables, the mailer its whole SMTP
     * configuration. Saving a setting without this leaves the old value in play
     * for the rest of the request, which is exactly the request that renders the
     * result of the save.
     * @return void
     */
    public function flushCaches(): void
    {
        Money::flush();
        Status::flush();
        Mailer::flush();
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Coerce a Value To What The Options Table Stores
     *
     * Booleans become the literal strings 'true' and 'false', because
     * option_bool() is `preg_match('/^true$/i', ...)` and reads 1, 'yes' and
     * 'on' as false.
     * @param string $key Option Key
     * @param mixed $value Value
     * @return string
     */
    private function encode(string $key, mixed $value): string
    {
        if (is_bool($value) || in_array($key, self::BOOLEANS, true)) {
            $truthy = is_bool($value)
                ? $value
                : in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);

            return $truthy ? 'true' : 'false';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR);
        }

        return trim((string) $value);
    }
}
