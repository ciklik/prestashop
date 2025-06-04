<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Managers;

use Db;
use DbQuery;
use PrestaShop\Module\Ciklik\Data\CiklikItemFrequencyData;
use Cart;
use Order;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CiklikItemFrequency
{
    /**
     * Sauvegarde une fréquence pour un produit dans le panier
     * 
     * @param int $cartId ID du panier
     * @param int $frequencyId ID de la fréquence
     * @param int $productId ID du produit
     * @param int $productAttributeId ID de la combinaison produit
     * @param int|null $customerId ID du client (optionnel)
     * @param int|null $guestId ID du visiteur (optionnel)
     * @return bool
     */
    public static function save(
        int $cartId,
        int $frequencyId,
        int $productId,
        int $productAttributeId,
        ?int $customerId = null,
        ?int $guestId = null
    ): bool {
        // Supprime d'abord les anciennes entrées pour ce produit dans le panier
        self::deleteByCartAndProduct($cartId, $productId);

        // Prépare les données pour l'insertion
        $data = [
            'cart_id' => (int)$cartId,
            'frequency_id' => (int)$frequencyId,
            'product_id' => (int)$productId,
            'id_product_attribute' => (int)$productAttributeId
        ];

        // Ajoute l'ID client ou guest selon le cas
        if ($customerId) {
            $data['customer_id'] = (int)$customerId;
        } elseif ($guestId) {
            $data['guest_id'] = (int)$guestId;
        }

        return Db::getInstance()->insert('ciklik_items_frequency', $data);
    }

    /**
     * Récupère la fréquence d'un produit dans le panier
     * 
     * @param int $cartId ID du panier
     * @param int $productId ID du produit
     * @return array|null
     */
    public static function getByCartAndProduct(int $cartId, int $productId)
    {
        $query = new DbQuery();
        $query->select('*');
        $query->from('ciklik_items_frequency');
        $query->where('cart_id = ' . (int)$cartId);
        $query->where('product_id = ' . (int)$productId);

        return Db::getInstance()->getRow($query);
    }

    /**
     * Supprime les fréquences d'un produit dans le panier
     * 
     * @param int $cartId ID du panier
     * @param int $productId ID du produit
     * @return bool
     */
    public static function deleteByCartAndProduct(int $cartId, int $productId): bool
    {
        return Db::getInstance()->delete(
            'ciklik_items_frequency',
            'cart_id = ' . (int)$cartId . ' AND product_id = ' . (int)$productId
        );
    }

    /**
     * Supprime toutes les fréquences d'un panier
     * 
     * @param int $cartId ID du panier
     * @return bool
     */
    public static function deleteByCart(int $cartId): bool
    {
        return Db::getInstance()->delete(
            'ciklik_items_frequency',
            'cart_id = ' . (int)$cartId
        );
    }

    /**
     * Récupère toutes les fréquences d'un panier
     * 
     * @param int $cartId ID du panier
     * @return array
     */
    public static function getByCart(int $cartId): array
    {
        $query = new DbQuery();
        $query->select('*');
        $query->from('ciklik_items_frequency');
        $query->where('cart_id = ' . (int)$cartId);

        return Db::getInstance()->executeS($query);
    }

    /**
     * Récupère une fréquence par produit et combinaison
     * 
     * @param int $productId ID du produit
     * @param int|null $productAttributeId ID de la combinaison (optionnel)
     * @return array|null Les données de fréquence ou null si non trouvé
     */
    public static function getByProduct(int $productId, ?int $productAttributeId = null): ?array
    {
        $query = new DbQuery();
        $query->select('*');
        $query->from('ciklik_items_frequency');
        $query->where('product_id = ' . (int)$productId);
        
        if ($productAttributeId !== null) {
            $query->where('id_product_attribute = ' . (int)$productAttributeId);
        } else {
            $query->where('id_product_attribute IS NULL');
        }

        return Db::getInstance()->getRow($query);
    }

    /**
     * Met à jour le customer_id pour un guest_id
     * 
     * @param int $guestId ID du visiteur
     * @param int $customerId ID du client
     * @param int $cartId ID du panier
     * @return bool True si l'opération a réussi
     */
    public static function updateCustomerFromGuest(int $guestId, int $customerId, int $cartId): bool
    {
        return Db::getInstance()->update(
            'ciklik_items_frequency',
            [
                'customer_id' => (int)$customerId,
                'guest_id' => null
            ],
            'guest_id = ' . (int)$guestId . ' AND cart_id = ' . (int)$cartId
        );
    }

    /**
     * Récupère toutes les fréquences pour un client
     * 
     * @param int $customerId ID du client
     * @return array Les fréquences trouvées
     */
    public static function getByCustomer(int $customerId): array
    {
        $query = new DbQuery();
        $query->select('*');
        $query->from('ciklik_items_frequency');
        $query->where('customer_id = ' . (int)$customerId);

        return Db::getInstance()->executeS($query);
    }

    /**
     * Met à jour l'id_order pour toutes les entrées d'un panier donné
     *
     * @param int $cartId ID du panier
     * @param int $orderId ID de la commande
     * @return bool True si l'opération a réussi
     */
    public static function updateOrderIdFromCart(int $cartId, int $orderId): bool
    {
        return Db::getInstance()->update(
            'ciklik_items_frequency',
            [
                'order_id' => (int)$orderId
            ],
            'cart_id = ' . (int)$cartId
        );
    }

    /**
     * Récupère la fréquence d'un produit dans une commande
     *
     * @param int $orderId
     * @param int $productId
     * @return array|false
     */
    public static function getByOrderAndProduct(int $orderId, int $productId)
    {
        $query = new DbQuery();
        $query->select('*');
        $query->from('ciklik_items_frequency');
        $query->where('order_id = ' . (int)$orderId);
        $query->where('product_id = ' . (int)$productId);

        return Db::getInstance()->getRow($query);
    }
} 