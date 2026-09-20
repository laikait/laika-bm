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

namespace LBM\Support\Http;

// Deny Direct Access
defined('APP_PATH') || http_response_code(403) . die('403 Direct Access Denied!');

use Closure;
use Throwable;

/**
 * A small, bounded curl client - Phase 53, LBM's stand-in for plan U4.
 *
 * laika-core has no HTTP client, and LBM had two: Module\Api's curl code and a
 * stream_context fetch in the update check. Server modules (Phase 54),
 * gateways (56) and the licence and update feeds (62, 63) all need a third, so
 * there is one here instead. It depends on nothing of LBM's, so it can move to
 * `Laika\Core\Http\Client` as it stands; LBM then only changes its imports.
 *
 * The rules are the ones Module\Api already kept, moved here unchanged:
 *
 *   BOUNDED. A timeout capped at MAX_TIMEOUT, a connect timeout of its own, and
 *   a reply larger than MAX_BYTES abandoned rather than read. A provider that
 *   stops answering must not hold a checkout or a cron run.
 *
 *   NEVER THROWS. Every failure is a Response with status 0 and an error in
 *   words, so a caller cannot forget the try/catch.
 *
 *   NEVER LOGS. Requests carry API keys and passwords.
 *
 *   NO REDIRECTS, http AND https ONLY. A header carrying a key goes to the
 *   address it was sent to and nowhere else.
 *
 *   TLS IS VERIFIED. `verify => false` exists because a control panel on a
 *   server's own hostname very often has a self-signed certificate - and it is
 *   per call, so that a server module can offer it as a setting for one server
 *   and nothing else inherits it.
 *
 * RETRIES are off unless asked for, and only ever repeat a request that is safe
 * to repeat: GET, HEAD, PUT, DELETE or OPTIONS, after no answer at all or a
 * 429/502/503/504. A POST is never retried here - "create account" sent twice
 * is two accounts. Callers that need a POST retried do it through the queue,
 * where the job itself is written to be idempotent.
 *
 * FAKING. Client::fake() makes every Client answer from a table or a callable
 * instead of the network, and records what was sent - so tests of a module need
 * no sandbox and no connection. Client::restore() puts the network back.
 */
class Client
{
    /** @var float The Longest Any One Request May Take, In Seconds */
    public const MAX_TIMEOUT = 30.0;

    /** @var float The Longest a Connection May Take To Open, In Seconds */
    public const CONNECT_TIMEOUT = 5.0;

    /** @var int The Largest Reply That Is Read */
    public const MAX_BYTES = 1048576;

    /** @var int Most Retries One Request May Ask For */
    public const MAX_RETRIES = 3;

    /** @var string[] Methods That Are Safe To Send Twice */
    public const IDEMPOTENT = ['GET', 'HEAD', 'PUT', 'DELETE', 'OPTIONS'];

    /** @var int[] Answers Worth Waiting Out */
    public const RETRY_ON = [429, 502, 503, 504];

    /** @var ?Closure(string $method, string $url, array $options): Response */
    private static ?Closure $fake = null;

    /** @var array<int,array{method:string,url:string,options:array}> What Was Sent While Faked */
    private static array $sent = [];

    /** @var array{timeout:float, retries:int, retry_delay:int, verify:bool, headers:array<string,string>} */
    private array $defaults;

    /**
     * @param array{timeout?:float, retries?:int, retry_delay?:int, verify?:bool, headers?:array<string,string>} $defaults
     *        Applied to every request this client sends; any of them can be
     *        overridden per request. retry_delay is milliseconds.
     */
    public function __construct(array $defaults = [])
    {
        $this->defaults = [
            'timeout'     =>  (float) ($defaults['timeout'] ?? 10.0),
            'retries'     =>  (int) ($defaults['retries'] ?? 0),
            'retry_delay' =>  (int) ($defaults['retry_delay'] ?? 500),
            'verify'      =>  (bool) ($defaults['verify'] ?? true),
            'headers'     =>  (array) ($defaults['headers'] ?? []),
        ];
    }

    ####################################################################################
    /*================================= EXTERNAL API =================================*/
    ####################################################################################

    /**
     * GET, With $query As The Query String
     * @param string $url
     * @param array $query
     * @param array<string,string> $headers
     * @return Response
     */
    public function get(string $url, array $query = [], array $headers = []): Response
    {
        return $this->send('GET', $url, ['query' => $query, 'headers' => $headers]);
    }

    /**
     * POST a JSON Body
     * @param string $url
     * @param array $json
     * @param array<string,string> $headers
     * @return Response
     */
    public function postJson(string $url, array $json, array $headers = []): Response
    {
        return $this->send('POST', $url, ['json' => $json, 'headers' => $headers]);
    }

