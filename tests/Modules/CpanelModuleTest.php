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
use Modules\Servers\Cpanel\Cpanel;

// Disabled modules are not autoloaded - required directly, as the Stripe test does.
require_once APP_PATH . '/modules/servers/Cpanel/src/Whm.php';
require_once APP_PATH . '/modules/servers/Cpanel/src/Cpanel.php';

/**
 * Phase 54: the cPanel module against a faked WHM - what it sends, how it
 * authenticates, and that every verb is safe to repeat.
 */
final class CpanelModuleTest extends TestCase
{
    private array $server;
    private array $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = [
            'name'       => 'web01',
            'hostname'   => 'whm.example.test',
            'ip_address' => '192.0.2.1',
            'port'       => 2083,
            'use_ssl'    => 'yes',
            'username'   => Vault::encrypt('reseller1'),
            'access_key' => Vault::encrypt('TOKEN123'),
        ];

        $this->service = ['service_id' => 48, 'domain' => 'Example.com', 'username' => null];
    }

    protected function tearDown(): void
    {
        Client::restore();
        parent::tearDown();
    }

    public function testCreateSendsAFormToWhmWithTheToken(): void
    {
        Client::fake(['POST https://whm.example.test:2087/json-api/createacct?api.version=1' => [
            'body' => ['metadata' => ['result' => 1, 'reason' => 'Account Creation Ok']],
        ]]);

        $result = $this->driver()->create($this->service, $this->context());

        self::assertTrue($result['success'], (string) $result['message']);
        self::assertSame('example1c', $result['username'], 'Letters from the domain, then 48 in base 36.');
        self::assertSame(20, strlen((string) $result['password']));

        $sent = Client::sent()[0];
        self::assertSame('whm reseller1:TOKEN123', $sent['options']['headers']['Authorization']);
        parse_str($sent['options']['body'], $form);
        self::assertSame(['username' => 'example1c', 'domain' => 'example.com', 'plan' => 'gold', 'password' => $result['password'], 'contactemail' => 'a@example.test'], $form);
    }

    public function testARetriedCreateFindsItsOwnAccount(): void
    {
        Client::fake([
            '*createacct*'     => ['body' => ['metadata' => ['result' => 0, 'reason' => 'The username “example1c” already exists.']]],
            '*accountsummary*' => ['body' => ['metadata' => ['result' => 1], 'data' => ['acct' => [['domain' => 'example.com']]]]],
        ]);

        $result = $this->driver()->create($this->service, $this->context());

        self::assertTrue($result['success']);
        self::assertSame('example1c', $result['username']);
        self::assertNull($result['password'], 'The panel has the old password; a new one was never set.');
    }

    public function testSomebodyElsesAccountWithTheNameIsAFailure(): void
    {
        Client::fake([
            '*createacct*'     => ['body' => ['metadata' => ['result' => 0, 'reason' => 'already exists']]],
            '*accountsummary*' => ['body' => ['metadata' => ['result' => 1], 'data' => ['acct' => [['domain' => 'other.org']]]]],
        ]);

        self::assertFalse($this->driver()->create($this->service, $this->context())['success']);
    }

    public function testRepeatedVerbsAreSuccesses(): void
    {
        $service = ['username' => 'example1c'] + $this->service;

        Client::fake([
            '*removeacct*'    => ['body' => ['metadata' => ['result' => 0, 'reason' => 'User example1c does not exist.']]],
            '*unsuspendacct*' => ['body' => ['metadata' => ['result' => 0, 'reason' => 'Account is not suspended']]],
        ]);

        self::assertTrue($this->driver()->terminate($service, $this->context())['success']);
        self::assertTrue($this->driver()->unsuspend($service, $this->context())['success']);

        parse_str(Client::sent()[0]['options']['body'], $form);
        self::assertSame('example1c', $form['username'], 'removeacct names the account `username`.');
    }

    public function testTerminateWithNoUsernameCallsNothing(): void
    {
        Client::fake([]);

        self::assertFalse($this->driver()->terminate($this->service, $this->context())['success']);
        self::assertSame([], Client::sent());
    }

    public function testUsageReadsDiskAndBandwidth(): void
    {
        Client::fake([
            '*accountsummary*' => ['body' => ['metadata' => ['result' => 1], 'data' => ['acct' => [['diskused' => '1.5G']]]]],
            '*showbw*'         => ['body' => ['metadata' => ['result' => 1], 'data' => ['acct' => [['totalbytes' => 3145728]]]]],
        ]);

        $usage = $this->driver()->usage(['username' => 'example1c'] + $this->service, $this->context());

        self::assertSame(['disk_mb' => 1536, 'bandwidth_mb' => 3], $usage['metrics']);
    }

    public function testCheckAndListUseTheServerAlone(): void
    {
        Client::fake([
            '*json-api/version?*' => ['body' => ['metadata' => ['result' => 1], 'data' => ['version' => '11.120.0.5']]],
            '*listaccts*' => ['body' => ['metadata' => ['result' => 1], 'data' => ['acct' => [
                ['user' => 'example1c', 'domain' => 'example.com', 'plan' => 'gold', 'suspended' => 1],
            ]]]],
        ]);

        self::assertStringContainsString('11.120.0.5', (new Cpanel())->checkServer($this->server)['message']);
        self::assertSame(
            [['username' => 'example1c', 'domain' => 'example.com', 'package' => 'gold', 'suspended' => true]],
            (new Cpanel())->accounts($this->server)['accounts']
        );
    }

    public function testABadTokenSaysSo(): void
    {
        Client::fake(['*' => new Response(401, 'Access denied')]);

        $check = (new Cpanel())->checkServer($this->server);

        self::assertFalse($check['success']);
        self::assertStringContainsString('API token', $check['message']);
    }

    private function driver(): Cpanel
    {
        return new Cpanel(['package' => 'gold', 'verify_tls' => 'yes', 'mode' => 'live']);
    }

    private function context(): array
    {
        return ['server' => $this->server, 'client' => ['email' => 'a@example.test'], 'product' => [], 'options' => []];
    }
}
