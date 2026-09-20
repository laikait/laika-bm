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

namespace LBM\Tests\Services;

use Laika\Service\Uid;
use LBM\Action\ServiceOperation;
use LBM\Model\ClientServiceModel;
use LBM\Model\ProductModel;
use LBM\Model\ProvisioningLogModel;
use LBM\Model\ServerModel;
use LBM\Model\ServiceUsageRecordModel;
use LBM\Module\Api;
use LBM\Module\Contracts\ServerInterface;
use LBM\Service\ClientService;
use LBM\Support\Http\Client;
use LBM\Tests\TestCase;
use Modules\Servers\Example\Example;

// Disabled modules are not autoloaded; the Example ships switched off.
require_once APP_PATH . '/modules/servers/Example/src/Api.php';
require_once APP_PATH . '/modules/servers/Example/src/Example.php';

/**
 * The real Example driver, for whichever product the call is for - the part of
 * driverFor() that needs the module switched on is the only part replaced.
 */
final class ExampleOperation extends ServiceOperation
{
    protected function driverFor(array $server, array $product = []): ?ServerInterface
    {
        $raw = $product['module_config'] ?? [];
        $config = is_array($raw) ? $raw : (array) unserialize((string) $raw, ['allowed_classes' => false]);

        return new Example($config + ['mode' => Api::LIVE]);
    }
}

/**
 * Phase 53: the optional module operations, end to end through the Example
 * module and a faked control panel - what is sent, what is stored, and what is
 * logged.
 */
final class ServiceOperationTest extends TestCase
{
    private array $service;
    private int $bigProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $small = $this->product('small');
        $this->bigProduct = $this->product('big');

        $servers = new ServerModel();
        $servers->insert([
            $servers->uid   =>  Uid::make(),
            'name'          =>  'web01',
            'hostname'      =>  'panel.example.test',
            'ip_address'    =>  '192.0.2.10',
            'ip_addresses'  =>  serialize([]),
            'module_name'   =>  'Example',
            'port'          =>  2087,
            'status_relid'  =>  1,
            'created_at'    =>  date('Y-m-d H:i:s'),
        ]);
        $serverId = (int) $servers->where(['name' => 'web01', 'hostname' => 'panel.example.test'])->first()[$servers->id];

        $services = new ClientServiceModel();
        $uid = Uid::make();
        $services->insert([
            $services->uid          =>  $uid,
            'client_relid'          =>  $this->client(),
            'product_relid'         =>  $small,
            'server_relid'          =>  $serverId,
            'username'              =>  'alice',
            'billing_cycle_relid'   =>  1,
            'currency_relid'        =>  $this->currencyId(),
            'amount'                =>  '10',
            'created_at'            =>  date('Y-m-d H:i:s'),
        ]);
        $this->service = $services->where([$services->uid => $uid])->first();
    }

    protected function tearDown(): void
    {
        Client::restore();
        parent::tearDown();
    }

    public function testCapabilitiesComeFromTheContracts(): void
    {
        self::assertSame(
            ['package' => true, 'password' => true, 'sso' => true, 'usage' => true],
            (new ExampleOperation())->capabilities($this->service)
        );
    }

    public function testPasswordIsStoredOnlyAfterThePanelTookIt(): void
    {
        Client::fake(['PUT https://panel.example.test:2087/api/v1/accounts/alice/password' => ['status' => 500]]);

        $refused = (new ExampleOperation())->changePassword($this->service, 'Tried-It-123');

        self::assertFalse($refused['success']);
        self::assertNull(ClientService::credential($this->fresh()));

        Client::fake(['PUT https://panel.example.test:2087/api/v1/accounts/alice/password' => ['status' => 200]]);

        $done = (new ExampleOperation())->changePassword($this->service, '');

        self::assertTrue($done['success'], $done['message']);

        $sent = Client::sent()[0]['options']['json']['password'];
        self::assertSame(16, strlen($sent), 'A blank password is generated.');
        self::assertSame($sent, ClientService::credential($this->fresh()));
    }

    public function testPackageMovesToTheNewProductsPackage(): void
    {
        Client::fake(['PUT https://panel.example.test:2087/api/v1/accounts/alice/package' => ['status' => 200]]);

        $result = (new ExampleOperation())->changePackage($this->service, $this->bigProduct);

        self::assertTrue($result['success'], $result['message']);
        self::assertSame(['package' => 'big'], Client::sent()[0]['options']['json']);
        self::assertSame($this->bigProduct, (int) $this->fresh()['product_relid']);
        self::assertSame('10.0000', (string) $this->fresh()['amount'], 'The price is not changed here.');
    }

    public function testSingleSignOnRefusesAnAddressThatIsNotWeb(): void
    {
        Client::fake(['POST *' => ['status' => 200, 'body' => ['url' => 'javascript:alert(1)']]]);
        self::assertFalse((new ExampleOperation())->singleSignOn($this->service, 'client')['success']);

        Client::fake(['POST *' => ['status' => 200, 'body' => ['url' => 'https://panel.example.test/sso/abc']]]);
        $result = (new ExampleOperation())->singleSignOn($this->service, 'staff');

        self::assertSame('https://panel.example.test/sso/abc', $result['url']);
        self::assertSame(['as' => 'staff'], Client::sent()[0]['options']['json']);

        // The link is a key to the panel: never in the log.
        $log = (new ProvisioningLogModel())->where(['service_relid' => (int) $this->service['service_id']])->get();
        self::assertStringNotContainsString('sso/abc', serialize($log));
    }

    public function testUsageBecomesRecords(): void
    {
        Client::fake(['GET *' => ['status' => 200, 'body' => ['disk_mb' => 512, 'bandwidth_mb' => 2048]]]);

        $result = (new ExampleOperation())->syncUsage($this->service);

        self::assertTrue($result['success'], $result['message']);
        self::assertSame(['disk_mb' => 512, 'bandwidth_mb' => 2048], $result['metrics']);
        self::assertSame(2, (new ServiceUsageRecordModel())->where(['service_relid' => (int) $this->service['service_id']])->count());
        self::assertSame(512, (new ExampleOperation())->latestUsage((int) $this->service['service_id'])['disk_mb']['quantity']);
    }

    private function product(string $package): int
    {
        $model = new ProductModel();
        $slug = 'test-' . $package . '-' . bin2hex(random_bytes(3));

        $model->insert([
            $model->uid          =>  Uid::make(),
            'product_slug'       =>  $slug,
            'group_relid'        =>  1,
            'product_name'       =>  ucfirst($package),
            'type_relid'         =>  1,
            'status_relid'       =>  1,
            'module_name'        =>  'Example',
            'module_config'      =>  serialize(['package' => $package]),
            'product_created_at' =>  date('Y-m-d H:i:s'),
        ]);

        return (int) $model->where(['product_slug' => $slug])->first()[$model->id];
    }

    private function fresh(): array
    {
        return (new ClientServiceModel())->where([(new ClientServiceModel())->id => (int) $this->service['service_id']])->first();
    }
}
