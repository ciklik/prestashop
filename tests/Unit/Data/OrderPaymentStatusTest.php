<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Data;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Data\OrderData;
use PrestaShop\Module\Ciklik\Data\OrderValidationData;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Paiement en attente (PayPal eCheck) : état « en attente de paiement » à la création, puis passage à
 * l'état payé ou annulé quand Ciklik confirme le paiement.
 */
class OrderPaymentStatusTest extends TestCase
{
    private const PAID_STATE = 2;
    private const AWAITING_STATE = 31;
    private const ERROR_STATE = 8;
    private const CREATION_STATE = 15;

    protected function setUp(): void
    {
        \Configuration::resetMocks();
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

    public function testAnOldApiWithoutPaymentStatusKeepsThePaidState()
    {
        $this->assertSame(self::PAID_STATE, $this->order()->getOrderState());
    }

    public function testAPendingPaymentIsCreatedAwaitingPayment()
    {
        $order = $this->order(['payment_status' => 'pending', 'payment_class_key' => 'PaypalVault']);

        $this->assertTrue($order->isPaymentPending());
        $this->assertSame(self::AWAITING_STATE, $order->getOrderState());
    }

    public function testAPendingPaymentWithoutTheAwaitingStateKeepsThePreviousBehaviour()
    {
        \Configuration::updateValue(\Ciklik::CONFIG_OS_AWAITING_PAYMENT, '0');

        $this->assertSame(self::PAID_STATE, $this->order(['payment_status' => 'pending'])->getOrderState());
    }

    public function testAPaidOrderGetsThePaidState()
    {
        $order = $this->order(['payment_status' => 'paid', 'paid_transaction_id' => 'CAP-1', 'paid_class_key' => 'PaypalVault']);

        $this->assertFalse($order->isPaymentPending());
        $this->assertSame(self::PAID_STATE, $order->getOrderState());
    }

    public function testAnUnfinishedOrderStaysInError()
    {
        $this->assertSame(self::ERROR_STATE, $this->order(['status' => 'pending', 'payment_status' => 'failed'])->getOrderState());
    }

    public function testThePspNameOfAPendingPaymentComesFromItsGateway()
    {
        $this->assertSame('Paypal (Ciklik)', $this->order(['payment_status' => 'pending', 'payment_class_key' => 'PaypalVault'])->getPspName());
    }

    public function testTheCreationStateNeverReplacesTheAwaitingState()
    {
        \Configuration::updateValue(\Ciklik::CONFIG_ENABLE_CREATION_ORDER_STATE, '1');
        \Configuration::updateValue(\Ciklik::CONFIG_CREATION_ORDER_STATE, (string) self::CREATION_STATE);
        $cart = new \Cart(42);
        $cart->id_currency = 1;
        $cart->secure_key = 'k';

        $pending = OrderValidationData::create($cart, $this->order(['payment_status' => 'pending']))->applyCreationState();
        $paid = OrderValidationData::create($cart, $this->order(['payment_status' => 'paid', 'paid_transaction_id' => 'CAP-1']))->applyCreationState();

        $this->assertSame(self::AWAITING_STATE, $pending->id_order_state);
        $this->assertSame(self::CREATION_STATE, $paid->id_order_state);
    }

    public function testAnAwaitingOrderBecomesPaidOnceCiklikConfirmsThePayment()
    {
        $order = $this->order(['payment_status' => 'paid', 'paid_transaction_id' => 'CAP-1', 'paid_class_key' => 'PaypalVault']);

        $this->assertSame(self::PAID_STATE, $order->paymentUpdateTargetState(self::AWAITING_STATE));
    }

    public function testAnAwaitingOrderGoesToPaymentErrorWhenThePaymentFails()
    {
        // Ni annulation ni remise en stock : l'abonnement Ciklik reste actif, le marchand régularise.
        $this->assertSame(self::ERROR_STATE, $this->order(['payment_status' => 'failed'])->paymentUpdateTargetState(self::AWAITING_STATE));
    }

    public function testNothingHappensWithoutThePaymentErrorState()
    {
        \Configuration::updateValue('PS_OS_ERROR', '0');

        $this->assertNull($this->order(['payment_status' => 'failed'])->paymentUpdateTargetState(self::AWAITING_STATE));
    }

    public function testTheCartIdIsReadFromTheApi()
    {
        $this->assertSame(42, $this->order(['prestashop_cart_id' => '42'])->prestashop_cart_id);
        $this->assertNull($this->order()->prestashop_cart_id);
        $this->assertNull($this->order(['prestashop_cart_id' => null])->prestashop_cart_id);
    }

    public function testAnOrderBoundToAnotherCartIsRefused()
    {
        // Anti-rejeu de validation.php : le panier courant doit être celui que Ciklik a lié à la commande.
        $this->assertTrue($this->order()->isForCart(7));
        $this->assertTrue($this->order(['prestashop_cart_id' => 7])->isForCart(7));
        $this->assertTrue($this->order(['prestashop_cart_id' => '7'])->isForCart('7'));
        $this->assertFalse($this->order(['prestashop_cart_id' => 8])->isForCart(7));
    }

    public function testAStillPendingPaymentChangesNothing()
    {
        $this->assertNull($this->order(['payment_status' => 'pending'])->paymentUpdateTargetState(self::AWAITING_STATE));
    }

    public function testAnOrderNoLongerAwaitingIsNeverTouched()
    {
        // Le marchand a déjà changé l'état (expédiée, annulée, payée à la main) : rien n'est réécrit.
        $paid = $this->order(['payment_status' => 'paid', 'paid_transaction_id' => 'CAP-1']);
        $failed = $this->order(['payment_status' => 'failed']);

        $this->assertNull($paid->paymentUpdateTargetState(self::PAID_STATE));
        $this->assertNull($failed->paymentUpdateTargetState(4));
    }

    public function testAPaidStatusWithoutTransactionIsNotTrusted()
    {
        $this->assertNull($this->order(['payment_status' => 'paid'])->paymentUpdateTargetState(self::AWAITING_STATE));
    }

    public function testNothingHappensWithoutTheAwaitingStateConfigured()
    {
        \Configuration::updateValue(\Ciklik::CONFIG_OS_AWAITING_PAYMENT, '0');

        $this->assertNull($this->order(['payment_status' => 'failed'])->paymentUpdateTargetState(0));
    }

    public function testAPendingOrderWhoseCartChangedIsCreatedInPaymentError()
    {
        // Panier modifié après le paiement : PrestaShop ne compare pas le montant pour un état non validé.
        $cart = new class(42) extends \Cart {
            public function getOrderTotal($withTaxes = true, $type = \Cart::BOTH)
            {
                return 59.90;
            }
        };
        $cart->id_currency = 1;
        $cart->secure_key = 'k';

        $data = OrderValidationData::create($cart, $this->order(['payment_status' => 'pending', 'total_paid' => '29,90']));

        $this->assertSame(self::ERROR_STATE, $data->id_order_state);
    }

    public function testAPendingOrderOfTheSameAmountStaysAwaiting()
    {
        $cart = new class(42) extends \Cart {
            public function getOrderTotal($withTaxes = true, $type = \Cart::BOTH)
            {
                return 29.90;
            }
        };
        $cart->id_currency = 1;
        $cart->secure_key = 'k';

        $this->assertSame(self::AWAITING_STATE, OrderValidationData::create($cart, $this->order(['payment_status' => 'pending']))->id_order_state);
    }

    public function testAnOrderWithAPaymentStatusButNoCartBelongsToNoCart()
    {
        $this->assertFalse($this->order(['payment_status' => 'pending'])->isForCart(42));
        $this->assertFalse($this->order(['payment_status' => 'pending', 'prestashop_cart_id' => 0])->isForCart(42));
    }

    public function testAnOldPlatformWithoutCartOrPaymentStatusIsAccepted()
    {
        $this->assertTrue($this->order()->isForCart(42));
    }

    public function testAnOrderOnlyMatchesItsOwnCart()
    {
        $order = $this->order(['payment_status' => 'pending', 'prestashop_cart_id' => 42]);

        $this->assertTrue($order->isForCart(42));
        $this->assertFalse($order->isForCart(43));
    }

    public function testTheCreationStateNeverHidesAPaymentError()
    {
        \Configuration::updateValue(\Ciklik::CONFIG_ENABLE_CREATION_ORDER_STATE, '1');
        \Configuration::updateValue(\Ciklik::CONFIG_CREATION_ORDER_STATE, (string) self::CREATION_STATE);
        $cart = new class(42) extends \Cart {
            public function getOrderTotal($withTaxes = true, $type = \Cart::BOTH)
            {
                return 59.90;
            }
        };
        $cart->id_currency = 1;
        $cart->secure_key = 'k';

        $data = OrderValidationData::create($cart, $this->order(['payment_status' => 'pending', 'total_paid' => '29,90']))->applyCreationState();

        $this->assertSame(self::ERROR_STATE, $data->id_order_state);
    }
}
