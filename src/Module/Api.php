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

namespace LBM\Module;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Throwable;

/**
 * What a module's own API client extends - Phase 40.
 *
 * ---------------------------------------------------------------------------
 * THE ADDRESSES LIVE IN THE MODULE, NOT ON A SCREEN
 * ---------------------------------------------------------------------------
 * A module knows where its provider's live API is and where its sandbox is, so
 * it says so in `endpoints()` and the operator never types an address. What the
 * operator chooses is the MODE - live or test - and it arrives in the settings
 * every driver is constructed with, under `mode`.
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
 */
abstract class Api
{
    /** @var string The Mode That Calls The Real Service */
    public const LIVE = 'live';

    /** @var string The Mode That Calls The Sandbox */
    public const TEST = 'test';

    /** @var float The Longest Any One Request May Take, In Seconds */
    public const MAX_TIMEOUT = 30.0;

    /** @var float The Longest a Connection May Take To Open, In Seconds */
    public const CONNECT_TIMEOUT = 5.0;

    /** @var int The Largest Reply That Is Read */
    public const MAX_BYTES = 1048576;

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
     * Where The Provider's API Is, For Each Mode
     *
     * A base address per mode, with no trailing slash needed:
     *
     *     return ['live' => 'https://api.example.com/v1', 'test' => 'https://sandbox.example.com/v1'];
     *
     * Leave `test` out for a provider with no sandbox, and test mode refuses
     * every call rather than touching the live one.
     * @return array{live?: string, test?: string}
     */
    abstract protected function endpoints(): array;

    ####################################################################################
    /*================================= EXTERNAL API =================================*/
    ####################################################################################

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

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

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

        return rtrim(trim((string) ($points[$this->mode] ?? '')), '/');
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

        if (!preg_match('#^https?://#i', $url)) {
            return $this->failed('Only http and https addresses are called.');
        }

        if (!function_exists('curl_init')) {
            return $this->failed('The curl extension is not installed, so no module can call its API.');
        }

        $method = strtoupper(trim($method)) ?: 'GET';
        $timeout = max(0.1, min($timeout, self::MAX_TIMEOUT));

        $lines = [];
        $typed = false;
        $accepts = false;

        foreach ($headers as $name => $value) {
            $name = trim((string) $name);
            $value = (string) $value;

            // A line break in a header is a second header smuggled in - the
            // same reason Laika Whois refuses one in a domain name.
            if ($name === '' || preg_match('/[\r\n:]/', $name) || preg_match('/[\r\n]/', $value)) {
                continue;
            }

            $typed = $typed || strcasecmp($name, 'Content-Type') === 0;
            $accepts = $accepts || strcasecmp($name, 'Accept') === 0;
            $lines[] = $name . ': ' . $value;
        }

        if (!$accepts) {
            $lines[] = 'Accept: application/json';
        }

        $body = null;

        if ($method === 'GET' || $method === 'HEAD') {
            if (is_array($data) && $data !== []) {
                $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($data);
            }
        } elseif (is_array($data)) {
            $body = (string) json_encode($data);

            if (!$typed) {
                $lines[] = 'Content-Type: application/json';
            }
        } else {
            $body = $data;
        }

        $received = '';
        $tooBig = false;

        $handle = curl_init($url);

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST     =>  $method,
            CURLOPT_HTTPHEADER        =>  $lines,
            CURLOPT_FOLLOWLOCATION    =>  false,
            CURLOPT_TIMEOUT_MS        =>  (int) ($timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS =>  (int) (min($timeout, self::CONNECT_TIMEOUT) * 1000),
            CURLOPT_NOSIGNAL          =>  true,

            // Read in pieces and stop at the cap. Returning fewer bytes than
            // were handed over is how curl is told to abandon the transfer.
            CURLOPT_WRITEFUNCTION     =>  static function ($curl, string $chunk) use (&$received, &$tooBig): int {
                if (strlen($received) + strlen($chunk) > self::MAX_BYTES) {
                    $tooBig = true;

                    return 0;
                }

                $received .= $chunk;

                return strlen($chunk);
            },
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        if ($method === 'HEAD') {
            curl_setopt($handle, CURLOPT_NOBODY, true);
        }

        $done = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = $done === false ? (string) curl_error($handle) : '';

        curl_close($handle);

        if ($tooBig) {
            return [
                'status' =>  0,
                'body'   =>  $received,
                'json'   =>  null,
                'error'  =>  'The reply was larger than ' . self::MAX_BYTES . ' bytes, so it was not read.',
            ];
        }

        if ($error !== '') {
            return $this->failed($error);
        }

        $json = null;

        if ($received !== '') {
            try {
                $decoded = json_decode($received, true, 512, JSON_THROW_ON_ERROR);
                $json = is_array($decoded) ? $decoded : null;
            } catch (Throwable) {
                $json = null;
            }
        }

        return ['status' => $status, 'body' => $received, 'json' => $json, 'error' => null];
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