    /**
     * POST a Form Body
     * @param string $url
     * @param array $form
     * @param array<string,string> $headers
     * @return Response
     */
    public function postForm(string $url, array $form, array $headers = []): Response
    {
        return $this->send('POST', $url, ['form' => $form, 'headers' => $headers]);
    }

    /**
     * Send Anything
     *
     * Options, each optional:
     *   query    array   appended to the URL
     *   json     array   sent as JSON, Content-Type set unless a header says otherwise
     *   form     array   sent urlencoded, likewise
     *   body     string  sent exactly as it is
     *   headers  array   name => value; a name or value with a line break is dropped
     *   auth     array   [user, password] for HTTP basic auth
     *   timeout  float   seconds, capped at MAX_TIMEOUT
     *   retries  int     see the class docblock; capped at MAX_RETRIES
     *   verify   bool    TLS certificate checks, on unless false
     * @param string $method
     * @param string $url
     * @param array $options
     * @return Response
     */
    public function send(string $method, string $url, array $options = []): Response
    {
        $method = strtoupper(trim($method)) ?: 'GET';
        $options += $this->defaults;
        $options['headers'] = (array) $options['headers'] + $this->defaults['headers'];

        if (!empty($options['query']) && is_array($options['query'])) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($options['query']);
        }

        $retries = in_array($method, self::IDEMPOTENT, true)
            ? max(0, min((int) $options['retries'], self::MAX_RETRIES))
            : 0;

        $attempt = 0;

