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

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Phase 50: both Stripe modules map refunds and disputes to the product's
 * `reversal` shape, and leave every other event alone.
 *
 * The modules are loaded from the app's modules/ directly: one that is switched
 * off is not autoloaded, and a test must not depend on an operator's toggles.
 */
final class StripeReversalMappingTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        foreach (['Stripe/src/Api.php', 'Stripe/src/Stripe.php', 'StripeCard/src/Api.php', 'StripeCard/src/StripeCard.php'] as $file) {
            require_once APP_PATH . '/modules/gateways/' . $file;
        }
    }

    public static function modules(): array
    {
        return [['Modules\\Gateways\\Stripe\\Stripe'], ['Modules\\Gateways\\StripeCard\\StripeCard']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modules')]
    public function testChargeRefundedIsARefundOfTheRunningTotal(string $class): void
    {
        $result = $this->map($class, 'charge.refunded', ['payment_intent' => 'pi_1', 'amount_refunded' => 4000, 'currency' => 'usd']);

        self::assertSame('refund', $result['reversal']);
        self::assertSame('pi_1', $result['payment_reference']);
        self::assertSame('evt', $result['reference'], 'The event id, so a retry is a duplicate.');
        self::assertSame(0, bccomp('40', (string) $result['amount'], 2));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modules')]
    public function testDisputes(string $class): void
    {
        self::assertSame('dispute_opened', $this->map($class, 'charge.dispute.created', ['payment_intent' => 'pi_1', 'amount' => 500, 'currency' => 'usd'])['reversal']);
        self::assertSame('dispute_lost', $this->map($class, 'charge.dispute.closed', ['payment_intent' => 'pi_1', 'amount' => 500, 'currency' => 'usd', 'status' => 'lost'])['reversal']);
        self::assertSame('dispute_won', $this->map($class, 'charge.dispute.closed', ['payment_intent' => 'pi_1', 'status' => 'won'])['reversal']);
        self::assertNull($this->map($class, 'charge.dispute.closed', ['payment_intent' => 'pi_1', 'status' => 'warning_closed']));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modules')]
    public function testPaymentsAreNotReversals(string $class): void
    {
        self::assertNull($this->map($class, 'checkout.session.completed', []));
        self::assertNull($this->map($class, 'payment_intent.succeeded', []));
    }

    private function map(string $class, string $type, array $object): ?array
    {
        return (new ReflectionMethod($class, 'reversal'))->invoke(null, $type, $object, 'evt');
    }
}
