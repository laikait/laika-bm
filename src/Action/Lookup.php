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
use LBM\Support\LooksUpDomains;
use LBM\Support\RegistersDomains;

/**
 * Whether a domain can be registered, and who is asked - Phase 36.
 *
 * Until Phase 36 the only thing that could answer was the REGISTRAR module a
 * TLD points at, so an operator who registers names by hand - a supported way
 * to run this shop - had a public search that said "we will check this one for
 * you" about every name anybody typed. A lookup module answers without
 * registering anything, and Laika Whois ships as one.
 *
 * ---------------------------------------------------------------------------
 * THE ORDER, AND WHY
 * ---------------------------------------------------------------------------
 *   1. the lookup module the operator chose;
 *   2. the registrar the TLD points at;
 *   3. could not say.
 *
 * A WHOIS query costs nothing. A registrar call spends an API credential and is
 * rate-limited, and a search asks about every TLD on the price list - so the
 * free question goes first and the expensive one only when it went unanswered.
 *
 * ANYTHING BUT A BOOLEAN FROM A SUCCESSFUL CALL FALLS THROUGH: null, a failed
 * call (whatever `available` it carries beside `success: false`), an exception,
 * a string. A failed call's answer is not an answer, and "yes" is not true.
 *
 * ---------------------------------------------------------------------------
 * THE CHOICE LIVES IN `options`
 * ---------------------------------------------------------------------------
 * `domain_lookup_module` holds the module DIRECTORY, or `none`. Missing means
 * Laika Whois: a fresh install switches that module on as it finishes, so a
 * new shop answers a search on its first day; an UPGRADED install has it on
 * disk and switched off, so its searches go on reaching the registrar exactly
 * as they did until somebody switches it on. `none` rather than an empty
 * string, because an option stored as '' reads as absent to more than one
 * layer of this product.
 *
 * Refusals are English, like every Action's: they reach the screen through
 * attempt(), and no catalogue is loaded when cron constructs an Action.
 */
class Lookup extends Action
{
    use LooksUpDomains;
    use RegistersDomains;

    /** @var string The Module Type, Which Is Also Its Directory */
    public const TYPE = 'lookup';

    /** @var string Where The Choice Is Stored */
    public const OPTION = 'domain_lookup_module';

    /** @var string What An Install That Never Chose Asks */
    public const DEFAULT = 'LaikaWhois';

    /** @var string The Stored Value For "Ask The Registrar Only" */
    public const NONE = 'none';

    /** @var array<string,array{name:string,enabled:bool}>|null Lookup Modules On Disk */
    private ?array $modules = null;

    /**
     * The Options Table - The Only Thing This Writes
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
     * Can This Name Be Registered?
     *
     * Null for every way of not knowing. The contract makes the same
     * distinction for the same reason: an unanswered question must never read
     * as a refusal, and must never read as a yes.
     * @param array $tld The TLD Row The Name Is On
     * @param string $name The Whole Name, TLD Included
     * @param float $timeout Seconds This May Take - What Is Left Of The Search's Budget
     * @return ?bool
     */
    public function available(array $tld, string $name, float $timeout): ?bool
    {
        $context = [
            'timeout' =>  max(0.1, $timeout),
            'tld'     =>  (string) ($tld['tld'] ?? ''),
        ];

        $module = $this->chosen();
        $lookup = $module === null ? null : $this->lookupDriver($module);

        if ($lookup !== null) {
            $answer = $this->read(static fn (): mixed => $lookup->available($name, $context));

            if ($answer !== null) {
                return $answer;
            }
        }

        $registrar = $this->registrarRow((int) ($tld['registrar_relid'] ?? 0));
        $driver = $registrar === null ? null : $this->registrarDriver($registrar);

        return $driver === null
            ? null
            : $this->read(static fn (): mixed => $driver->available($name, $context));
    }

    /**
     * The Lookup Module Chosen, Or Null For None
     * @return ?string Module Directory, As Stored
     */
    public function chosen(): ?string
    {
        $value = trim((string) option(self::OPTION, self::DEFAULT));

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
     * Choose Which Lookup Module a Search Asks First
     *
     * Stored in the ON-DISK spelling. The loader matches case-insensitively,
     * so `probe` would work - but the registrars screen's <select> would not
     * find it, and the next save would post whichever option the browser landed
     * on.
     * @param string $module Module Directory In Any Case, Or `none`
     * @return string What Is Stored Now
     * @throws RuntimeException When No Such Lookup Module Is Installed
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

        // THE ONE ALREADY CHOSEN IS ACCEPTED UNCHANGED, installed or not -
        // Phase 35's select trap. The screen offers it, selected, so saving the
        // card for any other reason posts it straight back; refusing it here
        // would make the card unsaveable until the module is restored.
        $current = $this->chosen();

        if ($current !== null && strcasecmp($current, $module) === 0) {
            return $current;
        }

        throw new RuntimeException(
            "No lookup module called [{$module}] is installed. Put it in modules/lookup/ first."
        );
    }

    /**
     * Lookup Modules On Disk, Keyed By Directory
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
     * The On-Disk Spelling Of a Lookup Module, If It Is Installed
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
     * What a Search Will Actually Do First
     *
     *   none     nothing chosen - every search asks the TLD's registrar
     *   ready    the chosen module is on and its driver builds
     *   off      the chosen module is installed and switched off
     *   missing  the chosen module is not on disk
     *   broken   the chosen module is switched on and will not build
     *
     * The last four look identical in the options table and all mean the same
     * thing to a search - the registrar is asked instead - so the screen has to
     * say which, because each needs a different fix.
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

        return $this->lookupDriver($directory) !== null ? 'ready' : 'broken';
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * One Module's Answer, Or Null For Every Way Of Not Having One
     * @param callable $ask Makes The Call
     * @return ?bool
     */
    private function read(callable $ask): ?bool
    {
        try {
            $result = $ask();
        } catch (Throwable) {
            return null;
        }

        if (!is_array($result) || empty($result['success'])) {
            return null;
        }

        $available = $result['available'] ?? null;

        return is_bool($available) ? $available : null;
    }

    /**
     * Store The Choice
     *
     * Through Setting, which already knows the two ways writing an option goes
     * wrong on this stack - Option::insert()'s truthiness test, and PostgreSQL
     * rolling a lastInsertId() failure back along with the row.
     * @param string $value Module Directory, Or `none`
     * @return void
     */
    private function put(string $value): void
    {
        (new Setting())->putMany([self::OPTION => $value]);
    }
}
