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

use Throwable;
use RuntimeException;
use Laika\Model\Model;
use Laika\Service\Vault;
use LBM\Model\DomainModel;
use LBM\Model\DomainRegistrarModel;
use LBM\Model\TldModel;
use LBM\Module\Api;
use LBM\Module\Contracts\RegistrarInterface;
use LBM\Support\ModuleSettings;
use LBM\Support\RegistersDomains;

/**
 * The registrars domains are registered through, and what each one's module
 * logs in with.
 *
 * `domain_registrars` has existed since Phase 0 and until Phase 35 had no
 * writer outside the test harnesses - no screen, no route, no action. Every TLD
 * must point at a registrar, so a fresh install could not sell a single domain
 * without somebody typing a row into the database by hand.
 *
 * ---------------------------------------------------------------------------
 * CREDENTIALS ARE ENCRYPTED, AND THEY ARE HANDED TO THE MODULE
 * ---------------------------------------------------------------------------
 * The second half is the finding. Nothing in the product read `credentials`
 * before this class: `RegistersDomains::registrarDriver()` built every driver
 * with no arguments, so a real registrar module was never given its own API
 * key. `settingsFor()` is what it is built with now.
 *
 * Each value is sealed with Laika\Service\Vault, as the server screen seals a
 * root password, and the NAMES stay readable. A name is not a secret - it is
 * what the module's documentation told the operator to call it - and an edit
 * form that can say "api_key: saved" without opening anything is the only way
 * to show what is there while showing none of it.
 *
 * A blank value on an edit means "keep it". The form cannot show what is
 * stored, so treating an empty box as a blanking would wipe the API key of
 * every registrar anybody ever renamed - Server::modify()'s rule, for the same
 * reason.
 *
 * ---------------------------------------------------------------------------
 * EVERY REFUSAL IS NAMED BEFORE THE DATABASE CAN REFUSE IT
 * ---------------------------------------------------------------------------
 * `name` and `module_name` are both UNIQUE. A duplicate that reaches the
 * database is refused there too, as a driver exception an operator cannot act
 * on. The name check is case-insensitive here because MySQL's collation would
 * refuse `NAMESILO` beside `Namesilo` while PostgreSQL would store both.
 *
 * "No module" is stored as the empty string, and the index counts it as a
 * value - so the table holds ONE registrar that is run by hand. That is a limit
 * of the schema, stated in the refusal rather than surfaced as a duplicate key.
 *
 * The table is small by nature, one row per registrar account, so the
 * uniqueness checks read it whole rather than asking the database to compare
 * case-insensitively, which would need raw SQL.
 */
class Registrar extends Action
{
    use RegistersDomains;

    /** @var string The Module Directory a Registrar's Module Lives Under */
    public const TYPE = 'registrars';

    /** @var string[] Columns a Form May Write */
    public const FIELDS = ['name', 'module_name', 'api_url', 'is_default', 'is_active'];

    /**
     * @var string[] Names a Credential May Not Take
     *
     * `api_url` has its own column and is handed to the module under that key.
     * A credential of the same name would shadow it or be shadowed by it, and
     * either way the operator would be looking at one value while the module
     * used another. `mode` is the Live/Test switch, since Phase 40, for the
     * same reason.
     */
    public const RESERVED = ['api_url', 'mode'];

    /** @var string What a Credential Name May Look Like */
    public const CREDENTIAL_NAME = '/^[A-Za-z0-9_.\-]{1,60}$/';

    /** @var array<string,array>|null Registrar Modules On Disk, Read Once */
    private ?array $modules = null;

    public function model(): Model
    {
        return new DomainRegistrarModel();
    }

    protected function searchable(): array
    {
        return ['name', 'module_name'];
    }

    protected function createdColumn(): ?string
    {
        return 'dr_created_at';
    }

    protected function updatedColumn(): ?string
    {
        return 'dr_updated_at';
    }

    ####################################################################################
    /*==================================== READS =====================================*/
    ####################################################################################

