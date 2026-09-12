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

namespace LBM\Support;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Throwable;
use RuntimeException;
use Laika\Service\Vault;
use LBM\Module\Api;
use LBM\Module\ModuleManager;
use LBM\Module\Contracts\Configurable;

/**
 * The ONE place a module's declared settings are read, checked, sealed and
 * opened - Phase 40.
 *
 * Four screens save module settings - gateways, registrars, a module's own
 * configure page, and the product form for server modules - and each keeps them
 * in the place that kind already had: `payment_gateways.settings`,
 * `domain_registrars.credentials`, `module_settings`, `products.module_config`.
 * What must not differ between them is the RULES, so the rules are here and the
 * four callers only decide where the result goes.
 *
 * ---------------------------------------------------------------------------
 * THE RULES
 * ---------------------------------------------------------------------------
 *   - Only what a module DECLARED is stored. A posted key it never asked for is
 *     dropped, so a form cannot be used to write arbitrary settings a driver
 *     might one day read.
 *   - A secret is sealed with Vault before it is stored, and no template is
 *     ever handed it - opened or sealed. forForm() is the only view a screen
 *     gets.
 *   - A blank secret box means KEEP what is saved: the form cannot show it, so
 *     an empty box meaning "clear" would wipe the key on every save
 *     (Server::modify()'s rule, and Phase 35's). Clearing is its own tick, and
 *     it wins over anything posted beside it.
 *   - A refusal names the field by its LABEL, which is what the operator sees.
 *
 * ---------------------------------------------------------------------------
 * OPENING IS FORGIVING IN BOTH DIRECTIONS, ON PURPOSE
 * ---------------------------------------------------------------------------
 * Before Phase 40 a gateway's settings were stored as typed, and a registrar's
 * credentials were ALL sealed. So a non-secret value that turns out to be sealed
 * is opened, and one that is not is used as it is - the Offline gateway's
 * instructions and a Phase 35 registrar's account name both read correctly
 * after the upgrade, with nothing migrated. A SECRET that will not open is
 * dropped instead: a stale key handed to a module is worse than none, and the
 * module saying "no key" is something an operator can fix by typing it again.
 */
final class ModuleSettings
{
    /** @var string[] The Field Types a Module May Declare */
    public const TYPES = ['text', 'password', 'textarea', 'select', 'yesno', 'number'];

    /** @var string[] Names No Field May Take - `mode` Is The Live/Test Switch */
    public const RESERVED = ['mode'];

    /** @var string What a Field Name May Look Like */
    public const NAME = '/^[A-Za-z][A-Za-z0-9_]{0,59}$/';

    ####################################################################################
    /*================================= EXTERNAL API =================================*/
    ####################################################################################

    /**
     * Whether a Class Declares Its Settings
     * @param string $class
     * @return bool
     */
    public static function configurable(string $class): bool
    {
        try {
            return $class !== '' && class_exists($class) && is_subclass_of($class, Configurable::class);
        } catch (Throwable) {
            // A module class that will not load is a broken module, not a
            // broken screen.
            return false;
        }
    }

