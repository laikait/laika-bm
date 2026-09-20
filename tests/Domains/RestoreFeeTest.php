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

namespace LBM\Tests\Domains;

use Laika\Service\Uid;
use LBM\Action\DomainRenewal;
use LBM\Model\DomainModel;
use LBM\Model\InvoiceItemModel;
use LBM\Model\InvoiceModel;
use LBM\Model\TldModel;
use LBM\Service\Status;
use LBM\Tests\TestCase;
use ReflectionMethod;

/**
 * Phase 50: a domain entering redemption gets the restore fee on its open
 * renewal invoice, once, as a line that is never mistaken for a renewal.
 */
final class RestoreFeeTest extends TestCase
{
    private int $domainId;
    private int $invoiceId;

    protected function setUp(): void
    {
        parent::setUp();

        $clientId = $this->client();
        $currency = $this->currencyId();

        (new TldModel())->insert([
            'uid' => Uid::make(), 'registrar_relid' => 0, 'currency_relid' => $currency, 'tld' => '.lbmtest',
            'register_price' => '10', 'renew_price' => '12', 'transfer_price' => '10', 'restore_price' => '80',
        ]);

        $expiry = date('Y-m-d H:i:s', strtotime('-40 days'));
        $domains = new DomainModel();
        $domains->insert([
            'uid' => Uid::make(), 'domain' => 'restore-me.lbmtest', 'tld' => '.lbmtest', 'client_relid' => $clientId,
            'registrar_relid' => 0, 'currency_relid' => $currency, 'amount' => '12',
            'status_relid' => Status::idOf('domain_statuses', 'expired'),
            'expiry_date' => $expiry, 'next_due_date' => date('Y-m-d H:i:s', strtotime('+325 days')),
        ]);
        $this->domainId = (int) $domains->where(['domain' => 'restore-me.lbmtest'])->first()[$domains->id];

        // The unpaid renewal invoice DomainRenewalJob would have raised.
        $this->invoiceId = $this->invoice($clientId, '12', [
            'description'  =>  'Domain renewal: restore-me.lbmtest (1 year)',
            'domain_relid' =>  $this->domainId,
            'period_start' =>  $expiry,
            'period_end'   =>  date('Y-m-d H:i:s', strtotime('+1 year', strtotime($expiry))),
        ]);
    }

    public function testEnteringRedemptionAddsTheFeeToTheRenewalInvoice(): void
    {
        $swept = (new DomainRenewal())->expire();

        self::assertGreaterThanOrEqual(1, $swept['redemption']);
        self::assertSame(
            (int) Status::idOf('domain_statuses', 'redemption'),
            (int) (new DomainModel())->where(['domain_id' => $this->domainId])->first()['status_relid']
        );
        self::assertCount(1, $this->feeLines());
        self::assertMoney('92', $this->total());
    }

    public function testTheFeeIsAddedOnce(): void
    {
        (new DomainRenewal())->expire();

        $charge = new ReflectionMethod(DomainRenewal::class, 'chargeRestore');
        $again = $charge->invoke(new DomainRenewal(), (new DomainModel())->where(['domain_id' => $this->domainId])->first());

        self::assertFalse($again);
        self::assertCount(1, $this->feeLines());
        self::assertMoney('92', $this->total());
    }

    public function testTheFeeLineIsNotReadAsARenewal(): void
    {
        (new DomainRenewal())->expire();

        $mine = array_filter(
            (new DomainRenewal())->awaiting(),
            fn (array $job): bool => (int) $job['domain']['domain_id'] === $this->domainId
        );

        self::assertSame([], $mine, 'An unpaid invoice must not be awaiting delivery.');
    }

    private function feeLines(): array
    {
        return (new InvoiceItemModel())
            ->where(['invoice_relid' => $this->invoiceId, 'domain_relid' => $this->domainId])
            ->isNull('period_end')
            ->get();
    }

    private function total(): string
    {
        return (string) (new InvoiceModel())->where(['invoice_id' => $this->invoiceId])->first()['total'];
    }
}
