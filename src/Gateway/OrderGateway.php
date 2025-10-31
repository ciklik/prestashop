<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Gateway;

use Cart;
use Context;
use Db;
use Order;
use PrestaShop\Module\Ciklik\Data\OrderData;
use PrestaShop\Module\Ciklik\Data\OrderValidationData;
use PrestaShop\Module\Ciklik\Helpers\CustomerHelper;
use PrestaShop\Module\Ciklik\Helpers\ThreadHelper;
use PrestaShop\Module\Ciklik\Managers\CiklikCustomer;
use Tools;
use Configuration;
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
        $cart = new Cart((int) Tools::getValue('prestashop_cart_id'));

        if (!$cart->id) {
            (new Response())->setBody(['error' => 'Cart not found'])->sendNotFound();
        }

        $context = Context::getContext();
        $context->cart = $cart;

        $orderData = (new \PrestaShop\Module\Ciklik\Api\Order($context->link))->getOne((int) Tools::getValue('ciklik_order_id'));

        if ($cart->orderExists()) {
            $sql = 'SELECT id_order FROM ' . _DB_PREFIX_ . 'orders WHERE id_cart = ' . (int) $cart->id;
            $orderId = Db::getInstance()->getValue($sql);
        
            $this->addDataToOrder(
                (int) $orderId,
                [
                    'ciklik_order_id' => $orderData->ciklik_order_id,
                    'order_type' => Tools::getValue('order_type'),
                    'subscription_uuid' => Tools::getValue('ciklik_subscription_uuid'),
                ]
            );

            $order = new Order((int) $orderId);

            if (Tools::getValue('order_type') === 'subscription_creation' && Configuration::get(\Ciklik::CONFIG_ENABLE_CUSTOMER_GROUP_ASSIGNMENT)) {
                CustomerHelper::assignCustomerGroup((int) $cart->id_customer);
            }
            
            // Récupérer les customizations détaillées de la commande existante
            $customizationData = CiklikCustomization::getDetailedCustomizationDataFromOrder($order);
            
            (new Response())->setBody([
                'ps_order_id' => (int) $orderId,
                'ps_customer_id' => (int) $cart->id_customer,
                'ps_id_address_delivery' => (int) $order->id_address_delivery,
                'customization_data' => CiklikCustomization::adaptForApiResponse($customizationData)
            ])->sendCreated();
        }

        if (!$orderData instanceof OrderData) {
            (new Response())->setBody(['error' => 'Order has not been retrieved'])->sendBadRequest();
        }

        $orderValidationData = OrderValidationData::create($cart, $orderData);

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

        // Lier les items avec fréquences du panier à la commande
        if(Configuration::get('CIKLIK_FREQUENCY_MODE')){ 
            CiklikItemFrequency::updateOrderIdFromCart($cart->id, $this->module->currentOrder);
        }

        //Lien client Ciklik et Prestashop
        CiklikCustomer::save((int) $cart->id_customer, $orderData->ciklik_user_uuid);

        $this->addDataToOrder(
            (int) $this->module->currentOrder,
            [
                'ciklik_order_id' => $orderData->ciklik_order_id,
                'order_type' => Tools::getValue('order_type'),
                'subscription_uuid' => Tools::getValue('ciklik_subscription_uuid'),
            ]
        );

        $order = new Order((int) $this->module->currentOrder);

        if (Tools::getValue('order_type') === 'subscription_creation' && Configuration::get(\Ciklik::CONFIG_ENABLE_CUSTOMER_GROUP_ASSIGNMENT)) {
            CustomerHelper::assignCustomerGroup((int) $cart->id_customer);
        }

        // Récupérer les customizations détaillées de la nouvelle commande
        $customizationData = CiklikCustomization::getDetailedCustomizationDataFromOrder($order);

        DeliveryModuleManager::updateOrderId($cart->id, $this->module->currentOrder);

        (new Response())->setBody([
            'ps_order_id' => (int) $this->module->currentOrder,
            'ps_customer_id' => (int) $cart->id_customer,
            'ps_id_address_delivery' => (int) $order->id_address_delivery,
            'customization_data' => CiklikCustomization::adaptForApiResponse($customizationData),
        ])->sendCreated();
    }
}
