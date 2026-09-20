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

use LBM\Action\Transaction;
use LBM\Model\ClientModel;
use LBM\Model\InvoiceModel;
use LBM\Tests\TestCase;

/**
 * Phase 50: money paid over what an invoice owes becomes account credit.
 */
final class OverpaymentTest extends TestCase
{
    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clientId = $this->client();
    }

    public function testExcessBecomesCredit(): void
    {
        $invoice = $this->invoice($this->clientId, '100');

        $this->pay($invoice, '120');

        self::assertMoney('100', $this->paid($invoice));
        self::assertMoney('20', $this->credit());
    }

    public function testPartialPaymentsOnlyCreditTheExcess(): void
    {
        $invoice = $this->invoice($this->clientId, '100');

        $this->pay($invoice, '60');
        $this->pay($invoice, '30');
        self::assertMoney('90', $this->paid($invoice));
        self::assertMoney('0', $this->credit());

        $this->pay($invoice, '30');
        self::assertMoney('100', $this->paid($invoice));
        self::assertMoney('20', $this->credit());
    }

    public function testPaymentOnASettledInvoiceIsAllCredit(): void
    {
        $invoice = $this->invoice($this->clientId, '100');
        $this->pay($invoice, '100');

        $this->pay($invoice, '50');

        self::assertMoney('100', $this->paid($invoice));
        self::assertMoney('50', $this->credit());
    }

    public function testEachOverpaymentHasALedgerRowNamingTheInvoice(): void
    {
        $invoice = $this->invoice($this->clientId, '100');
        $this->pay($invoice, '130');

        $rows = (new Transaction())->all(['client_relid' => $this->clientId, 'type' => Transaction::CREDIT]);

        self::assertCount(1, $rows);
        self::assertMoney('30', $rows[0]['amount']);
        self::assertStringStartsWith('Overpayment on invoice ', (string) $rows[0]['description']);
    }

    private function pay(int $invoiceId, string $amount): void
    {
        (new Transaction())->pay([
            'client_relid'   =>  $this->clientId,
            'invoice_relid'  =>  $invoiceId,
            'currency_relid' =>  $this->currencyId(),
            'amount'         =>  $amount,
            'description'    =>  'test payment',
        ]);
    }

    private function paid(int $invoiceId): string
    {
        return (string) (new InvoiceModel())->where(['invoice_id' => $invoiceId])->first()['amount_paid'];
    }

    private function credit(): string
    {
        return (string) (new ClientModel())->where(['cid' => $this->clientId])->first()['credit_balance'];
    }
}
