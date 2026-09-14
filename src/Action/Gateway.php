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
use Laika\Service\Uid;
use LBM\Model\PaymentGatewayModel;
use LBM\Module\Api;
use LBM\Module\Contracts\GatewayInterface;
use LBM\Module\Contracts\RegistersWebhook;
use LBM\Module\Contracts\TokenizerInterface;
use LBM\Module\ModuleManager;
use LBM\Support\ModuleSettings;
use RuntimeException;

/**
 * The payment gateways this installation can actually take money through.
 *
 * ------------------------------------------------------------------------
 * Two layers, and they answer different questions
 * ------------------------------------------------------------------------
 * A gateway is a **module** on disk under `modules/gateways` - that decides
 * whether its class is autoloadable at all.
 *
 * The `payment_gateways` row is its **configuration**: the API keys, test mode,
 * the name customers see, and whether it is offered. Since Phase 46 the two
 * move together: Enable on the gateways screen switches the module on and
 * creates the row the first time (attach()), and Disable switches both off.
 * The row is KEPT - transactions point at it. What stays apart is "offered" and
 * "can take money": a module switched on is not loadable until the next
 * request, and one that will not build is never offered at checkout, because
 * payable() builds the driver before listing it.
 *
 * ------------------------------------------------------------------------
 * resolve() is the only place a driver is instantiated
 * ------------------------------------------------------------------------
 * `module_class` is a class name read out of the database, so it is treated as
 * untrusted input every single time: it must exist, it must implement
 * GatewayInterface, and it must belong to a module that is currently loaded.
 * Skipping any of those turns a settings row into arbitrary class instantiation.
 *
 * Nothing here talks to a gateway's API. `charge()`, `refund()` and `webhook()`
 * are called by the payment flow, and what they report is recorded by
 * LBM\Action\Transaction, which is the only writer of a `transactions` row.
 * A gateway that wrote its own ledger row would be a second, divergent source
 * of truth about what a client has paid.
 */
class Gateway extends Action
{
    /** @var string[] Columns a Form May Write */
    public const FIELDS = [
        'gateway_name',
        'gateway_slug',
        'display_name',
        'module_class',
        'logo_url',
        'test_mode',
        'is_active',
    ];

    /** @var string The Module Type Gateways Live Under */
    public const TYPE = 'gateways';

    public function model(): Model
    {
        return new PaymentGatewayModel();
    }

    protected function searchable(): array
    {
        return ['gateway_name', 'display_name', 'gateway_slug'];
    }

    protected function createdColumn(): ?string
    {
        return 'gateway_created_at';
    }

    protected function updatedColumn(): ?string
    {
        return 'gateway_updated_at';
    }

    ####################################################################################
    /*================================= EXTERNAL API =================================*/
    ####################################################################################

