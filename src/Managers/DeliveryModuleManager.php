<?php
/**
 * Manager pour gérer les actions spécifiques des modules de livraison lors de l'ajout au panier (rebill).
 * Compatible PrestaShop 1.7.7 à 8.2
 */

namespace PrestaShop\Module\Ciklik\Managers;

use Db;
use DbQuery;

if (!defined('_PS_VERSION_')) {
    exit;
}

class DeliveryModuleManager
{
    /**
     * Détecte le module de livraison utilisé par le panier et exécute la méthode correspondante si elle existe
     *
     * @param \Cart $cart Instance du panier
     */
    public static function handleDeliveryModule($cart)
    {
        try {
            if (!isset($cart->id_carrier) || !$cart->id_carrier) {
                return;
            }
            $carrier = new \Carrier($cart->id_carrier);
            if (!isset($carrier->external_module_name) || !$carrier->external_module_name) {
                return;
            }
            $carrierModuleName = strtolower($carrier->external_module_name);
            // Génère le nom de la méthode à appeler
            $method = 'handle' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $carrierModuleName)));
            if (method_exists(__CLASS__, $method)) {
                static::$method($cart);
            }
        } catch (\Exception $e) {
            \PrestaShopLogger::addLog(
                'DeliveryModuleManager::handleDeliveryModule - Erreur: ' . $e->getMessage() . ' - Cart ID: ' . (int)$cart->id,
                3,
                null,
                'DeliveryModuleManager',
                null,
                true
            );
        }
    }

    /**
     * Met à jour l'order_id pour le module de livraison détecté automatiquement
     * 
     * @param int $cartId ID du panier
     * @param int $orderId ID de la commande
     */
    public static function updateOrderId($cartId, $orderId)
    {
        try {
            // Récupérer le transporteur utilisé par le panier
            $cart = new \Cart($cartId);
            if (!$cart->id || !$cart->id_carrier) {
                return;
            }

            $carrier = new \Carrier($cart->id_carrier);
            if (!isset($carrier->external_module_name) || !$carrier->external_module_name) {
                return;
            }

            $carrierModuleName = strtolower($carrier->external_module_name);
            // Génère le nom de la méthode à appeler
            $method = 'updateOrderId' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $carrierModuleName)));
            if (method_exists(__CLASS__, $method)) {
                static::$method($cartId, $orderId);
            }
        } catch (\Exception $e) {
            \PrestaShopLogger::addLog(
                'DeliveryModuleManager::updateOrderId - Erreur: ' . $e->getMessage() . ' - Cart ID: ' . (int)$cartId . ' - Order ID: ' . (int)$orderId,
                3,
                null,
                'DeliveryModuleManager',
                null,
                true
            );
        }
    }

    /**
     * Pour Mondial Relay
     * Clone la ligne la plus récente avec le même id_address pour le nouveau panier
     */
    protected static function handleMondialrelay($cart)
    {
        try {
            // Vérifier que la table existe
            if (!self::tableExists(_DB_PREFIX_ . 'mondialrelay_selected_relay')) {
                return;
            }

            // Récupérer la ligne la plus récente avec le même id_address_delivery et id_customer
            $query = new DbQuery();
            $query->select('*')
                ->from('mondialrelay_selected_relay')
                ->where('id_address_delivery = ' . (int)$cart->id_address_delivery)
                ->where('id_customer = ' . (int)$cart->id_customer)
                ->where('id_order IS NOT NULL') // Seulement les lignes avec une commande validée
                ->orderBy('date_add DESC')
                ->limit(1);

            $existingRelay = Db::getInstance()->getRow($query);

            if ($existingRelay) {
                // Cloner la ligne en excluant les champs liés à l'expédition
                $newRelay = [
                    'id_address_delivery' => (int)$existingRelay['id_address_delivery'],
                    'id_customer' => (int)$existingRelay['id_customer'],
                    'id_mondialrelay_carrier_method' => (int)$existingRelay['id_mondialrelay_carrier_method'],
                    'id_cart' => (int)$cart->id,
                    'id_order' => null, // Sera mis à jour lors de la validation de la commande
                    'package_weight' => pSQL($existingRelay['package_weight']),
                    'insurance_level' => pSQL($existingRelay['insurance_level']),
                    'selected_relay_num' => pSQL($existingRelay['selected_relay_num']),
                    'selected_relay_adr1' => pSQL($existingRelay['selected_relay_adr1']),
                    'selected_relay_adr2' => pSQL($existingRelay['selected_relay_adr2']),
                    'selected_relay_adr3' => pSQL($existingRelay['selected_relay_adr3']),
                    'selected_relay_adr4' => pSQL($existingRelay['selected_relay_adr4']),
                    'selected_relay_postcode' => pSQL($existingRelay['selected_relay_postcode']),
                    'selected_relay_city' => pSQL($existingRelay['selected_relay_city']),
                    'selected_relay_country_iso' => pSQL($existingRelay['selected_relay_country_iso']),
                    'tracking_url' => null, // Pas encore expédié
                    'label_url' => null, // Pas encore d'étiquette
                    'expedition_num' => null, // Pas encore d'expédition
                    'date_label_generation' => null, // Pas encore généré
                    'hide_history' => (int)$existingRelay['hide_history'],
                    'date_add' => pSQL(date('Y-m-d H:i:s')),
                    'date_upd' => pSQL(date('Y-m-d H:i:s'))
                ];

                // Insérer la nouvelle ligne
                $result = Db::getInstance()->insert('mondialrelay_selected_relay', $newRelay);
                
                if ($result) {
                    \PrestaShopLogger::addLog(
                        'DeliveryModuleManager::handleMondialrelay - Ligne clonée avec succès - Cart ID: ' . (int)$cart->id,
                        1,
                        null,
                        'DeliveryModuleManager',
                        null,
                        true
                    );
                }
            }
        } catch (\Exception $e) {
            \PrestaShopLogger::addLog(
                'DeliveryModuleManager::handleMondialrelay - Erreur: ' . $e->getMessage() . ' - Cart ID: ' . (int)$cart->id,
                3,
                null,
                'DeliveryModuleManager',
                null,
                true
            );
        }
    }

    /**
     * Met à jour l'order_id pour Mondial Relay
     */
    protected static function updateOrderIdMondialrelay($cartId, $orderId)
    {
        try {
            if (!self::tableExists(_DB_PREFIX_ . 'mondialrelay_selected_relay')) {
                return;
            }

            // Vérifier si l'order_id n'est pas déjà attribué pour éviter de modifier une ligne déjà terminée
            $query = new DbQuery();
            $query->select('id_order')
                ->from('mondialrelay_selected_relay')
                ->where('id_cart = ' . (int)$cartId);

            $existingOrderId = Db::getInstance()->getValue($query);

            // Si l'order_id est déjà défini, ne pas faire la mise à jour
            if ($existingOrderId && $existingOrderId > 0) {
                return;
            }

            $data = [
                'id_order' => (int)$orderId,
                'date_upd' => pSQL(date('Y-m-d H:i:s'))
            ];

            $result = Db::getInstance()->update(
                'mondialrelay_selected_relay',
                $data,
                'id_cart = ' . (int)$cartId
            );

            if ($result) {
                \PrestaShopLogger::addLog(
                    'DeliveryModuleManager::updateOrderIdMondialrelay - Order ID mis à jour avec succès - Cart ID: ' . (int)$cartId . ' - Order ID: ' . (int)$orderId,
                    1,
                    null,
                    'DeliveryModuleManager',
                    null,
                    true
                );
            }
        } catch (\Exception $e) {
            \PrestaShopLogger::addLog(
                'DeliveryModuleManager::updateOrderIdMondialrelay - Erreur: ' . $e->getMessage() . ' - Cart ID: ' . (int)$cartId . ' - Order ID: ' . (int)$orderId,
                3,
                null,
                'DeliveryModuleManager',
                null,
                true
            );
        }
    }

    /**
     * Pour DPD France
     * Clone la ligne la plus récente avec le même customer pour le nouveau panier
     */
    protected static function handleDpdfrance($cart)
    {
        try {
            // Vérifier que la table existe
            if (!self::tableExists(_DB_PREFIX_ . 'dpdfrance_shipping')) {
                return;
            }

            // 1. Vérifier que l'id_cart n'a pas déjà une ligne
            $query = new DbQuery();
            $query->select('COUNT(*)')
                ->from('dpdfrance_shipping')
                ->where('id_cart = ' . (int)$cart->id);

            if (Db::getInstance()->getValue($query) > 0) {
                return; // Une ligne existe déjà pour ce panier
            }

            // 2. Trouver la dernière commande payée par le customer_id, dont la colonne module vaut 'ciklik'
            $query = new DbQuery();
            $query->select('o.id_cart')
                ->from('orders', 'o')
                ->where('o.id_customer = ' . (int)$cart->id_customer)
                ->where('o.module = \'' . pSQL('ciklik') . '\'')
                ->where('o.current_state IN (SELECT id_order_state FROM ' . _DB_PREFIX_ . 'order_state WHERE paid = 1)')
                ->orderBy('o.date_add DESC')
                ->limit(1);

            $lastPaidCartId = Db::getInstance()->getValue($query);

            if (!$lastPaidCartId) {
                return; // Aucune commande payée trouvée
            }

            // 3. Trouver dans la table dpdfrance_shipping la ligne avec le cart_id du résultat au point 2
            $query = new DbQuery();
            $query->select('*')
                ->from('dpdfrance_shipping')
                ->where('id_cart = ' . (int)$lastPaidCartId);

            $existingShipping = Db::getInstance()->getRow($query);

            if (!$existingShipping) {
                return; // Aucune ligne de shipping trouvée
            }

            // 4. Dupliquer la ligne, en y remplaçant le cart_id du point 2, par le cart_id courant
            $newShipping = [
                'id_customer' => (int)$existingShipping['id_customer'],
                'id_cart' => (int)$cart->id,
                'id_carrier' => (int)$existingShipping['id_carrier'],
                'service' => pSQL($existingShipping['service']),
                'relay_id' => pSQL($existingShipping['relay_id']),
                'company' => pSQL($existingShipping['company']),
                'address1' => pSQL($existingShipping['address1']),
                'address2' => pSQL($existingShipping['address2']),
                'postcode' => pSQL($existingShipping['postcode']),
                'city' => pSQL($existingShipping['city']),
                'id_country' => (int)$existingShipping['id_country'],
                'gsm_dest' => pSQL($existingShipping['gsm_dest'])
            ];

            // Insérer la nouvelle ligne
            $result = Db::getInstance()->insert('dpdfrance_shipping', $newShipping);

            if ($result) {
                \PrestaShopLogger::addLog(
                    'DeliveryModuleManager::handleDpdfrance - Ligne clonée avec succès - Cart ID: ' . (int)$cart->id,
                    1,
                    null,
                    'DeliveryModuleManager',
                    null,
                    true
                );
            }
        } catch (\Exception $e) {
            \PrestaShopLogger::addLog(
                'DeliveryModuleManager::handleDpdfrance - Erreur: ' . $e->getMessage() . ' - Cart ID: ' . (int)$cart->id,
                3,
                null,
                'DeliveryModuleManager',
                null,
                true
            );
        }
    }

    /**
     * Vérifie si une table existe dans la base de données
     * 
     * @param string $tableName Nom de la table (avec préfixe)
     * @return bool
     */
    private static function tableExists($tableName)
    {
        try {
            $query = new DbQuery();
            $query->select('COUNT(*)')
                ->from('information_schema.tables')
                ->where('table_schema = DATABASE()')
                ->where('table_name = \'' . pSQL($tableName) . '\'');

            return (bool)Db::getInstance()->getValue($query);
        } catch (\Exception $e) {
            \PrestaShopLogger::addLog(
                'DeliveryModuleManager::tableExists - Erreur: ' . $e->getMessage() . ' - Table: ' . pSQL($tableName),
                3,
                null,
                'DeliveryModuleManager',
                null,
                true
            );
            return false;
        }
    }
} 