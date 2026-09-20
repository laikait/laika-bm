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

namespace LBM\Tests\Modules;

use Laika\Service\Vault;
use LBM\Support\Http\Client;
use LBM\Support\Http\Response;
use LBM\Tests\TestCase;
use Modules\Servers\Plesk\Plesk;

require_once APP_PATH . '/modules/servers/Plesk/src/Xml.php';
require_once APP_PATH . '/modules/servers/Plesk/src/Plesk.php';

/**
 * Phase 54: the Plesk module against a faked XML API - the packets it builds,
 * that values are escaped, and that terminate never takes what is not its own.
 */
final class PleskModuleTest extends TestCase
{
    private const AGENT = 'https://plesk.example.test:8443/enterprise/control/agent.php';

    private array $server;
    private array $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = [
            'hostname'   => 'plesk.example.test',
            'ip_address' => '192.0.2.3',
            'port'       => 2083,
            'use_ssl'    => 'yes',
            'access_key' => Vault::encrypt('SECRETKEY'),
        ];

        $this->service = ['service_id' => 7, 'domain' => 'shop.example', 'username' => null];
    }

    protected function tearDown(): void
    {
        Client::restore();
        parent::tearDown();
    }

    public function testCreateMakesACustomerThenASubscription(): void
    {
        Client::fake(fn(string $m, string $url, array $o): Response => new Response(200, self::ok()));

        $result = $this->driver()->create($this->service, $this->context(['first_name' => 'Tom & Jerry']));

        self::assertTrue($result['success'], (string) $result['message']);
        self::assertSame('shop7', $result['username']);

        [$customer, $webspace] = Client::sent();
        self::assertSame(self::AGENT, $customer['url']);
        self::assertSame('SECRETKEY', $customer['options']['headers']['KEY']);
        self::assertStringContainsString('<pname>Tom &amp; Jerry', $customer['options']['body'], 'Escaped, never raw.');
        self::assertStringContainsString('<owner-login>shop7</owner-login>', $webspace['options']['body']);
        self::assertStringContainsString('<plan-name>Unlimited</plan-name>', $webspace['options']['body']);
    }

    public function testASubscriptionOwnedBySomebodyElseIsAFailure(): void
    {
        Client::fake(function (string $m, string $url, array $o): Response {
            return match (true) {
                str_contains($o['body'], '<webspace><add>') => new Response(200, self::error(1007, 'Domain already exists')),
                str_contains($o['body'], '<webspace><get>') => new Response(200, self::ok('<data><gen_info><name>shop.example</name><owner-login>someone</owner-login></gen_info></data>')),
                default                                     => new Response(200, self::ok()),
            };
        });

        self::assertFalse($this->driver()->create($this->service, $this->context())['success']);
    }

    public function testARetryReusesTheCustomerAndItsSubscription(): void
    {
        Client::fake(function (string $m, string $url, array $o): Response {
            return match (true) {
                str_contains($o['body'], '<customer><add>')  => new Response(200, self::error(1007, 'Customer exists')),
                str_contains($o['body'], '<webspace><add>')  => new Response(200, self::error(1007, 'Domain exists')),
                default => new Response(200, self::ok('<data><gen_info><name>shop.example</name><owner-login>shop7</owner-login></gen_info></data>')),
            };
        });

        $result = $this->driver()->create($this->service, $this->context());

        self::assertTrue($result['success'], (string) $result['message']);
        self::assertNull($result['password'], 'The existing customer kept its own password.');
    }

    public function testTerminateKeepsACustomerWithOtherSites(): void
    {
        Client::fake(function (string $m, string $url, array $o): Response {
            if (str_contains($o['body'], '<owner-login>shop7</owner-login></filter>')) {
                return new Response(200, self::ok('<data><gen_info><name>second.example</name></gen_info></data>'));
            }

            return new Response(200, str_contains($o['body'], '<webspace><get>')
                ? self::ok('<data><gen_info><name>shop.example</name><owner-login>shop7</owner-login></gen_info></data>')
                : self::ok());
        });

        $result = $this->driver()->terminate(['username' => 'shop7'] + $this->service, $this->context());

        self::assertTrue($result['success']);
        self::assertStringContainsString('kept', (string) $result['message']);

        foreach (Client::sent() as $sent) {
            self::assertStringNotContainsString('<customer><del>', $sent['options']['body']);
        }
    }

    public function testSignOnUsageAndList(): void
    {
        Client::fake(function (string $m, string $url, array $o): Response {
            return match (true) {
                str_contains($o['body'], 'create_session') => new Response(200, self::ok('<id>abc123</id>')),
                str_contains($o['body'], '<disk_usage/>')  => new Response(200, self::ok('<data><disk_usage><httpdocs>1048576</httpdocs><dbases>2097152</dbases></disk_usage><stat><traffic>5242880</traffic></stat></data>')),
                default => new Response(200, '<packet><webspace><get>'
                    . '<result><status>ok</status><data><gen_info><name>a.example</name><owner-login>a1</owner-login><status>0</status></gen_info></data></result>'
                    . '<result><status>ok</status><data><gen_info><name>b.example</name><owner-login>b2</owner-login><status>16</status></gen_info></data></result>'
                    . '</get></webspace></packet>'),
            };
        });

        $service = ['username' => 'shop7'] + $this->service;

        self::assertSame(
            'https://plesk.example.test:8443/enterprise/rsession_init.php?PHPSESSID=abc123',
            $this->driver()->singleSignOn($service, $this->context())['url']
        );
        self::assertSame(['disk_mb' => 3, 'bandwidth_mb' => 5], $this->driver()->usage($service, $this->context())['metrics']);

        $list = (new Plesk())->accounts($this->server)['accounts'];
        self::assertSame(['a1', 'b2'], array_column($list, 'username'));
        self::assertSame([false, true], array_column($list, 'suspended'));
    }

    public function testBadCredentialsAreReported(): void
    {
        Client::fake(['*' => new Response(200, '<packet><system><status>error</status><errcode>1001</errcode><errtext>Authentication failed</errtext></system></packet>')]);

        $check = (new Plesk())->checkServer($this->server);

        self::assertFalse($check['success']);
        self::assertStringContainsString('Authentication failed', $check['message']);
    }

    private function driver(): Plesk
    {
        return new Plesk(['plan' => 'Unlimited', 'verify_tls' => 'yes', 'mode' => 'live']);
    }

    private function context(array $client = []): array
    {
        return ['server' => $this->server, 'client' => $client + ['email' => 'c@example.test'], 'product' => [], 'options' => []];
    }

    private static function ok(string $inner = ''): string
    {
        return '<packet><x><y><result><status>ok</status>' . $inner . '</result></y></x></packet>';
    }

    private static function error(int $code, string $text): string
    {
        return "<packet><x><y><result><status>error</status><errcode>{$code}</errcode><errtext>{$text}</errtext></result></y></x></packet>";
    }
}
