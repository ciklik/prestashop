<?php

/**
 * @author    Ciklik SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Data;

use PrestaShop\Module\Ciklik\Managers\CiklikFrequency;
use PrestaShop\Module\Ciklik\Managers\CiklikItemFrequency;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CartSubscriptionData
{
    /**
     * @var array
     */
    private $items;

    /**
     * @var bool
     */
    private $hasSubscriptions;

    /**
     * @param array $items
     * @param bool $hasSubscriptions
     */
    private function __construct(array $items, bool $hasSubscriptions)
    {
        $this->items = $items;
        $this->hasSubscriptions = $hasSubscriptions;
    }

    /**
     * Crée une instance de CartSubscriptionData à partir d'un panier
     *
     * @param \Cart $cart
     *
     * @return CartSubscriptionData
     */
    public static function fromCart(\Cart $cart): CartSubscriptionData
    {
        $items = [];
        $hasSubscriptions = false;

        // Récupère tous les produits du panier
        $products = $cart->getProducts();

        foreach ($products as $product) {
            // Récupère la fréquence pour ce produit
            $frequencyData = CiklikItemFrequency::getByCartAndProduct($cart->id, $product['id_product']);

            if ($frequencyData) {
                $hasSubscriptions = true;

                // Récupère les détails de la fréquence
                $frequency = CiklikFrequency::getFrequencyById((int) $frequencyData['frequency_id']);

                $items[] = [
                    'id_product' => (int) $product['id_product'],
                    'id_product_attribute' => (int) $product['id_product_attribute'],
                    'quantity' => (int) $product['quantity'],
                    'name' => $product['name'],
                    'price' => (float) $product['price'],
                    'total_price' => (float) $product['total'],
                    'frequency' => [
                        'id' => (int) $frequencyData['frequency_id'],
                        'name' => $frequency['name'],
                        'interval' => $frequency['interval'],
                        'interval_count' => (int) $frequency['interval_count'],
                        'discount_percent' => (float) $frequency['discount_percent'],
                        'discount_price' => (float) $frequency['discount_price'],
                    ],
                ];
            }
        }

        return new self($items, $hasSubscriptions);
    }

    /**
     * Crée une instance de CartSubscriptionData à partir d'une commande
     *
     * @param \Order $order
     *
     * @return CartSubscriptionData
     */
    public static function fromOrder(\Order $order): CartSubscriptionData
    {
        $items = [];
        $hasSubscriptions = false;

        // Récupère tous les produits de la commande
        $products = $order->getProducts();

        foreach ($products as $product) {
            // Récupère la fréquence pour ce produit
            $frequencyData = CiklikItemFrequency::getByOrderAndProduct($order->id, $product['product_id']);

            if ($frequencyData) {
                $hasSubscriptions = true;

                // Récupère les détails de la fréquence
                $frequency = CiklikFrequency::getFrequencyById((int) $frequencyData['frequency_id']);

                $items[] = [
                    'id_product' => (int) $product['product_id'],
                    'id_product_attribute' => (int) $product['product_attribute_id'],
                    'quantity' => (int) $product['product_quantity'],
                    'name' => $product['product_name'],
                    'price' => (float) $product['product_price'],
                    'total_price' => (float) $product['total_price_tax_incl'],
                    'frequency' => [
                        'id' => (int) $frequencyData['frequency_id'],
                        'name' => $frequency['name'],
                        'interval' => $frequency['interval'],
                        'interval_count' => (int) $frequency['interval_count'],
                        'discount_percent' => (float) $frequency['discount_percent'],
                        'discount_price' => (float) $frequency['discount_price'],
                    ],
                ];
            }
        }

        return new self($items, $hasSubscriptions);
    }

    /**
     * @return array
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * @return bool
     */
    public function hasSubscribableItems(): bool
    {
        return $this->hasSubscriptions;
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        return [
            'items' => $this->items,
            'has_subscriptions' => $this->hasSubscriptions,
        ];
    }
}