    /**
     * The Fields a Class Declares, Normalised
     *
     * Empty for anything that is not Configurable, and for a declaration that
     * throws or is not an array - a module that cannot say what it needs must
     * not take down the screen that would have let somebody switch it off.
     * @param string $class
     * @return array<string,array{name:string,label:string,type:string,required:bool,default:?string,options:array<string,string>,secret:bool,help:string}>
     */
    public static function fields(string $class): array
    {
        if (!self::configurable($class)) {
            return [];
        }

        try {
            $declared = $class::settings();
        } catch (Throwable) {
            return [];
        }

        if (!is_array($declared)) {
            return [];
        }

        $fields = [];

        foreach ($declared as $name => $field) {
            $name = (string) $name;

            if (!is_array($field) || !preg_match(self::NAME, $name) || in_array(strtolower($name), self::RESERVED, true)) {
                continue;
            }

            $type = strtolower(trim((string) ($field['type'] ?? 'text')));
            $type = in_array($type, self::TYPES, true) ? $type : 'text';

            $options = [];

            foreach ((array) ($field['options'] ?? []) as $value => $label) {
                $options[(string) $value] = (string) $label;
            }

            // A dropdown with nothing in it can never be answered.
            if ($type === 'select' && $options === []) {
                $type = 'text';
            }

            $default = $field['default'] ?? null;
            $default = is_scalar($default) ? (string) $default : null;

            if ($type === 'yesno') {
                $default = self::yesNo($default ?? 'no');
            }

            $fields[$name] = [
                'name'     =>  $name,
                'label'    =>  trim((string) ($field['label'] ?? '')) ?: $name,
                'type'     =>  $type,
                'required' =>  (bool) ($field['required'] ?? false),
                'default'  =>  $default,
                'options'  =>  $options,
                'secret'   =>  $type === 'password' || !empty($field['secret']),
                'help'     =>  trim((string) ($field['help'] ?? '')),
            ];
        }

        return $fields;
    }

    /**
     * Check a Submission And Turn It Into What Is Stored
     *
     * @param array $fields From fields()
     * @param array $posted The Form's `settings[...]`, Name => Value
     * @param array $stored What Is Stored Now
     * @param array $clear Names Ticked For Clearing
     * @return array<string,string> What To Store - Secrets Sealed
     * @throws RuntimeException Naming The Field By Its Label
     */
    public static function merge(array $fields, array $posted, array $stored, array $clear = []): array
    {
        $clear = array_map(static fn ($name): string => (string) $name, $clear);
        $out = [];

        foreach ($fields as $name => $field) {
            $label = (string) $field['label'];
            $raw = $posted[$name] ?? null;
            $value = is_scalar($raw) ? trim((string) $raw) : '';
            $saved = is_string($stored[$name] ?? null) && $stored[$name] !== '';

            // Last in intent, first in code: a tick to clear beats whatever
            // was posted beside it - exactly what an edit form sends for a
            // saved secret, a blank box and a tick.
            if (in_array($name, $clear, true)) {
                if ($field['required']) {
                    throw new RuntimeException("{$label} is required, so it cannot be cleared.");
                }

                continue;
            }

            if ($field['secret']) {
                if ($value === '') {
                    if ($saved) {
                        $out[$name] = (string) $stored[$name];

                        continue;
                    }

                    if ($field['required']) {
                        throw new RuntimeException("{$label} is required.");
                    }

                    continue;
                }

                $out[$name] = Vault::encrypt($value);

                continue;
            }

            if ($value === '') {
                if ($field['required']) {
                    throw new RuntimeException("{$label} is required.");
                }

                // Nothing stored, so the declared default applies on open.
                continue;
            }

            $out[$name] = match ($field['type']) {
                'select' => isset($field['options'][$value])
                    ? $value
                    : throw new RuntimeException("{$label}: choose one of the options offered."),
                'yesno'  => self::yesNo($value),
                'number' => is_numeric($value)
                    ? $value
                    : throw new RuntimeException("{$label} must be a number."),
                default  => $value,
            };
        }

        return $out;
    }

    /**
     * What a Driver Is Constructed With
     *
     * Every declared field that has a value, opened, with the declared default
     * where nothing is saved. Add `mode` beside it at the call site.
     * @param array $fields From fields()
     * @param array $stored What Is Stored
     * @return array<string,string>
     */
    public static function open(array $fields, array $stored): array
    {
        $open = [];

        foreach ($fields as $name => $field) {
            $value = $stored[$name] ?? null;

            if (!is_string($value) || $value === '') {
                if (!$field['secret'] && $field['default'] !== null) {
                    $open[$name] = (string) $field['default'];
                }

                continue;
            }

            if ($field['secret']) {
                $plain = self::unseal($value);

                if ($plain !== null) {
                    $open[$name] = $plain;
                }

                continue;
            }

            $open[$name] = self::unseal($value) ?? $value;
        }

        return $open;
    }

