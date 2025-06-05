<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Data;

use Cart;
use PrestaShop\Module\Ciklik\Helpers\CartHelper;

if (!defined('_PS_VERSION_')) {
    exit;
}

class OrderValidationData
{
    /**
     * @var int
     */
    public $id_cart;
    /**
     * @var int
     */
    public $id_order_state;
    /**
     * @var float
     */
    public $amount_paid;
    /**
     * @var string
     */
    public $payment_method;
    public $message;
    /**
     * @var array
     */
    public $extra_vars;
    /**
     * @var int
     */
    public $currency_special;
    /**
     * @var bool
     */
    public $dont_touch_amount;
    /**
     * @var string
     */
    public $secure_key;

    private function __construct(int $id_cart,
        int $id_order_state,
        float $amount_paid,
        string $payment_method,
        ?string $message,
        array $extra_vars,
        int $currency_special,
        bool $dont_touch_amount,
        string $secure_key)
    {
        $this->id_cart = $id_cart;
        $this->id_order_state = $id_order_state;
        $this->amount_paid = $amount_paid;
        $this->payment_method = $payment_method;
        $this->message = $message;
        $this->extra_vars = $extra_vars;
        $this->currency_special = $currency_special;
        $this->dont_touch_amount = $dont_touch_amount;
        $this->secure_key = $secure_key;
    }

    public static function create(Cart $cart, OrderData $orderData): OrderValidationData
    {
        return new self(
            $cart->id,
            $orderData->getOrderState(),
            $cart->getOrderTotal(CartHelper::shouldPaidWithTax($cart), Cart::BOTH),
            $orderData->getPspName(),
            null,
            [
                'transaction_id' => $orderData->paid_transaction_id,
            ],
            $cart->id_currency,
            false,
            $cart->secure_key
        );
    }
}
