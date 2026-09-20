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
use Modules\Servers\DirectAdmin\DirectAdmin;

require_once APP_PATH . '/modules/servers/DirectAdmin/src/Da.php';
require_once APP_PATH . '/modules/servers/DirectAdmin/src/DirectAdmin.php';

/**
 * Phase 54: the DirectAdmin module against a faked CMD_API - both answer
 * styles, the port it picks, and that every verb is safe to repeat.
 */
final class DirectAdminModuleTest extends TestCase
{
    private array $server;
    private array $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = [
            'hostname'   => 'da.example.test',
            'ip_address' => '192.0.2.2',
            'port'       => 2083,
            'use_ssl'    => 'yes',
            'username'   => Vault::encrypt('reseller'),
            'access_key' => Vault::encrypt('LOGINKEY'),
        ];

        $this->service = ['service_id' => 1000, 'domain' => 'my-shop.net', 'username' => null];
    }

    protected function tearDown(): void
    {
        Client::restore();
        parent::tearDown();
    }

    public function testCreatePostsToDirectAdminsPort(): void
    {
        Client::fake(['POST https://da.example.test:2222/CMD_API_ACCOUNT_USER' => ['body' => 'error=0&text=User+created']]);

        $result = $this->driver()->create($this->service, $this->context());

        self::assertTrue($result['success'], (string) $result['message']);
        self::assertSame('myshoprs', $result['username'], 'Ten characters at most: 1000 is rs in base 36.');

        $sent = Client::sent()[0];
        self::assertSame('Basic ' . base64_encode('reseller:LOGINKEY'), $sent['options']['headers']['Authorization']);
        parse_str($sent['options']['body'], $form);
        self::assertSame('192.0.2.2', $form['ip']);
        self::assertSame($form['passwd'], $form['passwd2']);
        self::assertSame('silver', $form['package']);
    }

    public function testARetriedCreateFindsItsOwnUser(): void
    {
        Client::fake([
            '*CMD_API_ACCOUNT_USER'     => ['body' => ['error' => '1', 'text' => 'Cannot Create Account', 'details' => 'That username already exists on the system']],
            '*CMD_API_SHOW_USER_CONFIG' => ['body' => 'domain=my-shop.net&package=silver'],
        ]);

        $result = $this->driver()->create($this->service, $this->context());

        self::assertTrue($result['success']);
        self::assertNull($result['password']);
    }

    public function testTheLoginPageIsNotASuccess(): void
    {
        Client::fake(['*' => new Response(200, '<html><body>Please log in</body></html>')]);

        $check = (new DirectAdmin())->checkServer($this->server);

        self::assertFalse($check['success']);
        self::assertStringContainsString('credentials', $check['message']);
    }

    public function testSuspendAndDeleteGoThroughSelectUsers(): void
    {
        $service = ['username' => 'myshoprs'] + $this->service;

        Client::fake([
            '*CMD_API_SELECT_USERS' => ['body' => 'error=1&text=Error&details=User+myshoprs+does+not+exist'],
        ]);

        self::assertTrue($this->driver()->terminate($service, $this->context())['success'], 'Already gone is deleted.');
        self::assertFalse($this->driver()->suspend($service, 'Unpaid', $this->context())['success']);

        parse_str(Client::sent()[1]['options']['body'], $form);
        self::assertSame(['select0' => 'myshoprs', 'location' => 'CMD_SELECT_USERS', 'suspend' => 'Suspend', 'dosuspend' => 'yes', 'json' => 'yes'], $form);
    }

    public function testUsageAndListReadEitherAnswerStyle(): void
    {
        Client::fake([
            '*CMD_API_SHOW_USER_USAGE' => ['body' => 'bandwidth=2048.4&quota=300.6'],
            '*CMD_API_SHOW_ALL_USERS'  => ['body' => 'error=1&text=Not+admin'],
            '*CMD_API_SHOW_USERS'      => ['body' => ['myshoprs', 'other']],
        ]);

        $usage = $this->driver()->usage(['username' => 'myshoprs'] + $this->service, $this->context());
        self::assertSame(['disk_mb' => 301, 'bandwidth_mb' => 2048], $usage['metrics']);

        $list = (new DirectAdmin())->accounts($this->server);
        self::assertTrue($list['success'], (string) $list['message']);
        self::assertSame(['myshoprs', 'other'], array_column($list['accounts'], 'username'));
        self::assertNull($list['accounts'][0]['suspended'], 'Unknown, not guessed.');
    }

    private function driver(): DirectAdmin
    {
        return new DirectAdmin(['package' => 'silver', 'verify_tls' => 'yes', 'mode' => 'live']);
    }

    private function context(): array
    {
        return ['server' => $this->server, 'client' => ['email' => 'b@example.test'], 'product' => [], 'options' => []];
    }
}
