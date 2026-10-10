<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Gateway;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Data\OrderData;
use PrestaShop\Module\Ciklik\Gateway\PaymentGateway;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Passerelle ciklik/gateway/payments : décision (état cible), retrouvaille de la commande PrestaShop, garde
 * de transaction et corps de réponse. L'enchaînement OrderHistory est vérifié par l'essai réel sur PrestaShop.
 */
class PaymentGatewayTest extends TestCase
{
    private const PAID_STATE = 2;
    private const AWAITING_STATE = 31;
    private const ERROR_STATE = 8;

    protected function setUp(): void
    {
        \Configuration::resetMocks();
        \Db::resetMocks();
        \PrestaShopLogger::resetLogs();
        \Configuration::updateValue(\Ciklik::CONFIG_ORDER_STATE, self::PAID_STATE);
        \Configuration::updateValue(\Ciklik::CONFIG_OS_AWAITING_PAYMENT, self::AWAITING_STATE);
        \Configuration::updateValue('PS_OS_ERROR', self::ERROR_STATE);
    }

    private function order(array $overrides = []): OrderData
    {
        return OrderData::create(array_merge([
            'order_id' => 100,
            'user_uuid' => 'a1b2c3d4-e5f6-4a7b-8c9d-e0f1a2b3c4d5',
            'status' => 'completed',
            'paid_transaction_id' => null,
            'paid_class_key' => null,
            'created_at' => '2026-10-09 10:00:00',
            'total_paid' => '29,90',
        ], $overrides));
    }

    private function paid(): OrderData
    {
        return $this->order(['payment_status' => 'paid', 'paid_transaction_id' => 'CAP-1', 'paid_class_key' => 'PaypalVault']);
    }

    public function testAPaidPaymentPlansPaymentThenThePaidState()
    {
        $plan = PaymentGateway::plan($this->paid(), self::AWAITING_STATE);

        $this->assertSame(self::PAID_STATE, $plan['target']);
        $this->assertSame(PaymentGateway::KIND_PAID, $plan['kind']);
    }

    public function testAFailedPaymentPlansThePaymentErrorStateWithoutPayment()
    {
        $plan = PaymentGateway::plan($this->order(['payment_status' => 'failed']), self::AWAITING_STATE);

        $this->assertSame(self::ERROR_STATE, $plan['target']);
        $this->assertSame(PaymentGateway::KIND_ERROR, $plan['kind']);
    }

    public function testNothingIsPlannedForAPendingPaymentOrAnOrderNoLongerAwaiting()
    {
        $this->assertSame(['target' => null, 'kind' => null], PaymentGateway::plan($this->order(['payment_status' => 'pending']), self::AWAITING_STATE));
        $this->assertSame(['target' => null, 'kind' => null], PaymentGateway::plan($this->paid(), self::PAID_STATE));
        $this->assertSame(['target' => null, 'kind' => null], PaymentGateway::plan($this->order(['payment_status' => 'failed']), 4));
    }

    public function testThePrestashopOrderComesFromCiklikWhenLinked()
    {
        \Db::setMockGetValue('999');

        $this->assertSame(17, PaymentGateway::findOrderId($this->order(['prestashop_order_id' => 17, 'prestashop_cart_id' => 5])));
        $this->assertSame([], \Db::$queryLog);
    }

    public function testThePrestashopOrderIsFoundByCartOtherwise()
    {
        \Db::setMockGetValue('23');

        $this->assertSame(23, PaymentGateway::findOrderId($this->order(['prestashop_cart_id' => 5])));
        $this->assertCount(1, \Db::$queryLog);
        $this->assertStringContainsString('id_cart = 5', \Db::$queryLog[0]);
    }

    public function testAnUnknownOrderIsReportedAsZero()
    {
        \Db::setMockGetValue(false);

        $this->assertSame(0, PaymentGateway::findOrderId($this->order(['prestashop_cart_id' => 5])));
        $this->assertSame(0, PaymentGateway::findOrderId($this->order()));
    }

    public function testAKnownTransactionIsNotRecordedTwice()
    {
        \Db::setMockGetValue('1');

        $this->assertTrue(PaymentGateway::isPaymentRecorded('ABCDEFGHI', 'CAP-1'));
        $this->assertStringContainsString('order_reference = "ABCDEFGHI"', \Db::$queryLog[0]);
        $this->assertStringContainsString('transaction_id = "CAP-1"', \Db::$queryLog[0]);
    }

    public function testAnUnknownTransactionIsRecorded()
    {
        \Db::setMockGetValue('0');

        $this->assertFalse(PaymentGateway::isPaymentRecorded('ABCDEFGHI', 'CAP-1'));
    }

    public function testTheReferenceAndTransactionAreEscapedInTheQuery()
    {
        \Db::setMockGetValue('0');

        PaymentGateway::isPaymentRecorded('AB"C', 'CAP"-1');

        $this->assertStringNotContainsString('AB"C', \Db::$queryLog[0]);
        $this->assertStringNotContainsString('CAP"-1', \Db::$queryLog[0]);
    }

    public function testAnEmptyTransactionIsNeverConsideredRecorded()
    {
        \Db::setMockGetValue('1');

        $this->assertFalse(PaymentGateway::isPaymentRecorded('ABCDEFGHI', ''));
        $this->assertSame([], \Db::$queryLog);
    }

    public function testTheResponseBodies()
    {
        $this->assertSame(['ps_order_id' => 17, 'updated' => true, 'state' => 2], PaymentGateway::responseBody(17, true, 2));
        $this->assertSame(['ps_order_id' => 17, 'updated' => false, 'state' => 31], PaymentGateway::responseBody(17, false, 31));
    }

    public function testAFailureIsLoggedWithoutPersonalData()
    {
        PaymentGateway::logFailure(new \RuntimeException('hook en erreur'), 100);

        $this->assertCount(1, \PrestaShopLogger::$logs);
        $this->assertSame(3, \PrestaShopLogger::$logs[0]['severity']);
        $this->assertStringContainsString('Ciklik PaymentGateway', \PrestaShopLogger::$logs[0]['message']);
        $this->assertStringContainsString('100', \PrestaShopLogger::$logs[0]['message']);
        $this->assertStringContainsString('RuntimeException', \PrestaShopLogger::$logs[0]['message']);
    }

    public function testTheAmountMustMatchToTheCent()
    {
        $this->assertTrue(PaymentGateway::amountMatches('29.90', 29.9));
        $this->assertTrue(PaymentGateway::amountMatches('29.90', '29.904'));
        $this->assertFalse(PaymentGateway::amountMatches('29.90', '59.90'));
        $this->assertFalse(PaymentGateway::amountMatches('29.90', '29.88'));
    }

    public function testAnUnchangedResponseCarriesItsReason()
    {
        $this->assertSame(
            ['ps_order_id' => 7, 'updated' => false, 'state' => 31, 'reason' => 'amount_mismatch'],
            PaymentGateway::responseBody(7, false, 31, PaymentGateway::REASON_AMOUNT_MISMATCH)
        );
        $this->assertArrayNotHasKey('reason', PaymentGateway::responseBody(7, true, 2));
    }

    public function testTheLockIsPerOrder()
    {
        $this->assertSame('ciklik_payment_12', PaymentGateway::lockName(12));
        $this->assertNotSame(PaymentGateway::lockName(12), PaymentGateway::lockName(13));
    }
}
