<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Helpers;

use Address;
use AddressFormat;
use Carrier;
use Cart;
use Context;
use Hook;
use Product;
use State;
use Tools;

if (!defined('_PS_VERSION_')) {
    exit;
}
/**
 * Class Cart
 */
class CartHelper
{
    public static function getRebillRawSummaryDetails(int $id_lang, bool $refresh = false, Cart $cart): array
    {
        $context = Context::getContext();

        $delivery = new Address((int) $cart->id_address_delivery);
        $invoice = new Address((int) $cart->id_address_invoice);

        // New layout system with personalization fields
        $formatted_addresses = [
            'delivery' => AddressFormat::getFormattedLayoutData($delivery),
            'invoice' => AddressFormat::getFormattedLayoutData($invoice),
        ];

        $base_total_tax_inc = $cart->getOrderTotal(true, Cart::BOTH, null, $cart->id_carrier);
        $base_total_tax_exc = $cart->getOrderTotal(false, Cart::BOTH, null, $cart->id_carrier);

        $total_tax = $base_total_tax_inc - $base_total_tax_exc;

        if ($total_tax < 0) {
            $total_tax = 0;
        }

        $products = $cart->getProducts($refresh);

        foreach ($products as $key => &$product) {
            $product['price_without_quantity_discount'] = Product::getPriceStatic(
                $product['id_product'],
                !Product::getTaxCalculationMethod(),
                $product['id_product_attribute'],
                6,
                null,
                false,
                false
            );

            if ($product['reduction_type'] == 'amount') {
                $reduction = (!Product::getTaxCalculationMethod() ? (float) $product['price_wt'] : (float) $product['price']) - (float) $product['price_without_quantity_discount'];
                $product['reduction_formatted'] = Tools::getContextLocale($context)->formatPrice($reduction, $context->currency->iso_code);
            }
        }

        $total_shipping = $cart->getTotalShippingCost(
            $cart->delivery_option
        );

        $summary = [
            'delivery' => $delivery,
            'delivery_state' => State::getNameById($delivery->id_state),
            'invoice' => $invoice,
            'invoice_state' => State::getNameById($invoice->id_state),
            'formattedAddresses' => $formatted_addresses,
            'products' => array_values($products),
            'discounts' => array_values($cart->getCartRules()),
            'is_virtual_cart' => (int) $cart->isVirtualCart(),
            'total_discounts' => $cart->getOrderTotal(true, Cart::ONLY_DISCOUNTS, null, $cart->id_carrier),
            'total_discounts_tax_exc' => $cart->getOrderTotal(false, Cart::ONLY_DISCOUNTS, null, $cart->id_carrier),
            'total_wrapping' => $cart->getOrderTotal(true, Cart::ONLY_WRAPPING, null, $cart->id_carrier),
            'total_wrapping_tax_exc' => $cart->getOrderTotal(false, Cart::ONLY_WRAPPING, null, $cart->id_carrier),
            'total_shipping' => $total_shipping,
            'total_shipping_tax_exc' => $cart->getTotalShippingCost($cart->delivery_option, false),
            'total_products_wt' => $cart->getOrderTotal(true, Cart::ONLY_PRODUCTS, null, $cart->id_carrier),
            'total_products' => $cart->getOrderTotal(false, Cart::ONLY_PRODUCTS, null, $cart->id_carrier),
            'total_price' => $base_total_tax_inc,
            'total_tax' => $total_tax,
            'total_price_without_tax' => $base_total_tax_exc,
            'is_multi_address_delivery' => false,
            'free_ship' => !$total_shipping,
            'carrier' => new Carrier($cart->id_carrier, $id_lang),
        ];

        // An array [module_name => module_output] will be returned
        $hook = Hook::exec('actionCartSummary', $summary, null, true);
        if (is_array($hook)) {
            $summary = array_merge($summary, (array) array_shift($hook));
        }

        return $summary;
    }
}
