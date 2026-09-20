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

namespace LBM\Tests\Billing;

use Laika\Service\Uid;
use LBM\Action\GatewayCallback;
use LBM\Action\Invoice;
use LBM\Action\Refund;
use LBM\Action\Transaction;
use LBM\Model\PaymentGatewayModel;
use LBM\Tests\TestCase;

/**
 * Phase 50: refunds and disputes made at the processor reach the ledger, once.
 */
final class GatewayReversalTest extends TestCase
{
    private array $gateway;
    private int $invoiceId;
    private int $paymentId;

    protected function setUp(): void
    {
        parent::setUp();

        $clientId = $this->client();

        $gateways = new PaymentGatewayModel();
        $gateways->insert([
            'uid' => Uid::make(), 'gateway_name' => 'lbmtest', 'gateway_slug' => 'lbmtest',
            'display_name' => 'Test Gateway', 'module_class' => 'X',
        ]);
        $this->gateway = $gateways->where(['gateway_slug' => 'lbmtest'])->first();

        $this->invoiceId = $this->invoice($clientId, '100');
        $this->paymentId = (new Transaction())->pay([
            'client_relid'    =>  $clientId,
            'invoice_relid'   =>  $this->invoiceId,
            'currency_relid'  =>  $this->currencyId(),
            'gateway_relid'   =>  (int) $this->gateway[$gateways->id],
            'transaction_ref' =>  'pi_lbmtest',
            'amount'          =>  '100',
            'description'     =>  'test payment',
        ]);
    }

    public function testADashboardRefundIsRecorded(): void
    {
        self::assertSame('applied', $this->send('evt_1', 'refund', '40')['outcome']);
        self::assertMoney('40', $this->refunded());
    }

    public function testAReplayedEventIsADuplicate(): void
    {
        $this->send('evt_1', 'refund', '40');

        self::assertSame('duplicate', $this->send('evt_1', 'refund', '40')['outcome']);
        self::assertMoney('40', $this->refunded());
    }

    public function testARefundMadeHereIsNotCountedTwice(): void
    {
        (new Refund())->byHand($this->paymentId, '10', 'made here');

        // Stripe's running total now includes that 10.
        self::assertSame('ignored', $this->send('evt_1', 'refund', '10')['outcome']);
        self::assertMoney('10', $this->refunded());
    }

    public function testOnlyTheGrowthOfTheRunningTotalIsWritten(): void
    {
        $this->send('evt_1', 'refund', '40');
        $this->send('evt_2', 'refund', '70');

        self::assertMoney('70', $this->refunded());
    }

    public function testAnOpenedDisputeChangesNothing(): void
    {
        self::assertSame('ignored', $this->send('evt_1', 'dispute_opened', '30')['outcome']);
        self::assertMoney('0', $this->refunded());
    }

    public function testALostDisputeIsAChargebackAndSettlesTheRefund(): void
    {
        $this->send('evt_1', 'refund', '70');

        self::assertSame('applied', $this->send('evt_2', 'dispute_lost', '30')['outcome']);
        self::assertMoney('100', $this->refunded());
        self::assertSame(
            (new Invoice())->statusId('refunded'),
            (int) (new Invoice())->find($this->invoiceId)['status_relid']
        );
    }

    public function testMoreThanTheInvoiceHoldsIsNotWritten(): void
    {
        $this->send('evt_1', 'refund', '150');

        self::assertMoney('100', $this->refunded());
    }

    public function testAPaymentThisInstallNeverSawIsIgnored(): void
    {
        self::assertSame('ignored', $this->send('evt_1', 'refund', '5', 'pi_unknown')['outcome']);
    }

    private function send(string $event, string $kind, string $amount, string $payment = 'pi_lbmtest'): array
    {
        return (new GatewayCallback())->receive($this->gateway, [
            'verified'          =>  true,
            'event'             =>  'test',
            'reference'         =>  $event,
            'reversal'          =>  $kind,
            'payment_reference' =>  $payment,
            'amount'            =>  $amount,
            'success'           =>  true,
        ]);
    }

    private function refunded(): string
    {
        return (new Refund())->recordedFor((new Transaction())->find($this->paymentId));
    }
}