    /**
     * Every Registrar, By Name
     * @return array
     */
    public function listing(): array
    {
        return $this->model()->order('name', self::ASC)->get();
    }

    /**
     * Registrar Names, Keyed By Id
     *
     * The one place a registrar is NAMED for a form. The TLD form and the domain
     * form each had their own copy before Phase 35 - the domain one a bare Model
     * guessing at a `registrar_name` column that has never existed.
     * @return array<int,string>
     */
    public function choices(): array
    {
        $choices = [];

        foreach ($this->listing() as $row) {
            $choices[(int) $row['dr_id']] = (string) $row['name'];
        }

        return $choices;
    }

    /**
     * The Registrar a New TLD Starts On
     *
     * `is_default` has been in the schema since Phase 0 with nothing reading
     * it. This is its reader, so the switch on the registrar form does
     * something - Phase 27.4 refused to ship promo codes' `is_recurring` as a
     * tick that did nothing, and the same rule holds here.
     * @return ?int Null when none is marked default
     */
    public function defaultId(): ?int
    {
        $row = $this->first(['is_default' => 'yes']);

        return $row === null ? null : (int) $row['dr_id'];
    }

    /**
     * Which Credentials a Registrar Has, By Name Only
     * @param array $registrar Registrar Row
     * @return string[]
     */
    public function credentialNames(array $registrar): array
    {
        return array_map('strval', array_keys($this->sealedOf($registrar)));
    }

    /**
     * A Registrar's Credentials, Opened
     *
     * The only way out of the column, and a method rather than something a
     * listing returns - a screen that never asks cannot print one.
     *
     * A value that will not open is left out rather than thrown over. The usual
     * cause is the application key having been rotated, and a module that then
     * reports a missing key is one somebody can fix by typing it again; a
     * registrars screen that fatals is not.
     * @param array $registrar Registrar Row
     * @return array<string,string>
     */
    public function credentials(array $registrar): array
    {
        $open = [];

        foreach ($this->sealedOf($registrar) as $name => $value) {
            $plain = $this->open($value);

            if ($plain !== null) {
                $open[$name] = $plain;
            }
        }

        return $open;
    }

    /**
     * What The Registrar's Module Is Constructed With
     *
     * Its credentials, opened, under the names the operator gave them - or,
     * for a module that DECLARES its fields (Phase 40), exactly those, opened.
     * Then `api_url` from its own column and `mode` from `test_mode`, both set
     * LAST so nothing can shadow them. The form already refuses a credential of
     * either name; a row written some other way must not be able to hand the
     * module a different URL, or a different mode, from the one on the screen.
     * @param array $registrar Registrar Row
     * @param ?string $class The Driver Class Being Built, When Known
     * @return array<string,string>
     */
    public function settingsFor(array $registrar, ?string $class = null): array
    {
        $fields = $class === null ? [] : ModuleSettings::fields($class);

        $settings = $fields === []
            ? $this->credentials($registrar)
            : ModuleSettings::open($fields, $this->sealedOf($registrar));

        $settings['api_url'] = (string) ($registrar['api_url'] ?? '');
        $settings['mode'] = $this->modeOf($registrar);

        return $settings;
    }

    /**
     * Live Or Test - Phase 40
     * @param array $registrar Registrar Row
     * @return string
     */
    public function modeOf(array $registrar): string
    {
        return ($registrar['test_mode'] ?? 'no') === 'yes' ? Api::TEST : Api::LIVE;
    }

    /**
     * What The Form May Show Of a Module That Declares Its Fields - Phase 40
     *
     * `declared` false means the module declares nothing, or is not loaded,
     * and the form offers the Phase 35 name/value pairs instead.
     * @param array $registrar Registrar Row
     * @return array{fields: array, mode: string, declared: bool}
     */
    public function formFor(array $registrar): array
    {
        $fields = $this->declaredFields((string) ($registrar['module_name'] ?? ''));

        return [
            'fields'   =>  ModuleSettings::forForm($fields, $this->sealedOf($registrar)),
            'mode'     =>  $this->modeOf($registrar),
            'declared' =>  $fields !== [],
        ];
    }

