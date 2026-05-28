<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Gateway;

use PrestaShop\Module\Ciklik\Data\OrderData;
use PrestaShop\Module\Ciklik\Data\OrderValidationData;
use PrestaShop\Module\Ciklik\Helpers\CustomerHelper;
use PrestaShop\Module\Ciklik\Helpers\ThreadHelper;
use PrestaShop\Module\Ciklik\Managers\CiklikCustomer;
use PrestaShop\Module\Ciklik\Managers\CiklikCustomization;
use PrestaShop\Module\Ciklik\Managers\CiklikItemFrequency;
use PrestaShop\Module\Ciklik\Managers\DeliveryModuleManager;

if (!defined('_PS_VERSION_')) {
    exit;
}

class OrderGateway extends AbstractGateway implements EntityGateway
{
    use ThreadHelper;

    public function post()
    {
        $cart = new \Cart((int) \Tools::getValue('prestashop_cart_id'));

        if (!$cart->id) {
            (new Response())->setBody(['error' => 'Cart not found'])->sendNotFound();
        }

        $context = \Context::getContext();
        $context->cart = $cart;

        $orderData = (new \PrestaShop\Module\Ciklik\Api\Order($context->link))->getOne((int) \Tools::getValue('ciklik_order_id'));

        // Garde d'idempotence : la commande existe déjà (retry côté Ciklik)
        if ($cart->orderExists()) {
            $orderId = $this->getOrderIdByCart($cart);
            $this->applyPostCreationSteps($orderId, $cart, $orderData);
            $this->sendOrderResponse($orderId, $cart);
        }

        if (!$orderData instanceof OrderData) {
            (new Response())->setBody(['error' => 'Order has not been retrieved'])->sendBadRequest();
        }

        $orderValidationData = OrderValidationData::create($cart, $orderData);

        // Statut différencié pour les créations d'abonnement
        if (\Tools::getValue('order_type') === 'subscription_creation'
            && \Configuration::get(\Ciklik::CONFIG_ENABLE_CREATION_ORDER_STATE)
            && (int) \Configuration::get(\Ciklik::CONFIG_CREATION_ORDER_STATE) > 0) {
            $orderValidationData->id_order_state = (int) \Configuration::get(\Ciklik::CONFIG_CREATION_ORDER_STATE);
        }

        $startTime = microtime(true);

        try {
            $this->module->validateOrder(
                $orderValidationData->id_cart,
                $orderValidationData->id_order_state,
                $orderValidationData->amount_paid,
                $orderValidationData->payment_method,
                $orderValidationData->message,
                $orderValidationData->extra_vars,
                $orderValidationData->currency_special,
                $orderValidationData->dont_touch_amount,
                $orderValidationData->secure_key
            );

            $orderId = (int) $this->module->currentOrder;
            $this->applyPostCreationSteps($orderId, $cart, $orderData);
            DeliveryModuleManager::updateOrderId($cart->id, $orderId);
        } catch (\Throwable $e) {
            self::logValidateOrderFailure($e, $cart->id);
            self::logSlowValidateOrder(microtime(true) - $startTime, $cart->id);

            // La commande a pu être créée en BDD avant le crash (hook tiers, etc.)
            if ($cart->orderExists()) {
                $orderId = $this->getOrderIdByCart($cart);
                $this->applyPostCreationSteps($orderId, $cart, $orderData);
                $this->sendOrderResponse($orderId, $cart);
            }

            // Commande non créée du tout : détail complet dans les logs, message générique au client
            (new Response())->setBody([
                'error' => 'Order validation failed',
            ])->sendBadRequest();
        }

        self::logSlowValidateOrder(microtime(true) - $startTime, $cart->id);
        $this->sendOrderResponse($orderId, $cart);
    }

    /**
     * Applique les étapes post-création de commande :
     * lien fréquences, lien client Ciklik, thread SAV, groupe client
     *
     * @param int $orderId ID de la commande PS
     * @param \Cart $cart Panier source
     * @param OrderData $orderData Données commande Ciklik
     */
    private function applyPostCreationSteps($orderId, \Cart $cart, OrderData $orderData)
    {
        // Lier les items avec fréquences du panier à la commande
        if (\Configuration::get('CIKLIK_FREQUENCY_MODE')) {
            CiklikItemFrequency::updateOrderIdFromCart($cart->id, $orderId);
        }

        // Lien client Ciklik et PrestaShop
        CiklikCustomer::save((int) $cart->id_customer, $orderData->ciklik_user_uuid);

        $this->addDataToOrder(
            (int) $orderId,
            $this->buildThreadData()
        );

        if (\Tools::getValue('order_type') === 'subscription_creation'
            && \Configuration::get(\Ciklik::CONFIG_ENABLE_CUSTOMER_GROUP_ASSIGNMENT)) {
            CustomerHelper::assignCustomerGroup((int) $cart->id_customer);
        }
    }

    /**
     * Construit les données du thread SAV Ciklik
     *
     * @return array
     */
    private function buildThreadData()
    {
        return [
            'ciklik_order_id' => \Tools::getValue('ciklik_order_id'),
            'order_type' => \Tools::getValue('order_type'),
            'subscription_uuid' => \Tools::getValue('ciklik_subscription_uuid'),
        ];
    }

    /**
     * Envoie la réponse 201 avec les données de la commande
     *
     * @param int $orderId ID de la commande PS
     * @param \Cart $cart Panier source
     */
    private function sendOrderResponse($orderId, \Cart $cart)
    {
        $order = new \Order((int) $orderId);
        $customizationData = CiklikCustomization::getDetailedCustomizationDataFromOrder($order);

        (new Response())->setBody([
            'ps_order_id' => (int) $orderId,
            'ps_customer_id' => (int) $cart->id_customer,
            'ps_id_address_delivery' => (int) $order->id_address_delivery,
            'customization_data' => CiklikCustomization::adaptForApiResponse($customizationData),
        ])->sendCreated();
    }

    /**
     * Récupère l'ID de commande depuis un panier
     *
     * @param \Cart $cart
     *
     * @return int
     */
    private function getOrderIdByCart(\Cart $cart)
    {
        return (int) \Db::getInstance()->getValue(
            'SELECT id_order FROM ' . _DB_PREFIX_ . 'orders WHERE id_cart = ' . (int) $cart->id
        );
    }

    /**
     * Log l'échec de validateOrder avec les informations de contexte
     * (public pour testabilité uniquement)
     *
     * @param \Throwable $e Exception capturée
     * @param int $cartId ID du panier
     */
    public static function logValidateOrderFailure(\Throwable $e, $cartId)
    {
        \PrestaShopLogger::addLog(
            'Ciklik OrderGateway - validateOrder failed: ' . $e->getMessage(),
            3,
            null,
            'Cart',
            (int) $cartId,
            true
        );
    }

    /**
     * Log un warning si validateOrder a pris plus de 10 secondes
     * (public pour testabilité uniquement)
     *
     * @param float $elapsed Temps écoulé en secondes
     * @param int $cartId ID du panier
     * @param float $threshold Seuil en secondes (défaut: 10)
     */
    public static function logSlowValidateOrder($elapsed, $cartId, $threshold = 10.0)
    {
        if ($elapsed > $threshold) {
            \PrestaShopLogger::addLog(
                'Ciklik OrderGateway - validateOrder slow: ' . round($elapsed, 2) . 's for cart ' . $cartId,
                2,
                null,
                'Cart',
                (int) $cartId,
                true
            );
        }
    }
}
