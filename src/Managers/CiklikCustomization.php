<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Managers;

use Order;
use Cart;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CiklikCustomization
{
    public static function getCustomizationDataFromCart(Cart $cart)
    {
        $customizationData = [];

        foreach ($cart->getProducts() as $product) {
            $customizationData[] = [
                'id_product' => $product['id_product'],
                'id_product_attribute' => $product['id_product_attribute'],
                'customization_data' => isset($product['customization_data']) ? $product['customization_data'] : null,
            ];
        }

        return $customizationData;
    }

    public static function getCustomizationDataFromOrder(Order $order)
    {
        $customizationData = [];

        foreach ($order->getProducts() as $product) {
            $customizationData[] = [
                'id_product' => $product['id_product'], 
                'id_product_attribute' => $product['id_product_attribute'] ?? null,
                'customization_data' => isset($product['customization_data']) ? $product['customization_data'] : null,
            ];
        }

        return $customizationData;
    }
}