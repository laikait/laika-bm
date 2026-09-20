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

use ReflectionClass;
use Throwable;
use LBM\Support\Http\Client;

/**
 * What a module's own API client extends - Phase 40.
 *
 * ---------------------------------------------------------------------------
 * THE ADDRESSES LIVE IN THE MODULE, NOT ON A SCREEN
 * ---------------------------------------------------------------------------
 * A module knows where its provider's live API is and where its sandbox is, so
 * it says so in two constants on its own Api class - `API_URL`, and
 * `TEST_API_URL` when there is a sandbox - and the operator never types an
 * address. What the operator chooses is the MODE - live or test - and it arrives
 * in the settings every driver is constructed with, under `mode`. (Phase 46.
 * Until then every module wrote an endpoints() method to say the same thing.)
 *
 * Only the word `test` selects the sandbox. A missing mode, a misspelt one, or
 * one this class has never heard of is LIVE, because the alternative is a
 * module somebody believes is live quietly talking to a sandbox and taking no
 * money at all.
 *
 * And the other direction is absolute: a module in test mode whose API has no
 * test address REFUSES. It never falls back to live. A sandbox switch that
 * charges real cards when the sandbox is missing is worse than no switch.
 *
 * ---------------------------------------------------------------------------
 * request() IS BOUNDED, NEVER THROWS, AND NEVER LOGS
 * ---------------------------------------------------------------------------
 * Bounded: a timeout capped at MAX_TIMEOUT, a connect timeout of its own, and a
 * reply larger than MAX_BYTES is refused rather than read into memory. A
 * provider that stops answering must not hold a checkout, or a cron run, for as
 * long as it likes.
 *
 * Never throws: every failure - a refused connection, a timeout, no curl at all
 * - comes back as `status` 0 and an `error` in words, so a module can report it
 * without a try/catch it will one day forget.
 *
 * Never logs: the request carries the operator's API key, and nothing here
 * writes a request or a reply anywhere.
 *
 * Redirects are NOT followed, and only http and https are called. A header
 * carrying an API key goes to the address it was sent to and nowhere else.
 *
 * Since Phase 53 all four rules are enforced by LBM\Support\Http\Client,
 * which this class now sends through, so every other outbound call gets them
 * too. TLS verification can be turned off per module instance with a
 * `verify_tls` setting - see verifiesTls().
 */
abstract class Api
{
    /** @var string The Mode That Calls The Real Service */
    public const LIVE = 'live';

    /** @var string The Mode That Calls The Sandbox */
    public const TEST = 'test';

    /** @var float The Longest Any One Request May Take, In Seconds */
    public const MAX_TIMEOUT = Client::MAX_TIMEOUT;

    /** @var float The Longest a Connection May Take To Open, In Seconds */
    public const CONNECT_TIMEOUT = Client::CONNECT_TIMEOUT;

    /** @var int The Largest Reply That Is Read */
    public const MAX_BYTES = Client::MAX_BYTES;

    /** @var array<string,mixed> The Settings The Module Was Constructed With */
    protected array $settings;

    /** @var string live Or test */
    private string $mode;

    /**
     * @param array<string,mixed> $settings What the driver was given - its
     *        declared settings, opened, plus `mode`
     */
    public function __construct(array $settings = [])
    {
        $this->settings = $settings;
        $this->mode = self::modeOf($settings['mode'] ?? null);
    }

    /**
     * Where The Provider's API Is, For Each Mode - Phase 46
     *
     * Read from two constants on the module's own Api class, with any
     * visibility:
     *
     *     protected const API_URL      = 'api.example.com/v1';
     *     protected const TEST_API_URL = 'sandbox.example.com/v1';
     *
     * Leave TEST_API_URL out for a provider with no sandbox, and test mode
     * refuses every call rather than touching the live one. An address written
     * without a scheme is called over https - see base().
     *
     * Override this instead when the address is not fixed. A control panel's is
     * whichever server the account is on, which is how the server Example builds
     * its own.
     * @return array{live?: string, test?: string}
     */
    protected function endpoints(): array
    {
        $points = [];

        foreach ([self::LIVE => 'API_URL', self::TEST => 'TEST_API_URL'] as $mode => $name) {
            $address = $this->declared($name);

            if ($address !== '') {
                $points[$mode] = $address;
            }
        }

        return $points;
    }

    ##############################################################################
    /*============================== EXTERNAL API ==============================*/
    ##############################################################################

    /**
     * Read a Mode Out Of Anything
     *
     * `test` in any case is the sandbox. Everything else is live - see the class
     * docblock for why a word nobody recognises must not select the sandbox.
     * @param mixed $value
     * @return string
     */
    public static function modeOf(mixed $value): string
    {
        return is_string($value) && strtolower(trim($value)) === self::TEST ? self::TEST : self::LIVE;
    }

    /**
     * @return string live Or test
     */
    public function mode(): string
    {
        return $this->mode;
    }

    /**
     * @return bool Whether This Is The Sandbox
     */
    public function isTest(): bool
    {
        return $this->mode === self::TEST;
    }

