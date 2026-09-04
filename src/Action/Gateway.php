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
use LBM\Module\Contracts\GatewayInterface;
use LBM\Module\ModuleManager;
use RuntimeException;

/**
 * The payment gateways this installation can actually take money through.
 *
 * ------------------------------------------------------------------------
 * Two layers, and they answer different questions
 * ------------------------------------------------------------------------
 * A gateway is a **module** on disk under `modules/gateways`, enabled or
 * disabled through the modules screen like every other module - that decides
 * whether its class is autoloadable at all.
 *
 * The `payment_gateways` row is its **configuration**: the API keys, test mode,
 * and whether the operator has switched it on for customers. Enabled-but-not-
 * configured is a real and common state, and it must not be offered at
 * checkout, so the two are kept apart rather than collapsed into one flag.
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
            $driver = new $class($this->settings($row));
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

        return $this->driverFor($row) === null
            ? "The class [{$class}] exists but could not be constructed."
            : null;
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
    /*=================================== WRITING ====================================*/
    ####################################################################################

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
