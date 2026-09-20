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

namespace LBM\Tests\Http;

use LBM\Module\Api;
use LBM\Support\Http\Client;
use LBM\Support\Http\Response;
use LBM\Tests\TestCase;

/** An Api the way a module writes one: two addresses, nothing else. */
final class ProviderApi extends Api
{
    protected const API_URL = 'api.provider.test/v1';
    protected const TEST_API_URL = 'sandbox.provider.test/v1';
}

/**
 * Phase 53 (plan U4, LBM stand-in): the client never throws, never reaches the
 * network while faked, retries only what is safe to repeat - and Module\Api,
 * now sending through it, keeps the contract every module was written against.
 */
final class ClientTest extends TestCase
{
    protected function tearDown(): void
    {
        Client::restore();
        parent::tearDown();
    }

    public function testATableAnswersAndRecordsWhatWasSent(): void
    {
        Client::fake([
            'POST https://api.provider.test/*' => ['status' => 201, 'body' => ['id' => 7]],
            'https://api.provider.test/*'      => ['status' => 200, 'body' => 'plain', 'headers' => ['X-Thing' => 'yes']],
        ]);

        $created = (new Client())->postJson('https://api.provider.test/things', ['name' => 'a']);
        $read = (new Client())->get('https://api.provider.test/things', ['page' => 2]);

        self::assertSame(201, $created->status);
        self::assertSame(['id' => 7], $created->json());
        self::assertSame('plain', $read->body);
        self::assertSame('yes', $read->header('x-thing'));

        $sent = Client::sent();
        self::assertCount(2, $sent);
        self::assertSame(['name' => 'a'], $sent[0]['options']['json']);
        self::assertSame('https://api.provider.test/things?page=2', $sent[1]['url']);
    }

    public function testAnythingNotFakedIsRefusedRatherThanSent(): void
    {
        Client::fake([]);

        $reply = (new Client())->get('https://elsewhere.test/');

        self::assertTrue($reply->failed());
        self::assertStringContainsString('Not faked', (string) $reply->error);
    }

    public function testOnlyHttpAndHttpsAreCalled(): void
    {
        $reply = (new Client())->get('file:///etc/passwd');

        self::assertSame(0, $reply->status);
        self::assertStringContainsString('Only http and https', (string) $reply->error);
    }

    public function testAGetIsRetriedThroughA503(): void
    {
        $calls = 0;

        Client::fake(static function () use (&$calls): Response {
            return ++$calls < 3 ? new Response(503) : new Response(200, 'ok');
        });

        $reply = (new Client(['retries' => 3]))->get('https://api.provider.test/x');

        self::assertSame(200, $reply->status);
        self::assertSame(3, $calls);
    }

    public function testAPostIsNeverRetried(): void
    {
        $calls = 0;

        Client::fake(static function () use (&$calls): Response {
            $calls++;

            return new Response(503);
        });

        $reply = (new Client(['retries' => 3]))->postJson('https://api.provider.test/accounts', []);

        self::assertSame(503, $reply->status);
        self::assertSame(1, $calls, 'Creating an account twice is two accounts.');
    }

    public function testApiKeepsItsContractThroughTheClient(): void
    {
        Client::fake(['*' => ['status' => 200, 'body' => ['ok' => true]]]);

        $live = (new ProviderApi(['mode' => 'live']))->request('GET', 'domains', ['q' => 'x']);
        $test = (new ProviderApi(['mode' => 'test', 'verify_tls' => 'no']))->request('POST', 'domains', ['name' => 'a']);

        self::assertSame(['status' => 200, 'body' => '{"ok":true}', 'json' => ['ok' => true], 'error' => null], $live);
        self::assertSame(200, $test['status']);

        [$get, $post] = Client::sent();

        self::assertSame('https://api.provider.test/v1/domains?q=x', $get['url']);
        self::assertTrue($get['options']['verify']);
        self::assertSame('https://sandbox.provider.test/v1/domains', $post['url']);
        self::assertSame(['name' => 'a'], $post['options']['json']);
        self::assertFalse($post['options']['verify'], 'verify_tls = no turns certificate checks off for that module only.');
    }
}
