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

namespace LBM\Tests;

use Laika\Core\Model\OptionModel;
use Laika\Model\Connection;
use Laika\Service\Uid;
use LBM\Action\Invoice;
use LBM\Action\Setting;
use LBM\Model\ClientModel;
use LBM\Model\CurrencyModel;
use PHPUnit\Framework\TestCase as Base;

/**
 * Every LBM test runs inside a transaction that is rolled back afterwards, on
 * the throwaway database tests/bootstrap.php builds - so tests cannot see each
 * other's rows, and nothing a test writes outlives it.
 *
 * The factories write the smallest row the schema accepts, through the models,
 * so a test states only what it is about.
 */
abstract class TestCase extends Base
{
    protected function setUp(): void
    {
        Connection::beginTransaction('default');
    }

    protected function tearDown(): void
    {
        Connection::rollBack('default');

        // The rollback undoes the rows, not what this process remembers of
        // them: option() caches every value for the whole process, and the
        // mailer, Money and Status memoise too. Without this a setting written
        // by one test is still "set" in the next.
        OptionModel::flush();
        (new Setting())->flushCaches();
    }

    /**
     * The Installed Currency
     * @return int Currency ID
     */
    protected function currencyId(): int
    {
        $model = new CurrencyModel();

        return (int) $model->where(['currency_code' => 'USD'])->first()[$model->id];
    }

    /**
     * A Client
     * @param array $columns Anything To Override
     * @return int Client ID
     */
    protected function client(array $columns = []): int
    {
        $email = 'test-' . bin2hex(random_bytes(6)) . '@example.test';
        $model = new ClientModel();

        $model->insert($columns + [
            'cuid'           =>  Uid::make(),
            'first_name'     =>  'Test',
            'last_name'      =>  'Client',
            'email'          =>  $email,
            'currency_relid' =>  $this->currencyId(),
        ]);

        return (int) $model->where(['email' => $columns['email'] ?? $email])->first()[$model->id];
    }

    /**
     * An Invoice With One Untaxed Line
     * @param int $clientId Client ID
     * @param string $amount The Line's Price
     * @param array $line Anything To Override On The Line
     * @return int Invoice ID
     */
    protected function invoice(int $clientId, string $amount = '100', array $line = []): int
    {
        return (new Invoice())->store(
            ['client_relid' => $clientId, 'currency_relid' => $this->currencyId()],
            [$line + ['description' => 'Test line', 'quantity' => '1', 'unit_price' => $amount, 'tax' => '0']]
        );
    }

    /**
     * Assert Two Money Amounts Are Equal, To Four Places
     * @param string $expected
     * @param mixed $actual
     * @param string $message
     */
    protected static function assertMoney(string $expected, mixed $actual, string $message = ''): void
    {
        self::assertSame(0, bccomp($expected, (string) $actual, 4), $message ?: "Expected {$expected}, got {$actual}.");
    }
}