        while (true) {
            $response = $this->once($method, $url, $options);

            if ($attempt >= $retries || !$this->worthRetrying($response)) {
                return $response;
            }

            $attempt++;
            $this->pause($response, (int) $options['retry_delay'] * $attempt);
        }
    }

    ####################################################################################
    /*==================================== FAKING ====================================*/
    ####################################################################################

    /**
     * Answer Every Request Without The Network
     *
     * Either a callable - fn(string $method, string $url, array $options): Response|array
     * - or a table of URL patterns (fnmatch, optionally prefixed with a method:
     * `POST https://api.example.com/*`) to a Response or to
     * ['status' => .., 'body' => .., 'headers' => ..]. An array body is JSON
     * encoded. A request nothing matches gets status 0, "not faked", so a test
     * cannot quietly reach the real network.
     * @param callable|array $responder
     * @return void
     */
    public static function fake(callable|array $responder): void
    {
        self::$sent = [];

        self::$fake = is_callable($responder)
            ? Closure::fromCallable($responder)
            : static function (string $method, string $url) use ($responder) {
                foreach ($responder as $pattern => $answer) {
                    $pattern = (string) $pattern;
                    $wanted = null;

                    if (preg_match('/^([A-Z]+)\s+(.+)$/', $pattern, $m)) {
                        [$wanted, $pattern] = [$m[1], $m[2]];
                    }

                    if (($wanted === null || $wanted === $method) && fnmatch($pattern, $url)) {
                        return $answer;
                    }
                }

                return Response::failure("Not faked: {$method} {$url}");
            };
    }

    /**
     * Put The Network Back
     * @return void
     */
    public static function restore(): void
    {
        self::$fake = null;
        self::$sent = [];
    }

    /**
     * What Was Sent Since fake()
     * @return array<int,array{method:string,url:string,options:array}>
     */
    public static function sent(): array
    {
        return self::$sent;
    }

    ####################################################################################
    /*================================= INTERNAL API =================================*/
    ####################################################################################

    /**
     * One Attempt, Never Throwing
     * @param string $method
     * @param string $url
     * @param array $options
     * @return Response
     */
    private function once(string $method, string $url, array $options): Response
    {
        if (self::$fake !== null) {
            self::$sent[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return $this->fakeAnswer($method, $url, $options);
        }

        try {
            return $this->transfer($method, $url, $options);
        } catch (Throwable $e) {
            return Response::failure('The request could not be made: ' . $e->getMessage());
        }
    }

    /**
     * The curl Call
     * @param string $method
     * @param string $url
     * @param array $options
     * @return Response
     */
    private function transfer(string $method, string $url, array $options): Response
    {
        if (!preg_match('#^https?://#i', $url)) {
            return Response::failure('Only http and https addresses are called.');
        }

        if (!function_exists('curl_init')) {
            return Response::failure('The curl extension is not installed, so nothing can be called.');
        }

        [$lines, $body] = $this->prepare($method, $options);
        $timeout = max(0.1, min((float) $options['timeout'], self::MAX_TIMEOUT));

        $received = '';
        $headers = [];
        $tooBig = false;

        $handle = curl_init($url);

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST     =>  $method,
            CURLOPT_HTTPHEADER        =>  $lines,
            CURLOPT_FOLLOWLOCATION    =>  false,
            CURLOPT_PROTOCOLS         =>  CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT_MS        =>  (int) ($timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS =>  (int) (min($timeout, self::CONNECT_TIMEOUT) * 1000),
            CURLOPT_NOSIGNAL          =>  true,
            CURLOPT_SSL_VERIFYPEER    =>  (bool) $options['verify'],
            CURLOPT_SSL_VERIFYHOST    =>  $options['verify'] ? 2 : 0,

            CURLOPT_HEADERFUNCTION    =>  static function ($curl, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);

                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },

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

        if (is_array($options['auth'] ?? null) && count($options['auth']) === 2) {
            curl_setopt($handle, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($handle, CURLOPT_USERPWD, $options['auth'][0] . ':' . $options['auth'][1]);
        }

        $done = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = $done === false ? (string) curl_error($handle) : '';

        curl_close($handle);

        if ($tooBig) {
            return new Response(0, $received, $headers, 'The reply was larger than ' . self::MAX_BYTES . ' bytes, so it was not read.');
        }

        if ($error !== '') {
            return Response::failure($error);
        }

        return new Response($status, $received, $headers);
    }

    /**
     * Header Lines And The Body
     * @param string $method
     * @param array $options
     * @return array{0:string[],1:?string}
     */
    private function prepare(string $method, array $options): array
    {
        $lines = [];
        $typed = false;
        $accepts = false;

        foreach ((array) $options['headers'] as $name => $value) {
            $name = trim((string) $name);
            $value = (string) $value;

            // A line break in a header is a second header smuggled in.
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

        if ($method !== 'GET' && $method !== 'HEAD') {
            if (isset($options['json']) && is_array($options['json'])) {
                $body = (string) json_encode($options['json']);

                if (!$typed) {
                    $lines[] = 'Content-Type: application/json';
                }
            } elseif (isset($options['form']) && is_array($options['form'])) {
                $body = http_build_query($options['form']);

                if (!$typed) {
                    $lines[] = 'Content-Type: application/x-www-form-urlencoded';
                }
            } elseif (isset($options['body']) && is_string($options['body'])) {
                $body = $options['body'];
            }
        }

        return [$lines, $body];
    }

    /**
     * @param Response $response
     * @return bool
     */
    private function worthRetrying(Response $response): bool
    {
        // A reply refused for its size would be exactly as big the next time.
        if ($response->failed()) {
            return !str_starts_with((string) $response->error, 'The reply was larger')
                && !str_starts_with((string) $response->error, 'Only http')
                && !str_starts_with((string) $response->error, 'The curl extension')
                && !str_starts_with((string) $response->error, 'Not faked');
        }

        return in_array($response->status, self::RETRY_ON, true);
    }

    /**
     * Wait Before The Next Attempt
     *
     * Retry-After is honoured when it asks for no more than five seconds; one
     * asking for longer is a provider saying "not now", which is a job for the
     * queue's backoff, not for a request holding a process open.
     * @param Response $response
     * @param int $milliseconds
     * @return void
     */
    private function pause(Response $response, int $milliseconds): void
    {
        $after = $response->header('Retry-After');

        if ($after !== null && ctype_digit($after) && (int) $after <= 5) {
            $milliseconds = max($milliseconds, (int) $after * 1000);
        }

        if (self::$fake === null && $milliseconds > 0) {
            usleep(min($milliseconds, 5000) * 1000);
        }
    }

    /**
     * @param string $method
     * @param string $url
     * @param array $options
     * @return Response
     */
    private function fakeAnswer(string $method, string $url, array $options): Response
    {
        try {
            $answer = (self::$fake)($method, $url, $options);
        } catch (Throwable $e) {
            return Response::failure('The fake raised an error: ' . $e->getMessage());
        }

        if ($answer instanceof Response) {
            return $answer;
        }

        if (!is_array($answer)) {
            return Response::failure('The fake answered with neither a Response nor an array.');
        }

        $body = $answer['body'] ?? '';

        return new Response(
            (int) ($answer['status'] ?? 200),
            is_array($body) ? (string) json_encode($body) : (string) $body,
            array_change_key_case((array) ($answer['headers'] ?? []), CASE_LOWER),
            isset($answer['error']) ? (string) $answer['error'] : null,
        );
    }
}