    /**
     * What a Form May Show
     *
     * Each field's definition, plus `value` - the saved value for anything that
     * is not a secret, and ALWAYS '' for one that is - and `saved`, whether a
     * usable value is stored. The only view of module settings any template is
     * given.
     * @param array $fields From fields()
     * @param array $stored What Is Stored
     * @return array<string,array>
     */
    public static function forForm(array $fields, array $stored): array
    {
        $form = [];

        foreach ($fields as $name => $field) {
            $value = $stored[$name] ?? null;
            $has = is_string($value) && $value !== '';

            if ($field['secret']) {
                $form[$name] = $field + ['value' => (string) $value, 'saved' => $has && self::unseal($value) !== null];

                continue;
            }

            $form[$name] = $field + [
                'value' =>  $has ? (string) (self::unseal($value) ?? $value) : (string) ($field['default'] ?? ''),
                'saved' =>  $has,
            ];
        }

        return $form;
    }

    /**
     * The Mode a Stored Value Means - `test` Or `live`
     * @param mixed $value
     * @return string
     */
    public static function mode(mixed $value): string
    {
        return Api::modeOf($value);
    }

    /**
     * The Class a Loaded Module Declares
     *
     * The one resolver behind every driver builder: a module name becomes a
     * class only when that module is switched on AND loaded this request, so a
     * module somebody switched off cannot be constructed by anything. Whether
     * the class implements the right contract is still each caller's check -
     * the answer differs per kind.
     * @param string $type Module Kind - One Of ModuleManager::TYPES
     * @param string $directory Module Directory, In Any Case
     * @return ?string
     */
    public static function loadedClass(string $type, string $directory): ?string
    {
        $directory = trim($directory);

        if ($directory === '') {
            return null;
        }

        $wanted = ModuleManager::uid($type, $directory);

        foreach (ModuleManager::loaded() as $uid => $meta) {
            if (($meta['type'] ?? '') !== $type || strtolower((string) $uid) !== $wanted) {
                continue;
            }

            $class = trim((string) ($meta['class'] ?? ''));

            return $class === '' ? null : $class;
        }

        return null;
    }

    /**
     * Run a Driver's Connection Test, Safely
     *
     * A module's own words come back as they are; a driver that is not
     * Configurable, that throws, or that says nothing is reported in ours.
     * English on purpose - cron and the CLI reach Support, and no catalogue is
     * loaded there.
     * @param ?object $driver
     * @return array{success: bool, message: string}
     */
    public static function test(?object $driver): array
    {
        if (!$driver instanceof Configurable) {
            return ['success' => false, 'message' => 'This module has no connection test.'];
        }

        try {
            $result = $driver->test();
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'The test threw: ' . $e->getMessage()];
        }

        $success = is_array($result) && ($result['success'] ?? false) === true;
        $message = is_array($result) ? trim((string) ($result['message'] ?? '')) : '';

        return [
            'success' =>  $success,
            'message' =>  $message !== ''
                ? $message
                : ($success ? 'The module reported success.' : 'The module reported a failure and said nothing more.'),
        ];
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Open a Sealed Value
     *
     * Vault::decrypt() throws on anything it did not seal - which is exactly
     * how a value stored as typed by an older release is told from a sealed
     * one.
     * @param string $value
     * @return ?string Null When It Will Not Open
     */
    private static function unseal(string $value): ?string
    {
        try {
            $plain = Vault::decrypt($value);
        } catch (Throwable) {
            return null;
        }

        return is_string($plain) && $plain !== '' ? $plain : null;
    }

    /**
     * yes Or no
     * @param string $value
     * @return string
     */
    private static function yesNo(string $value): string
    {
        return in_array(strtolower(trim($value)), ['yes', 'true', '1', 'on'], true) ? 'yes' : 'no';
    }
}