    /**
     * Try a Registrar's Saved Settings - Phase 40
     *
     * Asked of its MODULE, whatever the registrar's own active switch says: an
     * operator tests before switching one on. What cannot be tried says why,
     * in state()'s terms.
     * @param array $registrar Registrar Row
     * @return array{success: bool, message: string}
     */
    public function testConnection(array $registrar): array
    {
        $driver = $this->registrarDriver(array_merge($registrar, ['is_active' => 'yes']));

        if ($driver === null) {
            return ['success' => false, 'message' => match ($this->state($registrar)) {
                'manual'  =>  'This registrar has no module, so there is nothing to connect to.',
                'off'     =>  'Its module is switched off. Switch it on under Modules first.',
                'missing' =>  'The module it names is not installed.',
                default   =>  'Its module is on, but the driver could not be built.',
            }];
        }

        return ModuleSettings::test($driver);
    }

    /**
     * Registrar Modules On Disk, Keyed By Directory
     *
     * Disk rather than what is loaded: a module switched off is still one the
     * operator can choose, and the screen says it is off.
     * @return array<string,array{name:string,enabled:bool}>
     */
    public function modules(): array
    {
        if ($this->modules !== null) {
            return $this->modules;
        }

        $modules = [];

        foreach ((new Module())->ofType(self::TYPE) as $module) {
            $directory = (string) ($module['directory'] ?? '');

            if ($directory === '') {
                continue;
            }

            $modules[$directory] = [
                'name'    =>  (string) ($module['name'] ?? $directory),
                'enabled' =>  !empty($module['enabled']),
            ];
        }

        return $this->modules = $modules;
    }

    /**
     * The On-Disk Spelling Of a Module, If It Is Installed
     *
     * Case-insensitive, because the loader is: `RegistersDomains` lowercases
     * both sides of the uid comparison, so `probe` in a row and `Probe` on disk
     * are the same module to it and must be the same module here.
     * @param string $module Module Directory, In Any Case
     * @return ?string
     */
    public function installedModule(string $module): ?string
    {
        foreach (array_keys($this->modules()) as $directory) {
            if (strcasecmp((string) $directory, $module) === 0) {
                return (string) $directory;
            }
        }

        return null;
    }

    /**
     * What a Registrar Will Actually Do With an Order
     *
     *   manual   no module - every domain is left for somebody to register
     *   ready    its module is on and the driver builds
     *   off      its module is installed and switched off
     *   missing  the module it names is not on disk
     *   broken   its module is switched on and the driver will not build
     *
     * All four of the last look identical in the database, and all mean the
     * same thing to an order: nothing is sent to the registrar. The screen has
     * to say which, because each needs a different fix.
     *
     * Asked of the MODULE, so a registrar switched off by its own flag is still
     * reported on what its module would do. The active switch is shown beside
     * this, as its own thing.
     * @param array $registrar Registrar Row
     * @return string
     */
    public function state(array $registrar): string
    {
        $module = trim((string) ($registrar['module_name'] ?? ''));

        if ($module === '') {
            return 'manual';
        }

        $directory = $this->installedModule($module);

        if ($directory === null) {
            return 'missing';
        }

        if (!$this->modules()[$directory]['enabled']) {
            return 'off';
        }

        return $this->registrarDriver(array_merge($registrar, ['is_active' => 'yes'])) !== null
            ? 'ready'
            : 'broken';
    }

    /**
     * What Points At a Registrar
     * @param array $registrar Registrar Row
     * @return array{tlds:int,domains:int}
     */
    public function usage(array $registrar): array
    {
        $id = (int) ($registrar['dr_id'] ?? 0);

        if ($id <= 0) {
            return ['tlds' => 0, 'domains' => 0];
        }

        return [
            'tlds'    =>  (int) (new TldModel())->where(['registrar_relid' => $id])->count(),
            'domains' =>  (int) (new DomainModel())->where(['registrar_relid' => $id])->count(),
        ];
    }

