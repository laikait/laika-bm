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
use LBM\Model\ModuleModel;
use LBM\Module\ModuleManager;
use LBM\Module\Contracts\FraudInterface;
use LBM\Support\ErrorLog;
use LBM\Support\ModuleSettings;

/**
 * Screening an order at checkout, and which module does it - Phase 41.
 *
 * ---------------------------------------------------------------------------
 * NOTHING CHANGES UNTIL AN OPERATOR CHOOSES A MODULE
 * ---------------------------------------------------------------------------
 * `fraud_module` holds a module DIRECTORY, or `none`, and missing means none.
 * An install upgraded to this phase screens nothing, exactly as before, until
 * somebody chooses on the Modules screen - holding orders is a decision about
 * the business, and a default that started holding them would be somebody
 * else's decision.
 *
 * ---------------------------------------------------------------------------
 * ONLY A CLEAR VERDICT DOES ANYTHING
 * ---------------------------------------------------------------------------
 * `review` and `fail` hold the order: status `fraud`, no invoice, for staff.
 * Everything else lets it through - `pass`, and every way of not knowing: a
 * module that is chosen but switched off, missing or broken, a failed call, an
 * exception, a verdict nobody recognises. A screening service that is down must
 * not stop every sale, so the ways of not knowing are written to the error log
 * against the module instead: a screen that has quietly stopped working is
 * something an operator needs to be able to see.
 *
 * The choice is kept the way `Action\Lookup` keeps its own - read that class
 * for the reasons behind `choose()` accepting the value already chosen, and
 * behind storing the on-disk spelling.
 *
 * Refusals are English, like every Action's.
 */
class Fraud extends Action
{
    /** @var string The Module Type, Which Is Also Its Directory */
    public const TYPE = 'fraud';

    /** @var string Where The Choice Is Stored */
    public const OPTION = 'fraud_module';

    /** @var string The Stored Value For "No Fraud Check" */
    public const NONE = 'none';

    /** @var string[] The Verdicts That Mean Something */
    public const VERDICTS = ['pass', 'review', 'fail'];

    /** @var string[] The Verdicts That Hold An Order */
    public const HOLD = ['review', 'fail'];

    /** @var string The Order Status a Held Order Is Given - Seeded Since Phase 0 */
    public const HELD_STATUS = 'fraud';

    /** @var float Seconds a Screen May Take. A Customer Is Waiting On It */
    public const TIMEOUT = 8.0;

    /** @var array<string,array{name:string,enabled:bool}>|null Fraud Modules On Disk */
    private ?array $modules = null;

    /**
     * The Options Table - The Only Thing The Choice Writes
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
     * Screen An Order, And Hold It When The Module Says So
     *
     * Called by checkout between writing the order and raising its invoice.
     * Writes `orders.fraud_score` when the module gives one, and the `fraud`
     * status when it holds the order. Never throws.
     * @param int|string $key Order ID Or Uid
     * @return array{held: bool, verdict: ?string, score: ?string, message: string}
     */
    public function screen(int|string $key): array
    {
        $none = ['held' => false, 'verdict' => null, 'score' => null, 'message' => ''];

        try {
            $orders = new Order();
            $order = $orders->find($key);
            $directory = $this->ready();

            if ($order === null || $directory === null) {
                return $none;
            }

            $driver = $this->driver($directory);

            if ($driver === null) {
                return $none;
            }

            $moduleId = $this->moduleId($directory);

            try {
                $result = $driver->check($order, $this->contextFor($order));
            } catch (Throwable $e) {
                ErrorLog::record($e, 'module', $moduleId);

                return $none;
            }

            $result = is_array($result) ? $result : [];
            $verdict = ($result['success'] ?? false) === true && in_array($result['verdict'] ?? null, self::VERDICTS, true)
                ? (string) $result['verdict']
                : null;
            $score = $this->score($result['score'] ?? null);
            $message = trim((string) ($result['message'] ?? ''));
            $number = (string) ($order['order_number'] ?? $order['oid']);

            if ($verdict === null) {
                ErrorLog::note(
                    "The fraud check could not say about order {$number}"
                        . ($message !== '' ? ": {$message}" : '.') . ' It was let through.',
                    'module',
                    $moduleId
                );
            }

            $data = $score === null ? [] : ['fraud_score' => $score];
            $held = in_array($verdict, self::HOLD, true);

            if ($held) {
                $status = $orders->statusId(self::HELD_STATUS);

                if ($status === null) {
                    // No status to hold it in, so it is not held: an order
                    // left pending with no invoice would look like a fault.
                    ErrorLog::note(
                        "The fraud check wanted order {$number} held, and there is no `fraud` order status to hold it in.",
                        'module',
                        $moduleId
                    );
                    $held = false;
                } else {
                    $data['status_relid'] = $status;
                }
            }

            if ($data !== []) {
                $orders->update((int) $order['oid'], $data);
            }

            return ['held' => $held, 'verdict' => $verdict, 'score' => $score, 'message' => $message];
        } catch (Throwable $e) {
            // Screening is never the reason a checkout fails.
            ErrorLog::record($e, 'app');

            return $none;
        }
    }

    /**
     * The Fraud Module Chosen, Or Null For None
     * @return ?string Module Directory, As Stored
     */
    public function chosen(): ?string
    {
        $value = trim((string) option(self::OPTION, self::NONE));

        return $value === '' || strcasecmp($value, self::NONE) === 0 ? null : $value;
    }

