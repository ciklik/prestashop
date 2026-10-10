<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Data;

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
        $message,
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

    /**
     * État de création d'abonnement choisi en back-office, s'il est activé. Jamais à la place de l'état
     * « en attente de paiement » : la commande d'un paiement non encaissé ne doit pas paraître payée.
     *
     * @return OrderValidationData
     */
    public function applyCreationState()
    {
        if (OrderData::awaitingPaymentState() > 0 && $this->id_order_state === OrderData::awaitingPaymentState()) {
            return $this;
        }

        // Erreur de paiement (paiement en attente dont le panier a changé, commande non terminée) : jamais masquée.
        if ($this->id_order_state === (int) \Configuration::get('PS_OS_ERROR')) {
            return $this;
        }

        if (\Configuration::get(\Ciklik::CONFIG_ENABLE_CREATION_ORDER_STATE)
            && (int) \Configuration::get(\Ciklik::CONFIG_CREATION_ORDER_STATE) > 0) {
            $this->id_order_state = (int) \Configuration::get(\Ciklik::CONFIG_CREATION_ORDER_STATE);
        }

        return $this;
    }

    public static function create(\Cart $cart, OrderData $orderData): OrderValidationData
    {
        $state = $orderData->getOrderState();

        // Paiement en attente : PrestaShop ne compare pas le montant au total du panier pour un état non validé.
        // Un panier modifié après le paiement donne une commande en erreur de paiement, jamais « en attente ».
        if ($orderData->isPaymentPending() && method_exists($cart, 'getOrderTotal')
            && abs((float) $cart->getOrderTotal(true, \Cart::BOTH) - (float) $orderData->total_paid) >= 0.01) {
            $state = (int) \Configuration::get('PS_OS_ERROR');
        }

        return new self(
            $cart->id,
            $state,
            $orderData->total_paid,
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