    /**
     * The Columns a Form May Write
     *
     * A method rather than the constant, because a relay facade forwards method
     * calls and not constants.
     * @return string[]
     */
    public function fields(): array
    {
        return self::FIELDS;
    }

    ####################################################################################
    /*=================================== WRITING ====================================*/
    ####################################################################################

    /**
     * Add a Registrar
     * @param array $input Submitted Data
     * @return int New Registrar ID
     * @throws RuntimeException
     */
    public function store(array $input): int
    {
        $data = $this->prepare($input, null);

        if ($data['is_default'] === 'yes') {
            $this->clearDefault();
        }

        return $this->create($data);
    }

    /**
     * Change a Registrar
     * @param int|string $key Registrar ID Or Uid
     * @param array $input Submitted Data
     * @return int Affected rows
     * @throws RuntimeException
     */
    public function modify(int|string $key, array $input): int
    {
        $current = $this->find($key);

        if ($current === null) {
            throw new RuntimeException('That registrar no longer exists.');
        }

        $data = $this->prepare($input, $current);

        if ($data['is_default'] === 'yes') {
            $this->clearDefault();
        }

        return $this->update((int) $current['dr_id'], $data);
    }

    /**
     * Delete a Registrar
     *
     * Refused while anything points at it. A TLD or a domain whose registrar has
     * gone finds no row, therefore no driver, and is quietly left for staff for
     * ever - and a domain in that state is one nobody renews. Domains are
     * checked first because they are the worse of the two: a TLD can be
     * repointed in a minute, a lapsed domain may not come back.
     * @param int|string $key Registrar ID Or Uid
     * @return int Affected rows
     * @throws RuntimeException
     */
    public function remove(int|string $key): int
    {
        $row = $this->find($key);

        if ($row === null) {
            return 0;
        }

        $usage = $this->usage($row);

        if ($usage['domains'] > 0) {
            throw new RuntimeException(
                "{$usage['domains']} domain(s) are registered through this registrar, and deleting it would leave nobody to renew them. Move them to another registrar first."
            );
        }

        if ($usage['tlds'] > 0) {
            throw new RuntimeException(
                "{$usage['tlds']} TLD(s) are priced through this registrar. Point them at another registrar first."
            );
        }

        return $this->delete((int) $row['dr_id']);
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * Check a Submission And Turn It Into Columns
     * @param array $input Submitted Data
     * @param ?array $current The Row Being Edited, Or Null When Adding
     * @return array
     * @throws RuntimeException
     */
    private function prepare(array $input, ?array $current): array
    {
        $data = $this->only($input, self::FIELDS);
        $id = $current === null ? 0 : (int) $current['dr_id'];

        $name = (string) ($data['name'] ?? '');

        if ($name === '') {
            throw new RuntimeException('A registrar needs a name.');
        }

        if (mb_strlen($name) > 100) {
            throw new RuntimeException('A registrar name can be 100 characters at most.');
        }

        $others = array_filter(
            $this->listing(),
            static fn (array $row): bool => (int) $row['dr_id'] !== $id
        );

        foreach ($others as $row) {
            if (mb_strtolower((string) $row['name']) === mb_strtolower($name)) {
                throw new RuntimeException("There is already a registrar called {$row['name']}.");
            }
        }

        $module = $this->moduleFor((string) ($data['module_name'] ?? ''), $current);

        foreach ($others as $row) {
            if (strcasecmp((string) $row['module_name'], $module) !== 0) {
                continue;
            }

            throw new RuntimeException($module === ''
                ? "{$row['name']} is already the registrar with no module. A registrar is stored against its module and \"no module\" counts as one, so only one can be run by hand - give this one its module, or use {$row['name']}."
                : "{$row['name']} already uses the {$module} module. A module serves one registrar.");
        }

        $url = (string) ($data['api_url'] ?? '');

        if ($url !== '' && (
            !preg_match('#^https?://#i', $url)
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || strlen($url) > 255
        )) {
            throw new RuntimeException('The API URL must be a whole address starting with http:// or https://.');
        }

        return [
            'name'        =>  $name,
            'module_name' =>  $module,

            // NOT NULL with no default. MySQL's non-strict mode puts '' in for a
            // missing value and PostgreSQL refuses the insert, so it is always
            // written, blank or not.
            'api_url'     =>  $url,

            'is_default'  =>  array_key_exists('is_default', $data)
                ? $this->flag($data['is_default'])
                : (string) ($current['is_default'] ?? 'no'),
            'is_active'   =>  array_key_exists('is_active', $data)
                ? $this->flag($data['is_active'])
                : (string) ($current['is_active'] ?? 'yes'),

            // ALWAYS serialized, an empty set included. The column is
            // `serialize` and casts run on READ only, so a row written without
            // a value makes the next read of the whole table throw - which took
            // /domains down for every visitor in Phase 27.3.
            'credentials' =>  serialize($this->credentialsFrom($input, $module, $current)),

            // Phase 40. Kept as it is unless the form carried the switch.
            'test_mode'   =>  array_key_exists('mode', $input)
                ? (ModuleSettings::mode($input['mode']) === Api::TEST ? 'yes' : 'no')
                : (string) ($current['test_mode'] ?? 'no'),
        ];
    }

    /**
     * What The Credentials Column Becomes
     *
     * A module that declares its fields (Phase 40) is checked against them by
     * ModuleSettings; one that does not keeps the Phase 35 name/value pairs.
     * The declared fields are applied only when the form CARRIED them: the add
     * form cannot, because the module is chosen on it, so a new registrar is
     * saved first and configured on its edit form.
     * @param array $input Submitted Data
     * @param string $module The Module Directory Being Saved
     * @param ?array $current The Row Being Edited, Or Null
     * @return array<string,string>
     * @throws RuntimeException
     */
    private function credentialsFrom(array $input, string $module, ?array $current): array
    {
        $stored = $current === null ? [] : $this->sealedOf($current);
        $fields = $this->declaredFields($module);

        if ($fields === []) {
            return $this->sealed($input, $stored);
        }

        if (!is_array($input['settings'] ?? null)) {
            return $stored;
        }

        return ModuleSettings::merge(
            $fields,
            $input['settings'],
            $stored,
            is_array($input['settings_clear'] ?? null) ? $input['settings_clear'] : []
        );
    }

    /**
     * The Fields a Registrar Module Declares, When It Is Loaded
     * @param string $module Module Directory
     * @return array
     */
    private function declaredFields(string $module): array
    {
        $class = ModuleSettings::loadedClass(self::TYPE, $module);

        try {
            if ($class === null || !class_exists($class) || !is_subclass_of($class, RegistrarInterface::class)) {
                return [];
            }
        } catch (Throwable) {
            return [];
        }

        return ModuleSettings::fields($class);
    }

    /**
     * Resolve The Module a Submission Names
     * @param string $posted What The Form Sent
     * @param ?array $current The Row Being Edited, Or Null
     * @return string The directory, in its on-disk spelling, or '' for none
     * @throws RuntimeException
     */
    private function moduleFor(string $posted, ?array $current): string
    {
        $posted = trim($posted);

        if ($posted === '') {
            return '';
        }

        $installed = $this->installedModule($posted);

        if ($installed !== null) {
            if (strlen($installed) > 60) {
                throw new RuntimeException("The module directory [{$installed}] is longer than a registrar can record.");
            }

            return $installed;
        }

        // The module this registrar ALREADY has, even though it is no longer on
        // disk. Refusing it would make the form unsaveable for a registrar whose
        // module was removed, and the only way out would be to pick another
        // module or none - exactly the silent change the dropdown keeps the
        // stored value to prevent.
        $stored = (string) ($current['module_name'] ?? '');

        if ($stored !== '' && strcasecmp($stored, $posted) === 0) {
            return $stored;
        }

        throw new RuntimeException("No registrar module called [{$posted}] is installed. Put it in modules/registrars/ first.");
    }

    /**
     * Apply a Submission's Credentials To What Is Stored
     *
     * The form posts `credential_name[]` and `credential_value[]` in pairs, and
     * `credential_forget[]` naming saved ones to remove.
     * @param array $input Submitted Data
     * @param array<string,string> $stored Sealed Values Already Stored
     * @return array<string,string> Sealed Values To Store
     * @throws RuntimeException
     */
    private function sealed(array $input, array $stored): array
    {
        $names = array_values((array) ($input['credential_name'] ?? []));
        $values = array_values((array) ($input['credential_value'] ?? []));
        $rows = max(count($names), count($values));

        for ($i = 0; $i < $rows; $i++) {
            $name = trim((string) ($names[$i] ?? ''));
            $value = trim((string) ($values[$i] ?? ''));

            if ($name === '') {
                if ($value !== '') {
                    throw new RuntimeException('A credential was given a value but no name, so nothing could ever look it up.');
                }

                continue;
            }

            if (!preg_match(self::CREDENTIAL_NAME, $name)) {
                throw new RuntimeException("Credential names are letters, digits, dots, dashes and underscores - [{$name}] is not one.");
            }

            if (in_array(strtolower($name), self::RESERVED, true)) {
                throw new RuntimeException("{$name} is not a credential - it has its own field on this form, and is handed to the module under that name.");
            }

            if ($value === '') {
                // Beside a SAVED name a blank box means keep it - the form
                // cannot show what is stored. Beside a new name it is a
                // mistake, and storing the name alone would tell the module
                // there is a key when there is not.
                if (!array_key_exists($name, $stored)) {
                    throw new RuntimeException("The credential {$name} was given no value.");
                }

                continue;
            }

            $stored[$name] = $this->seal($value);
        }

        // Last, so a saved name posted back with a blank value AND ticked for
        // removal - which is exactly what the edit form sends - is removed
        // rather than kept by the loop above.
        foreach ((array) ($input['credential_forget'] ?? []) as $forget) {
            unset($stored[trim((string) $forget)]);
        }

        return $stored;
    }

    /**
     * The Sealed Values On a Row
     *
     * Normally already an array, because the model casts the column on read.
     * The string branch is for a row read through a bare Model, and it
     * unserialize()s inside try/catch rather than behind `@`: once
     * lf-boot/app.php is loaded the handler promotes the warning to an
     * exception, so `@unserialize()` on a bad value is a fatal, not a false.
     * @param array $registrar Registrar Row
     * @return array<string,string>
     */
    private function sealedOf(array $registrar): array
    {
        $raw = $registrar['credentials'] ?? null;

        if (is_string($raw)) {
            if (trim($raw) === '') {
                return [];
            }

            try {
                $raw = unserialize($raw, ['allowed_classes' => false]);
            } catch (Throwable) {
                return [];
            }
        }

        if (!is_array($raw)) {
            return [];
        }

        $sealed = [];

        foreach ($raw as $name => $value) {
            if (is_string($value) && $value !== '') {
                $sealed[(string) $name] = $value;
            }
        }

        return $sealed;
    }

    /**
     * Only One Registrar Is The Default
     * @return void
     */
    private function clearDefault(): void
    {
        $this->updateWhere(['is_default' => 'yes'], ['is_default' => 'no']);
    }

    /**
     * Encrypt a Credential
     * @param string $value Plain Value
     * @return string
     */
    private function seal(string $value): string
    {
        return Vault::encrypt($value);
    }

    /**
     * Decrypt a Credential
     * @param string $value Stored Value
     * @return ?string Null when it will not open
     */
    private function open(string $value): ?string
    {
        try {
            $plain = Vault::decrypt($value);
        } catch (Throwable) {
            return null;
        }

        return is_string($plain) && $plain !== '' ? $plain : null;
    }
}