    /**
     * One Of The Settings The Module Was Constructed With
     * @param string $key Setting Name, As The Module Declared It
     * @param mixed $default
     * @return mixed
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->settings) ? $this->settings[$key] : $default;
    }

    /**
     * The Whole Address For a Path, In The Current Mode
     * @param string $path Relative To The Mode's Base Address
     * @return string '' When There Is No Address For This Mode
     */
    public function url(string $path = ''): string
    {
        $base = $this->base();

        if ($base === '') {
            return '';
        }

        $path = ltrim($path, '/');

        return $path === '' ? $base : $base . '/' . $path;
    }

    /**
     * Call The Provider
     *
     * GET and HEAD send `$data` as a query string. Anything else sends an array
     * as JSON, with `Content-Type: application/json` unless a header says
     * otherwise, and a string exactly as it is.
     * @param string $method HTTP Method
     * @param string $path Relative To The Mode's Base Address
     * @param array|string $data Query Or Body
     * @param array<string,string> $headers Name => Value
     * @param float $timeout Seconds, Capped At MAX_TIMEOUT
     * @return array{status: int, body: string, json: ?array, error: ?string}
     *   `status` is 0 whenever no HTTP answer arrived, and `error` then says why.
     */
    public function request(
        string $method,
        string $path,
        array|string $data = [],
        array $headers = [],
        float $timeout = 10.0
    ): array {
        try {
            return $this->send($method, $path, $data, $headers, $timeout);
        } catch (Throwable $e) {
            return $this->failed('The request could not be made: ' . $e->getMessage());
        }
    }

    ##############################################################################
    /*============================== INTERNAL API ==============================*/
    ##############################################################################

    /**
     * The Base Address For The Current Mode
     * @return string
     */
    private function base(): string
    {
        try {
            $points = $this->endpoints();
        } catch (Throwable) {
            return '';
        }

        $address = $points[$this->mode] ?? '';
        $base = is_string($address) ? rtrim(trim($address), '/') : '';

        if ($base === '') {
            return '';
        }

        // Written without a scheme - `api.example.com/v1`, the operator's own
        // example - it is called over https. Anything that names a scheme is
        // left as written, and send() still refuses whatever is not http(s).
        return preg_match('#^[a-z][a-z0-9+.\-]*://#i', $base) === 1 ? $base : 'https://' . $base;
    }

    /**
     * One Of The Address Constants, Whatever Its Visibility
     *
     * By reflection, because this class cannot name a constant it does not
     * declare - and must not declare one: a module writing `protected const
     * API_URL`, as the operator's own example does, would then be narrowing a
     * public constant, which PHP refuses outright.
     * @param string $name API_URL Or TEST_API_URL
     * @return string '' When It Is Not Declared, Or Is Not a String
     */
    private function declared(string $name): string
    {
        $class = new ReflectionClass(static::class);

        if (!$class->hasConstant($name)) {
            return '';
        }

        $value = $class->getConstant($name);

        return is_string($value) ? trim($value) : '';
    }

    /**
     * Make The Call
     * @param string $method
     * @param string $path
     * @param array|string $data
     * @param array<string,string> $headers
     * @param float $timeout
     * @return array{status: int, body: string, json: ?array, error: ?string}
     */
    private function send(string $method, string $path, array|string $data, array $headers, float $timeout): array
    {
        $url = $this->url($path);

        if ($url === '') {
            return $this->failed($this->isTest()
                ? 'This module has no test address, so nothing was sent. Test mode never falls back to live.'
                : 'This module has no live address, so nothing was sent.');
        }

        // Phase 53: the transport is Support\Http\Client - the same bounds,
        // header checks and never-throw rule this method used to carry itself,
        // now shared with everything else LBM calls. The contract below is
        // unchanged: GET and HEAD send $data as a query, anything else sends an
        // array as JSON and a string as it is.
        $method = strtoupper(trim($method)) ?: 'GET';
        $options = ['headers' => $headers, 'timeout' => $timeout, 'verify' => $this->verifiesTls()];

        if ($method === 'GET' || $method === 'HEAD') {
            $options['query'] = is_array($data) ? $data : [];
        } elseif (is_array($data)) {
            $options['json'] = $data;
        } else {
            $options['body'] = $data;
        }

        return (new Client())->send($method, $url, $options)->toArray();
    }

    /**
     * Whether TLS Certificates Are Checked - Phase 53
     *
     * Yes, unless the module's settings carry `verify_tls` set to something
     * false. A server module offers that for a control panel on a self-signed
     * certificate; nothing else should.
     * @return bool
     */
    protected function verifiesTls(): bool
    {
        $value = $this->settings['verify_tls'] ?? true;

        return !in_array(is_string($value) ? strtolower(trim($value)) : $value, [false, 0, '0', 'no', 'off', 'false'], true);
    }

    /**
     * No HTTP Answer, And Why
     * @param string $error
     * @return array{status: int, body: string, json: null, error: string}
     */
    private function failed(string $error): array
    {
        return ['status' => 0, 'body' => '', 'json' => null, 'error' => $error];
    }
}