    /**
     * What The Chosen Module Is Called On Screen
     * @return ?string
     */
    public function name(): ?string
    {
        $chosen = $this->chosen();

        if ($chosen === null) {
            return null;
        }

        $directory = $this->installedModule($chosen);

        return $directory === null ? $chosen : $this->modules()[$directory]['name'];
    }

    /**
     * Choose Which Fraud Module Screens Checkout
     *
     * Stored in the ON-DISK spelling, so the screen's <select> finds it.
     * @param string $module Module Directory In Any Case, Or `none`
     * @return string What Is Stored Now
     * @throws RuntimeException When No Such Fraud Module Is Installed
     */
    public function choose(string $module): string
    {
        $module = trim($module);

        if ($module === '' || strcasecmp($module, self::NONE) === 0) {
            $this->put(self::NONE);

            return self::NONE;
        }

        $directory = $this->installedModule($module);

        if ($directory !== null) {
            $this->put($directory);

            return $directory;
        }

        // The one already chosen is accepted unchanged, installed or not - the
        // screen offers it, selected, so saving the card posts it straight back.
        $current = $this->chosen();

        if ($current !== null && strcasecmp($current, $module) === 0) {
            return $current;
        }

        throw new RuntimeException(
            "No fraud module called [{$module}] is installed. Put it in modules/fraud/ first."
        );
    }

    /**
     * Fraud Modules On Disk, Keyed By Directory
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

            if ($directory !== '') {
                $modules[$directory] = [
                    'name'    =>  (string) ($module['name'] ?? $directory),
                    'enabled' =>  !empty($module['enabled']),
                ];
            }
        }

        return $this->modules = $modules;
    }

    /**
     * The On-Disk Spelling Of a Fraud Module, If It Is Installed
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
     * What Checkout Will Actually Do
     *
     *   none     nothing chosen - no order is screened
     *   ready    the chosen module is on and its driver builds
     *   off      chosen, installed and switched off - orders go through unscreened
     *   missing  chosen and not on disk - the same
     *   broken   switched on and will not build - the same
     * @return string
     */
    public function state(): string
    {
        $module = $this->chosen();

        if ($module === null) {
            return 'none';
        }

        $directory = $this->installedModule($module);

        if ($directory === null) {
            return 'missing';
        }

        if (!$this->modules()[$directory]['enabled']) {
            return 'off';
        }

        return $this->driver($directory) !== null ? 'ready' : 'broken';
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * The Chosen Module's Directory When It Is Installed, Or Null
     * @return ?string
     */
    private function ready(): ?string
    {
        $chosen = $this->chosen();

        return $chosen === null ? null : $this->installedModule($chosen);
    }

    /**
     * Build The Driver - The Only Place a Fraud Module Is Constructed
     *
     * A class name read from a manifest is untrusted input: it must be loaded
     * this request, exist, and implement the contract. Built with its settings
     * and `mode`, like every module since Phase 40.
     * @param string $directory Module Directory
     * @return ?FraudInterface
     */
    private function driver(string $directory): ?FraudInterface
    {
        $class = ModuleSettings::loadedClass(self::TYPE, $directory) ?? '';

        if ($class === '' || !class_exists($class) || !is_subclass_of($class, FraudInterface::class)) {
            return null;
        }

        try {
            $driver = new $class((new Module())->settingsFor(ModuleManager::uid(self::TYPE, $directory), $class));
        } catch (Throwable) {
            return null;
        }

        return $driver instanceof FraudInterface ? $driver : null;
    }

    /**
     * What The Module Is Told About The Order
     * @param array $order Order Row
     * @return array
     */
    private function contextFor(array $order): array
    {
        $client = (new Client())->find((int) ($order['client_relid'] ?? 0)) ?? [];
        $currency = (new Currency())->find((int) ($order['currency_relid'] ?? 0)) ?? [];
        $countryId = (int) ($client['country_relid'] ?? 0);
        $country = $countryId > 0 ? (new Model())->table('countries')->where(['country_id' => $countryId])->first() : null;

        return [
            'client'   =>  $client,
            'email'    =>  (string) ($client['email'] ?? ''),
            'ip'       =>  (string) ($order['order_from_ip'] ?? ''),
            'amount'   =>  (string) ($order['amount'] ?? '0'),
            'currency' =>  (string) ($currency['currency_code'] ?? ''),
            'country'  =>  is_array($country) ? strtoupper((string) ($country['iso2'] ?? '')) : '',
            'timeout'  =>  self::TIMEOUT,
        ];
    }

    /**
     * A Score From 0 To 100, Or Null
     * @param mixed $score
     * @return ?string
     */
    private function score(mixed $score): ?string
    {
        if (!is_int($score) && !is_float($score) && !(is_string($score) && is_numeric($score))) {
            return null;
        }

        return number_format(max(0.0, min(100.0, (float) $score)), 2, '.', '');
    }

    /**
     * The Module's Row Id, For The Error Log
     * @param string $directory Module Directory
     * @return ?int
     */
    private function moduleId(string $directory): ?int
    {
        try {
            $row = (new ModuleModel())->where(['uid' => ModuleManager::uid(self::TYPE, $directory)])->first();

            return is_array($row) ? (((int) ($row['module_id'] ?? 0)) ?: null) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Store The Choice
     * @param string $value Module Directory, Or `none`
     * @return void
     */
    private function put(string $value): void
    {
        (new Setting())->putMany([self::OPTION => $value]);
    }
}