    /**
     * Gateways a Customer May Actually Pay Through
     *
     * Active AND resolvable. A row whose module has been disabled or deleted is
     * silently absent rather than an error: the operator sees the problem on the
     * gateways screen, and a customer trying to pay an invoice should not be
     * shown a broken button or a stack trace.
     * @return array<int,array> Gateway rows, ordered by display name
     */
    public function payable(): array
    {
        $out = [];

        foreach ($this->all(['is_active' => 'yes'], self::ASC, 'display_name') as $row) {
            if ($this->driverFor($row) !== null) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * One Payable Gateway By Slug
     *
     * Used by the pay flow, which is handed a slug from a form. Returns null
     * rather than throwing for an unknown or inactive slug - a customer posting
     * a slug that is not on offer is refused the same way as one posting
     * nothing.
     * @param string $slug Gateway Slug
     * @return ?array
     */
    public function payableBySlug(string $slug): ?array
    {
        $slug = trim($slug);

        if ($slug === '') {
            return null;
        }

        $row = $this->first(['gateway_slug' => $slug, 'is_active' => 'yes']);

        return $row !== null && $this->driverFor($row) !== null ? $row : null;
    }

    /**
     * Build The Driver For a Gateway Row
     *
     * @param array $row Gateway Row
     * @return ?GatewayInterface Null when the module is gone, disabled or not a gateway
     */
    public function driverFor(array $row): ?GatewayInterface
    {
        $class = trim((string) ($row['module_class'] ?? ''));

        if ($class === '' || !class_exists($class)) {
            return null;
        }

        // A class name out of the database is untrusted input. Without this an
        // operator - or anything that can write one settings row - chooses which
        // class the application instantiates.
        if (!is_subclass_of($class, GatewayInterface::class)) {
            return null;
        }

        try {
            $driver = new $class($this->driverSettings($row, $class));
        } catch (\Throwable) {
            // A driver that will not construct is a broken module, not a fatal
            // for whoever happened to be paying an invoice.
            return null;
        }

        return $driver instanceof GatewayInterface ? $driver : null;
    }

    /**
     * The Same, But Say Why It Failed
     *
     * The admin screen needs the reason; the payment flow only needs to know it
     * cannot be used. Kept apart so a customer-facing path can never leak a
     * class name.
     * @param array $row Gateway Row
     * @return ?string Null when the driver is fine
     */
    public function problemWith(array $row): ?string
    {
        $class = trim((string) ($row['module_class'] ?? ''));

        if ($class === '') {
            return 'No module class is recorded for this gateway.';
        }

        if (!class_exists($class)) {
            return "The class [{$class}] could not be loaded. Its module may be disabled or removed.";
        }

        if (!is_subclass_of($class, GatewayInterface::class)) {
            return "The class [{$class}] does not implement GatewayInterface.";
        }

        // Built here rather than through driverFor(), which swallows the reason:
        // a module missing its Composer package says so in its constructor, and
        // "could not be constructed" alone sends the operator looking elsewhere.
        try {
            new $class($this->driverSettings($row, $class));
        } catch (\Throwable $e) {
            return "The class [{$class}] could not be constructed: " . $e->getMessage();
        }

        return null;
    }

    /**
     * Every Gateway Driver An Enabled Module Declares
     *
     * From the manifest's `class` key, which modules/README.md has specified
     * since Phase 9. Only loaded modules are asked, so a disabled gateway offers
     * nothing - its classes are not autoloadable anyway.
     *
     * One driver per module, deliberately: the directory a module sits in
     * decides its kind, and a module claiming to be two gateways at once has no
     * meaningful answer to "which settings row is yours".
     * @return array<string,string> Module Uid => Class Name
     */
    public function drivers(): array
    {
        $out = [];

        foreach (ModuleManager::loaded() as $uid => $module) {
            if (($module['type'] ?? '') !== self::TYPE) {
                continue;
            }

            $class = trim((string) ($module['class'] ?? ''));

            if ($class !== '') {
                $out[$uid] = $class;
            }
        }

        return $out;
    }

    /**
     * Driver Classes With No Configuration Row Yet
     *
     * What the admin gateways screen offers to set up.
     * @return array<string,string> Module Uid => Class Name
     */
    public function unconfigured(): array
    {
        $known = [];

        foreach ($this->all() as $row) {
            $class = trim((string) ($row['module_class'] ?? ''));

            if ($class !== '') {
                $known[$class] = true;
            }
        }

        return array_filter(
            $this->drivers(),
            static fn(string $class): bool => !isset($known[$class])
        );
    }

    /**
     * A Gateway's Stored Settings
     *
     * Normally already an array: `settings` is declared `serialize` in
     * PaymentGatewayModel::$casts, and casts run on read. The string branch is
     * for a row read some other way, and it unserialize()s rather than
     * json_decode()s - getting that backwards stores JSON the model then tries
     * to unserialize, which throws on the next read of the whole table and takes
     * the gateways screen down with it.
     * @param array $row Gateway Row
     * @return array
     */
    public function settings(array $row): array
    {
        $raw = $row['settings'] ?? null;

        if (is_array($raw)) {
            return $raw;
        }

        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        // try/catch, not @. Once lf-boot/app.php is loaded the framework's
        // handler promotes warnings to ErrorException, so @unserialize() on a
        // malformed value is a fatal with no output rather than a false.
        try {
            $decoded = unserialize($raw, ['allowed_classes' => false]);
        } catch (\Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    ####################################################################################
    /*=========================== SETTINGS - PHASE 40 ================================*/
    ####################################################################################

    /**
     * What a Gateway Driver Is Constructed With
     *
     * A module that DECLARES its fields gets exactly those, opened. One that
     * does not gets the stored array as it always has. Either way `mode` is set
     * last, from `test_mode`, so nothing stored can shadow the switch.
     * @param array $row Gateway Row
     * @param string $class The Driver Class
     * @return array
     */
    public function driverSettings(array $row, string $class): array
    {
        $stored = $this->settings($row);
        $fields = ModuleSettings::fields($class);

        $settings = $fields === [] ? $stored : ModuleSettings::open($fields, $stored);
        $settings['mode'] = $this->modeOf($row);

        return $settings;
    }

    /**
     * Live Or Test
     * @param array $row Gateway Row
     * @return string
     */
    public function modeOf(array $row): string
    {
        return ($row['test_mode'] ?? 'no') === 'yes' ? Api::TEST : Api::LIVE;
    }

    /**
     * The Fields a Gateway's Module Declares
     *
     * Only for a class that is loadable AND a gateway: the name is read out of
     * the database, and nothing else's static settings() is asked.
     * @param array $row Gateway Row
     * @return array
     */
    public function fieldsFor(array $row): array
    {
        $class = trim((string) ($row['module_class'] ?? ''));

        try {
            if ($class === '' || !class_exists($class) || !is_subclass_of($class, GatewayInterface::class)) {
                return [];
            }
        } catch (\Throwable) {
            return [];
        }

        return ModuleSettings::fields($class);
    }

    /**
     * What The Gateways Screen May Show
     *
     * ModuleSettings::forForm() and nothing else - a secret arrives as a
     * `saved` flag. Until Phase 40 the screen was handed the stored array
     * whole, API keys included.
     * @param array $row Gateway Row
     * @return array{fields: array, mode: string}
     */
    public function formFor(array $row): array
    {
        return [
            'fields' =>  ModuleSettings::forForm($this->fieldsFor($row), $this->settings($row)),
            'mode'   =>  $this->modeOf($row),
        ];
    }

    /**
     * Save What The Gateways Screen Posted
     *
     * Only what the module declared is stored - the form used to keep whatever
     * it carried. The mode goes to `test_mode`.
     * @param int|string $key Gateway ID Or Uid
     * @param array $input The Form - `settings[...]`, `settings_clear[]`, `mode`
     * @return int Rows Updated
     * @throws RuntimeException Naming a refused field by its label
     */
    public function saveSettings(int|string $key, array $input): int
    {
        $row = $this->find($key);

        if ($row === null) {
            throw new RuntimeException('That gateway is not configured.');
        }

        $data = [];
        $fields = $this->fieldsFor($row);

        // Phase 46: the name customers see is set here, on the gateway's
        // Configure page - there is no Set up form to type it into any more.
        if (array_key_exists('display_name', $input)) {
            $name = trim((string) $input['display_name']);

            if ($name === '') {
                throw new RuntimeException('A gateway needs a name customers will see.');
            }

            if (mb_strlen($name) > 100) {
                throw new RuntimeException('A gateway name can be 100 characters at most.');
            }

            $data['display_name'] = $name;
        }

        if ($fields !== []) {
            // serialize()d by hand, putSettings()'s reason: casts run on READ only.
            $data['settings'] = serialize(ModuleSettings::merge(
                $fields,
                is_array($input['settings'] ?? null) ? $input['settings'] : [],
                $this->settings($row),
                is_array($input['settings_clear'] ?? null) ? $input['settings_clear'] : []
            ));
        }

        if (array_key_exists('mode', $input)) {
            $data['test_mode'] = ModuleSettings::mode($input['mode']) === Api::TEST ? 'yes' : 'no';
        }

        return $data === [] ? 0 : $this->update((int) $row['gateway_id'], $data);
    }

    /**
     * Try a Gateway's Saved Settings
     *
     * Works whether or not the gateway is switched on for customers - an
     * operator tests BEFORE offering it, which is the point.
     * @param int|string $key Gateway ID Or Uid
     * @return array{success: bool, message: string}
     * @throws RuntimeException
     */
    public function testConnection(int|string $key): array
    {
        $row = $this->find($key);

        if ($row === null) {
            throw new RuntimeException('That gateway is not configured.');
        }

        $driver = $this->driverFor($row);

        if ($driver === null) {
            return ['success' => false, 'message' => (string) ($this->problemWith($row) ?? 'The driver could not be built.')];
        }

        return ModuleSettings::test($driver);
    }

    ####################################################################################
    /*=========================== WEBHOOKS - PHASE 48 ================================*/
    ####################################################################################

    /**
     * Whether a Gateway's Driver Registers Its Own Webhook
     * @param array $row Gateway Row
     * @return bool
     */
    public function registersWebhook(array $row): bool
    {
        $class = trim((string) ($row['module_class'] ?? ''));

        try {
            return $class !== '' && class_exists($class) && is_subclass_of($class, RegistersWebhook::class);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Whether The Gateway's Current Mode Has Its Webhook
     * @param array $row Gateway Row
     * @return ?bool Null when the driver cannot say - it will not build, or it
     *               does not register its own
     */
    public function webhookReady(array $row): ?bool
    {
        $driver = $this->driverFor($row);

        if (!$driver instanceof RegistersWebhook) {
            return null;
        }

        try {
            return !$driver->needsWebhook();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Register a Gateway's Webhook With Its Provider
     *
     * After a save, only when the driver says its mode still needs one - so a
     * page that already works never touches the provider. From the button,
     * always.
     *
     * What the module returns is stored through ModuleSettings, and only for
     * the fields it DECLARES: a module cannot write a key it never declared, and
     * a secret is sealed the way one typed into the form would be. Nothing else
     * on the row is touched.
     * @param int|string $key Gateway ID Or Uid
     * @param bool $always The operator pressed Register webhook
     * @return ?array{success: bool, message: string} Null when there was nothing to do
     * @throws RuntimeException
     */
    public function registerWebhook(int|string $key, bool $always = false): ?array
    {
        $row = $this->find($key);

        if ($row === null) {
            throw new RuntimeException('That gateway is not configured.');
        }

        $driver = $this->driverFor($row);

        if (!$driver instanceof RegistersWebhook) {
            return $always
                ? ['success' => false, 'message' => (string) ($this->problemWith($row) ?? 'This gateway cannot register its own webhook.')]
                : null;
        }

        try {
            if (!$always && !$driver->needsWebhook()) {
                return null;
            }

            $answer = $driver->registerWebhook(named('webhook.gateway', ['gateway' => (string) $row['gateway_slug']]));
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'The module could not register the webhook: ' . $e->getMessage()];
        }

        $message = trim((string) ($answer['message'] ?? ''));

        if (($answer['success'] ?? false) !== true) {
            return ['success' => false, 'message' => $message !== '' ? $message : 'The webhook was not registered.'];
        }

        $fields = $this->fieldsFor($row);
        $keep = array_intersect_key(is_array($answer['settings'] ?? null) ? $answer['settings'] : [], $fields);

        if ($keep !== []) {
            // Merged over what is stored, field by field: the other fields are
            // not in the answer, and a merge of the whole set would read their
            // absence as a blank box.
            $stored = $this->settings($row);

            $merged = ModuleSettings::merge(
                array_intersect_key($fields, $keep),
                array_map(static fn (mixed $value): string => (string) $value, $keep),
                $stored
            );

            // serialize()d by hand, putSettings()'s reason: casts run on READ only.
            $this->update((int) $row['gateway_id'], ['settings' => serialize(array_merge($stored, $merged))]);
        }

        return ['success' => true, 'message' => $message !== '' ? $message : 'The webhook was registered.'];
    }

    ####################################################################################
    /*======================== TWO KINDS OF GATEWAY - PHASE 48 =======================*/
    ####################################################################################

    /** @var string A Gateway That Takes The Card On The Site */
    public const TOKENIZER = 'tokenizer';

    /** @var string A Gateway Paid On The Provider's Page, Or Offline */
    public const THIRD_PARTY = 'third_party';

    /**
     * Which Kind Of Gateway a Row Is - Read From Its Class, Never Stored
     *
     * A setting that said "tokenizer" over a class that is not one would draw a
     * card field nothing can charge.
     * @param array $row Gateway Row
     * @return ?string tokenizer, third_party, or null when the class is not loadable
     */
    public function kindOf(array $row): ?string
    {
        $class = trim((string) ($row['module_class'] ?? ''));

        try {
            if ($class === '' || !class_exists($class) || !is_subclass_of($class, GatewayInterface::class)) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        return is_subclass_of($class, TokenizerInterface::class) ? self::TOKENIZER : self::THIRD_PARTY;
    }

    /**
     * Whether a Gateway Takes The Card On The Site
     *
     * A method, because a relay forwards calls and not constants.
     * @param array $row Gateway Row
     * @return bool
     */
    public function isTokenizer(array $row): bool
    {
        return $this->kindOf($row) === self::TOKENIZER;
    }

    /**
     * A Tokenizer's Driver - Or Null For Any Other Gateway, Or One That Will Not Build
     * @param array $row Gateway Row
     * @return ?TokenizerInterface
     */
    public function tokenizerFor(array $row): ?TokenizerInterface
    {
        $driver = $this->driverFor($row);

        return $driver instanceof TokenizerInterface ? $driver : null;
    }

    /**
     * What a Page Needs To Draw a Tokenizer's Card Field
     *
     * Only what the driver's browser() returns, and of the scripts only https
     * addresses: the provider's own library comes from the provider, and a
     * plain-http script on a payment page is one anybody on the network can
     * rewrite.
     * @param array $row Gateway Row
     * @param array $context See TokenizerInterface::browser()
     * @return ?array{slug: string, name: string, adapter: string, scripts: string[], config: array, error: ?string}
     */
    public function cardField(array $row, array $context): ?array
    {
        $driver = $this->tokenizerFor($row);

        if ($driver === null) {
            return null;
        }

        try {
            $browser = $driver->browser($context);
        } catch (\Throwable $e) {
            $browser = ['error' => $e->getMessage()];
        }

        $scripts = array_values(array_filter(
            is_array($browser['scripts'] ?? null) ? $browser['scripts'] : [],
            static fn (mixed $src): bool => is_string($src) && preg_match('#^https://[^\s"\'<>]+$#i', $src) === 1
        ));

        $error = trim((string) ($browser['error'] ?? ''));

        return [
            'slug'    =>  (string) $row['gateway_slug'],
            'name'    =>  (string) $row['display_name'],
            'adapter' =>  (string) ($browser['adapter'] ?? ''),
            'scripts' =>  $scripts,
            'config'  =>  is_array($browser['config'] ?? null) ? $browser['config'] : [],
            'error'   =>  $error !== '' ? $error : null,
        ];
    }

    /**
     * The File a Tokenizer's Adapter Is Served From - Only From Inside Its Module
     *
     * modules/ is closed to the web, so the product serves the adapter. The path
     * is the module's to name; resolving it through realpath() against the
     * module's OWN directory is what stops a module - or a class name read out
     * of the database - serving lf-config/database.php as JavaScript.
     * @param array $row Gateway Row
     * @return ?string
     */
    public function scriptFile(array $row): ?string
    {
        if (!$this->isTokenizer($row)) {
            return null;
        }

        $class = trim((string) $row['module_class']);
        $home = '';

        foreach (ModuleManager::loaded() as $module) {
            if (($module['type'] ?? '') === self::TYPE && trim((string) ($module['class'] ?? '')) === $class) {
                $home = (string) ($module['path'] ?? '');
                break;
            }
        }

        try {
            $file = (string) $class::script();
        } catch (\Throwable) {
            return null;
        }

        $real = $file === '' ? false : realpath($file);
        $root = $home === '' ? false : realpath($home);

        if ($real === false || $root === false || !is_file($real) || strtolower(pathinfo($real, PATHINFO_EXTENSION)) !== 'js') {
            return null;
        }

        $real = str_replace('\\', '/', $real);
        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';

        if (PHP_OS_FAMILY === 'Windows') {
            return str_starts_with(strtolower($real), strtolower($root)) ? $real : null;
        }

        return str_starts_with($real, $root) ? $real : null;
    }

    /**
     * The Idempotency Key For Charging a Balance To a Card Today
     *
     * The same invoice, balance, card and day give the same key, whoever
     * presses what: two presses, or the scheduled charge and a member of staff
     * on the same morning, are one charge at the provider. A different card, or a
     * balance that has changed, is a different charge.
     * @param int $invoiceId
     * @param string $balance
     * @param string $card The token or saved card
     * @return string
     */
    public function attemptKey(int $invoiceId, string $balance, string $card): string
    {
        return 'lbm-' . substr(hash('sha256', implode('|', [$invoiceId, $balance, $card, date('Y-m-d')])), 0, 40);
    }

    ####################################################################################
    /*=================================== WRITING ====================================*/
    ####################################################################################

    /**
     * The Row a Driver Class Is Configured In - Phase 46
     *
     * A gateway row names its driver by class, and a module names its driver
     * by class in its manifest, so that is the link: the directory may be
     * renamed and the display name is the operator's.
     * @param string $class Driver Class
     * @return ?array
     */
    public function forClass(string $class): ?array
    {
        $class = trim($class);

        return $class === '' ? null : $this->first(['module_class' => $class]);
    }

    /**
     * The Row a Gateway Module Is Configured In - Phase 46
     * @param string $uid Module Uid
     * @return ?array
     */
    public function forModule(string $uid): ?array
    {
        $module = (new Module())->find($uid);

        return $module === null ? null : $this->forClass((string) ($module['class'] ?? ''));
    }

    /**
     * Switch a Gateway Module's Gateway On Or Off - Phase 46
     *
     * Enabling a gateway module IS setting it up: the row is created the first
     * time - named from the manifest, its slug from the directory, offered -
     * and offered again every time after. Disabling stops offering it and keeps
     * the row, because transactions point at it.
     *
     * No driver is built here. The module is being switched on in this very
     * request and is not loadable until the next one; payable() builds it
     * before a customer is shown it, so one that will not build is never on
     * the checkout, and the gateways screen says why.
     * @param string $uid Module Uid
     * @param bool $on
     * @return ?int Gateway ID, Or Null When Switching Off One That Never Had a Row
     * @throws RuntimeException When It Is Not a Gateway Module, Or Names No Driver
     */
    public function attach(string $uid, bool $on): ?int
    {
        $module = (new Module())->find($uid);

        if ($module === null || ($module['type'] ?? '') !== self::TYPE) {
            throw new RuntimeException('That payment gateway module is not installed.');
        }

        $class = trim((string) ($module['class'] ?? ''));
        $row = $this->forClass($class);

        if ($row !== null) {
            $this->update((int) $row['gateway_id'], ['is_active' => $on ? 'yes' : 'no']);

            return (int) $row['gateway_id'];
        }

        if (!$on) {
            return null;
        }

        if ($class === '') {
            throw new RuntimeException(
                'This gateway module names no driver class in its manifest, so it cannot take payments. Its author has to add one.'
            );
        }

        $slug = $this->freeSlug((string) $module['directory']);

        $id = $this->add([
            'gateway_name' =>  $slug,
            'gateway_slug' =>  $slug,
            'display_name' =>  mb_substr(trim((string) $module['name']) ?: (string) $module['directory'], 0, 100),
            'module_class' =>  $class,
            'test_mode'    =>  'no',
            'is_active'    =>  'yes',
        ]);

        // A serialize column, and casts run on READ only - written by hand.
        $this->putSettings($id, []);

        return $id;
    }

    /**
     * Record a Gateway's Configuration
     *
     * @param array $input Submitted Data
     * @return int New Gateway ID
     * @throws RuntimeException
     */
    public function add(array $input): int
    {
        $data = $this->fields($input);

        foreach (['gateway_name', 'gateway_slug', 'display_name', 'module_class'] as $required) {
            if (trim((string) ($data[$required] ?? '')) === '') {
                throw new RuntimeException("A gateway needs a {$required}.");
            }
        }

        if ($this->exists(['gateway_slug' => $data['gateway_slug']])) {
            throw new RuntimeException('A gateway with that slug is already configured.');
        }

        $data['uid'] = Uid::make();

        return $this->create($data);
    }

    /**
     * Switch a Gateway On Or Off For Customers
     *
     * Refuses to switch on a gateway whose driver will not build. Letting that
     * through would put a button on the checkout that cannot take money, and the
     * customer would be the one to find out.
     * @param int|string $key Gateway ID Or Uid
     * @param bool $active
     * @return int Rows Updated
     * @throws RuntimeException
     */
    public function activate(int|string $key, bool $active): int
    {
        $row = $this->find($key);

        if ($row === null) {
            throw new RuntimeException('That gateway is not configured.');
        }

        if ($active) {
            $problem = $this->problemWith($row);

            if ($problem !== null) {
                throw new RuntimeException($problem);
            }
        }

        return $this->update($key, ['is_active' => $active ? 'yes' : 'no']);
    }

    /**
     * Replace a Gateway's Settings
     *
     * serialize()d here rather than left to the model's `serialize` cast, for
     * the reason Transaction::recordGatewayData() already gives: casts run on
     * READ only, so handing update() an array stores the word "Array".
     *
     * Whatever the driver declares is what is kept - LBM does not know what an
     * individual gateway needs.
     * @param int|string $key Gateway ID Or Uid
     * @param array $settings
     * @return int Rows Updated
     */
    public function putSettings(int|string $key, array $settings): int
    {
        return $this->update($key, ['settings' => serialize($settings)]);
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * A Slug Nobody Else Has, From a Module Directory
     *
     * `gateway_slug` is what the webhook URL is built from, so it is plain
     * lower case - and a module whose directory name is already taken gets a
     * number rather than a duplicate-key error on the Enable button.
     * @param string $directory Module Directory
     * @return string
     */
    private function freeSlug(string $directory): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($directory)), '-');
        $base = $base === '' ? 'gateway' : substr($base, 0, 40);

        $slug = $base;

        for ($n = 2; $this->exists(['gateway_slug' => $slug]); $n++) {
            $slug = $base . '-' . $n;
        }

        return $slug;
    }

    /**
     * The Columns a Form May Write, And Nothing Else
     *
     * Each action carries its own, over the shared only() - the base class has
     * no fields() to inherit.
     * @param array $input Submitted Data
     * @return array
     */
    private function fields(array $input): array
    {
        $data = $this->only($input, self::FIELDS);

        foreach (['test_mode', 'is_active'] as $flag) {
            if (isset($data[$flag])) {
                $data[$flag] = $this->flag($data[$flag]);
            }
        }

        return $data;
    }
}
