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

use Throwable;

/**
 * What Client::send() hands back - Phase 53 (plan U4).
 *
 * Always an object, never an exception. `status` is 0 whenever no HTTP answer
 * arrived - refused, timed out, too big, no curl - and `error` then says why in
 * words. A 4xx or 5xx is an answer, so it has a status and no error: whether a
 * 404 is bad news depends on the caller, not on the transport.
 */
final class Response
{
    /** @var ?array Decoded Body, Worked Out On First Ask */
    private ?array $decoded = null;

    private bool $parsed = false;

    /**
     * @param int $status HTTP Status, 0 When None Arrived
     * @param string $body Raw Body
     * @param array<string,string> $headers Lower-Cased Name => Value
     * @param ?string $error Why No Answer Arrived
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body = '',
        public readonly array $headers = [],
        public readonly ?string $error = null,
    ) {}

    /**
     * No Answer, And Why
     * @param string $error
     * @return self
     */
    public static function failure(string $error): self
    {
        return new self(0, '', [], $error);
    }

    /**
     * @return bool A 2xx Answer
     */
    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * @return bool No HTTP Answer At All
     */
    public function failed(): bool
    {
        return $this->status === 0;
    }

    /**
     * The Body As a JSON Object Or List, Or Null
     * @return ?array
     */
    public function json(): ?array
    {
        if (!$this->parsed) {
            $this->parsed = true;

            try {
                $value = $this->body === '' ? null : json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
                $this->decoded = is_array($value) ? $value : null;
            } catch (Throwable) {
                $this->decoded = null;
            }
        }

        return $this->decoded;
    }

    /**
     * One Response Header
     * @param string $name Any Case
     * @return ?string
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * The Shape Module\Api::request() Has Always Returned
     * @return array{status:int, body:string, json:?array, error:?string}
     */
    public function toArray(): array
    {
        return ['status' => $this->status, 'body' => $this->body, 'json' => $this->json(), 'error' => $this->error];
    }
}
