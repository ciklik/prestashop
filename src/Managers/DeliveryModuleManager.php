<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Managers;

use Configuration;

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

            // Modules transporteur supportés
            $allowedModules = ['mondialrelay', 'dpdfrance', 'colissimo', 'nkmgls', 'chronopost'];
            if (!in_array($carrierModuleName, $allowedModules, true)) {
                return;
            }

            $method = 'handle' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $carrierModuleName)));
            if (method_exists(__CLASS__, $method)) {
                static::$method($cart);
            }
        } catch (\Exception $e) {
            \PrestaShopLogger::addLog(
                'DeliveryModuleManager::handleDeliveryModule - Erreur: ' . $e->getMessage() . ' - Cart ID: ' . (int) $cart->id,
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
                'DeliveryModuleManager::updateOrderId - Erreur: ' . $e->getMessage() . ' - Cart ID: ' . (int) $cartId . ' - Order ID: ' . (int) $orderId,
                3,
                null,
                'DeliveryModuleManager',
                null,
                true
            );
        }
    }

    /**
     * Journalise un événement lié à l'application d'un override de relais.
     * L'échec d'un override n'est jamais bloquant : le driver retombe sur le
     * clonage historique, le rebill ne doit pas échouer à cause de l'override.
     *
     * @param string $module Nom du module transporteur
     * @param int $cartId ID du panier
     * @param string $message Détail de l'événement
     * @param int $severity 1 = info, 2 = warning, 3 = erreur
     */
    private static function logOverride($module, $cartId, $message, $severity = 2)
    {
        \PrestaShopLogger::addLog(
            'DeliveryModuleManager::override[' . $module . '] - ' . $message . ' - Cart ID: ' . (int) $cartId,
            (int) $severity,
            null,
            'DeliveryModuleManager',
            null,
            true
        );
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

            // Idempotence : ne rien refaire si le panier a déjà sa ligne relais
            // (retry de webhook). Garde alignée sur les quatre autres drivers.
            $query = new \DbQuery();
            $query->select('COUNT(*)')
                ->from('mondialrelay_selected_relay')
                ->where('id_cart = ' . (int) $cart->id);

            if (\Db::getInstance()->getValue($query) > 0) {
                return;
            }

            // Override marchand : prioritaire sur le clonage historique. Toute
            // exception pendant son application est neutralisée localement pour
            // garantir le repli sur le clonage (le rebill ne doit jamais échouer
            // à cause de l'override).
            try {
                $override = CiklikDeliveryOverride::get((int) $cart->id_customer, 'mondialrelay');
                if ($override && self::applyMondialrelayOverride($cart, $override)) {
                    return;
                }
            } catch (\Exception $e) {
                self::logOverride('mondialrelay', $cart->id, 'exception: ' . $e->getMessage());
            }

            // Récupérer la ligne la plus récente avec le même id_address_delivery et id_customer
            $sql = 'SELECT * FROM ' . _DB_PREFIX_ . 'mondialrelay_selected_relay
                    WHERE id_address_delivery = ' . (int) $cart->id_address_delivery . '
                      AND id_customer = ' . (int) $cart->id_customer . '
                      AND id_order IS NOT NULL
                    ORDER BY date_add DESC';

            $existingRelay = \Db::getInstance()->getRow($sql);

            if (!$existingRelay) {
                // Récupérer la ligne la plus récente avec le même id_address_delivery et id_customer
                $sql = 'SELECT * FROM ' . _DB_PREFIX_ . 'mondialrelay_selected_relay 
                WHERE id_customer = ' . (int) $cart->id_customer . '
                AND id_order IS NOT NULL
                ORDER BY date_add DESC';

                $existingRelay = \Db::getInstance()->getRow($sql);
            }

            if ($existingRelay) {
                // Cloner la ligne en excluant les champs liés à l'expédition
                $newRelay = [
                    'id_address_delivery' => (int) $existingRelay['id_address_delivery'],
                    'id_customer' => (int) $existingRelay['id_customer'],
                    'id_mondialrelay_carrier_method' => (int) $existingRelay['id_mondialrelay_carrier_method'],
                    'id_cart' => (int) $cart->id,
                    // id_order omis volontairement : Db::insert convertit null en 0,
                    // ce qui ferait matcher la ligne sur les requêtes id_order IS NOT
                    // NULL. La colonne est DEFAULT NULL, mise à jour à la validation.
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
                    // tracking_url, label_url, expedition_num, date_label_generation
                    // omis : pas encore expédié. Db::insert convertit null en '',
                    // invalide pour une colonne DATETIME en sql_mode strict (l'INSERT
                    // échouait alors sur toute la ligne). Omises = DEFAULT NULL.
                    'hide_history' => (int) $existingRelay['hide_history'],
                    'date_add' => pSQL(date('Y-m-d H:i:s')),
                    'date_upd' => pSQL(date('Y-m-d H:i:s')),
                ];

                // Insérer la nouvelle ligne
                $result = \Db::getInstance()->insert('mondialrelay_selected_relay', $newRelay);

                if ($result) {
                    \PrestaShopLogger::addLog(
                        'DeliveryModuleManager::handleMondialrelay - Ligne clonée avec succès - Cart ID: ' . (int) $cart->id,
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
                'DeliveryModuleManager::handleMondialrelay - Erreur: ' . $e->getMessage() . ' - Cart ID: ' . (int) $cart->id,
                3,
                null,
                'DeliveryModuleManager',
                null,
                true
            );
        }
    }

    /**
     * Insère la ligne relais Mondial Relay du panier depuis l'override marchand.
     *
     * Les champs techniques (méthode transporteur, poids, assurance) ne sont pas
     * reconstituables depuis le BO : ils sont repris de la dernière ligne
     * historique du client. Sans historique, l'override est inapplicable et on
     * laisse le clonage se dérouler (cas normalement impossible pour un
     * abonnement actif : la commande initiale a créé une ligne).
     *
     * Payload attendu (clés normalisées) : name, address1, address2, zipcode,
     * city, country_iso.
     *
     * @param \Cart $cart
     * @param array $override ['relay_id' => string, 'payload' => array]
     *
     * @return bool True si la ligne a été insérée depuis l'override
     */
    private static function applyMondialrelayOverride($cart, array $override)
    {
        $payload = $override['payload'];

        $sql = 'SELECT * FROM ' . _DB_PREFIX_ . 'mondialrelay_selected_relay
                WHERE id_customer = ' . (int) $cart->id_customer . '
                  AND id_order IS NOT NULL
                ORDER BY date_add DESC';
        $technicalSource = \Db::getInstance()->getRow($sql);

        if (!$technicalSource) {
            self::logOverride('mondialrelay', $cart->id, 'aucune ligne historique pour les champs techniques, repli sur le clonage');

            return false;
        }

        $now = date('Y-m-d H:i:s');
        $result = \Db::getInstance()->insert('mondialrelay_selected_relay', [
            'id_address_delivery' => (int) $cart->id_address_delivery,
            'id_customer' => (int) $cart->id_customer,
            'id_mondialrelay_carrier_method' => (int) $technicalSource['id_mondialrelay_carrier_method'],
            'id_cart' => (int) $cart->id,
            // id_order omis : cf. remarque dans handleMondialrelay (null -> 0 via Db::insert)
            'package_weight' => pSQL($technicalSource['package_weight']),
            'insurance_level' => pSQL($technicalSource['insurance_level']),
            // Longueurs alignées sur le schéma du module officiel : num
            // varchar(6), adr1-4 varchar(36), postcode 10, city 32, iso 2
            'selected_relay_num' => pSQL(\Tools::substr($override['relay_id'], 0, 6)),
            'selected_relay_adr1' => pSQL(\Tools::substr(isset($payload['name']) ? $payload['name'] : '', 0, 36)),
            'selected_relay_adr2' => pSQL(\Tools::substr(isset($payload['name2']) ? $payload['name2'] : '', 0, 36)),
            'selected_relay_adr3' => pSQL(\Tools::substr(isset($payload['address1']) ? $payload['address1'] : '', 0, 36)),
            'selected_relay_adr4' => pSQL(\Tools::substr(isset($payload['address2']) ? $payload['address2'] : '', 0, 36)),
            'selected_relay_postcode' => pSQL(\Tools::substr(isset($payload['zipcode']) ? $payload['zipcode'] : '', 0, 10)),
            'selected_relay_city' => pSQL(\Tools::substr(isset($payload['city']) ? $payload['city'] : '', 0, 32)),
            'selected_relay_country_iso' => pSQL(\Tools::substr(isset($payload['country_iso']) ? strtoupper($payload['country_iso']) : 'FR', 0, 2)),
            // Colonnes d'expédition (dont date_label_generation DATETIME) omises :
            // pas encore expédié, et null deviendrait '' via Db::insert -> échec en
            // sql_mode strict. Omises = DEFAULT NULL.
            'hide_history' => 0,
            'date_add' => pSQL($now),
            'date_upd' => pSQL($now),
        ]);

        if ($result) {
            self::logOverride('mondialrelay', $cart->id, 'relais override appliqué: ' . $override['relay_id'], 1);
        }

        return (bool) $result;
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
            $query = new \DbQuery();
            $query->select('id_order')
                ->from('mondialrelay_selected_relay')
                ->where('id_cart = ' . (int) $cartId);

            $existingOrderId = \Db::getInstance()->getValue($query);

            // Si l'order_id est déjà défini, ne pas faire la mise à jour
            if ($existingOrderId && $existingOrderId > 0) {
                return;
            }

            $data = [
                'id_order' => (int) $orderId,
                'date_upd' => pSQL(date('Y-m-d H:i:s')),
            ];

            $result = \Db::getInstance()->update(
                'mondialrelay_selected_relay',
                $data,
                'id_cart = ' . (int) $cartId
            );

            if ($result) {
                \PrestaShopLogger::addLog(
                    'DeliveryModuleManager::updateOrderIdMondialrelay - Order ID mis à jour avec succès - Cart ID: ' . (int) $cartId . ' - Order ID: ' . (int) $orderId,
                    1,
                    null,
                    'DeliveryModuleManager',
                    null,
                    true
                );
            }
        } catch (\Exception $e) {
            \PrestaShopLogger::addLog(
                'DeliveryModuleManager::updateOrderIdMondialrelay - Erreur: ' . $e->getMessage() . ' - Cart ID: ' . (int) $cartId . ' - Order ID: ' . (int) $orderId,
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
            $query = new \DbQuery();
            $query->select('COUNT(*)')
                ->from('dpdfrance_shipping')
                ->where('id_cart = ' . (int) $cart->id);

            if (\Db::getInstance()->getValue($query) > 0) {
                return; // Une ligne existe déjà pour ce panier
            }

            // Override marchand : prioritaire sur le clonage historique. Toute
            // exception pendant son application est neutralisée localement pour
            // garantir le repli sur le clonage (le rebill ne doit jamais échouer
            // à cause de l'override).
            try {
                $override = CiklikDeliveryOverride::get((int) $cart->id_customer, 'dpdfrance');
                if ($override && self::applyDpdfranceOverride($cart, $override)) {
                    return;
                }
            } catch (\Exception $e) {
                self::logOverride('dpdfrance', $cart->id, 'exception: ' . $e->getMessage());
            }

            // 2. Trouver la dernière commande payée par le customer_id, dont la colonne module vaut 'ciklik'
            $sql = 'SELECT o.id_cart FROM ' . _DB_PREFIX_ . 'orders o
                    WHERE o.id_customer = ' . (int) $cart->id_customer . '
                      AND o.module = \'ciklik\'
                      AND o.current_state IN (SELECT id_order_state FROM ' . _DB_PREFIX_ . 'order_state WHERE paid = 1)
                    ORDER BY o.date_add DESC';

            $lastPaidCartId = \Db::getInstance()->getValue($sql);

            if (!$lastPaidCartId) {
                return; // Aucune commande payée trouvée
            }

            // 3. Trouver dans la table dpdfrance_shipping la ligne avec le cart_id du résultat au point 2
            $query = new \DbQuery();
            $query->select('*')
                ->from('dpdfrance_shipping')
                ->where('id_cart = ' . (int) $lastPaidCartId);

            $existingShipping = \Db::getInstance()->getRow($query);

            if (!$existingShipping) {
                return; // Aucune ligne de shipping trouvée
            }

            // 4. Dupliquer la ligne, en y remplaçant le cart_id du point 2, par le cart_id courant
            $newShipping = [
                'id_customer' => (int) $existingShipping['id_customer'],
                'id_cart' => (int) $cart->id,
                'id_carrier' => (int) $existingShipping['id_carrier'],
                'service' => pSQL($existingShipping['service']),
                'relay_id' => pSQL($existingShipping['relay_id']),
                'company' => pSQL($existingShipping['company']),
                'address1' => pSQL($existingShipping['address1']),
                'address2' => pSQL($existingShipping['address2']),
                'postcode' => pSQL($existingShipping['postcode']),
                'city' => pSQL($existingShipping['city']),
                'id_country' => (int) $existingShipping['id_country'],
                'gsm_dest' => pSQL($existingShipping['gsm_dest']),
            ];

            // Insérer la nouvelle ligne
            $result = \Db::getInstance()->insert('dpdfrance_shipping', $newShipping);

            if ($result) {
                \PrestaShopLogger::addLog(
                    'DeliveryModuleManager::handleDpdfrance - Ligne clonée avec succès - Cart ID: ' . (int) $cart->id,
                    1,
                    null,
                    'DeliveryModuleManager',
                    null,
                    true
                );
            }
        } catch (\Exception $e) {
            \PrestaShopLogger::addLog(
                'DeliveryModuleManager::handleDpdfrance - Erreur: ' . $e->getMessage() . ' - Cart ID: ' . (int) $cart->id,
                3,
                null,
                'DeliveryModuleManager',
                null,
                true
            );
        }
    }

    /**
     * Insère la ligne relais DPD France du panier depuis l'override marchand.
     *
     * Le champ technique « service » est repris de la dernière ligne
     * historique du client ; sans historique, 'REL' (relais) par défaut,
     * comme le fait le module Shoppingfeed pour ses imports de commandes.
     *
     * Payload attendu (clés normalisées) : name, address1, address2, zipcode,
     * city, country_iso, phone.
     *
     * @param \Cart $cart
     * @param array $override ['relay_id' => string, 'payload' => array]
     *
     * @return bool True si la ligne a été insérée depuis l'override
     */
    private static function applyDpdfranceOverride($cart, array $override)
    {
        $payload = $override['payload'];

        $technicalSource = \Db::getInstance()->getRow(
            'SELECT * FROM ' . _DB_PREFIX_ . 'dpdfrance_shipping
             WHERE id_customer = ' . (int) $cart->id_customer . '
             ORDER BY id_cart DESC'
        );

        // Sans historique : service relais 'REL' par défaut (même valeur que
        // celle posée en dur par le module Shoppingfeed pour ses imports)
        if (!$technicalSource) {
            $technicalSource = ['service' => 'REL', 'id_country' => 0, 'gsm_dest' => ''];
        }

        $idCountry = 0;
        if (!empty($payload['country_iso'])) {
            $idCountry = (int) \Country::getByIso(strtoupper($payload['country_iso']));
        }
        if (!$idCountry) {
            $idCountry = (int) $technicalSource['id_country'];
        }

        $result = \Db::getInstance()->insert('dpdfrance_shipping', [
            'id_customer' => (int) $cart->id_customer,
            'id_cart' => (int) $cart->id,
            'id_carrier' => (int) $cart->id_carrier,
            'service' => pSQL($technicalSource['service']),
            'relay_id' => pSQL($override['relay_id']),
            'company' => pSQL(isset($payload['name']) ? $payload['name'] : ''),
            'address1' => pSQL(isset($payload['address1']) ? $payload['address1'] : ''),
            'address2' => pSQL(isset($payload['address2']) ? $payload['address2'] : ''),
            'postcode' => pSQL(isset($payload['zipcode']) ? $payload['zipcode'] : ''),
            'city' => pSQL(isset($payload['city']) ? $payload['city'] : ''),
            'id_country' => $idCountry,
            'gsm_dest' => pSQL(isset($payload['phone']) && '' !== (string) $payload['phone']
                ? $payload['phone']
                : $technicalSource['gsm_dest']),
        ]);

        if ($result) {
            self::logOverride('dpdfrance', $cart->id, 'relais override appliqué: ' . $override['relay_id'], 1);
        }

        return (bool) $result;
    }

    /**
     * Pour Colissimo
     * Clone la ligne la plus récente avec le même customer pour le nouveau panier
     */
    protected static function handleColissimo($cart)
    {
        try {
            // Vérifier que la table existe
            if (!self::tableExists(_DB_PREFIX_ . 'colissimo_cart_pickup_point')) {
                return;
            }

            // 1. Vérifier que l'id_cart n'a pas déjà une ligne
            $query = new \DbQuery();
            $query->select('COUNT(*)')
                ->from('colissimo_cart_pickup_point')
                ->where('id_cart = ' . (int) $cart->id);

            if (\Db::getInstance()->getValue($query) > 0) {
                return; // Une ligne existe déjà pour ce panier
            }

            // Override marchand : prioritaire sur le clonage historique. Toute
            // exception pendant son application est neutralisée localement pour
            // garantir le repli sur le clonage (le rebill ne doit jamais échouer
            // à cause de l'override).
            try {
                $override = CiklikDeliveryOverride::get((int) $cart->id_customer, 'colissimo');
                if ($override && self::applyColissimoOverride($cart, $override)) {
                    return;
                }
            } catch (\Exception $e) {
                self::logOverride('colissimo', $cart->id, 'exception: ' . $e->getMessage());
            }

            // 2. Trouver la dernière commande payée par le customer_id, dont la colonne module vaut 'ciklik'
            $sql = 'SELECT o.id_cart FROM ' . _DB_PREFIX_ . 'orders o
                    WHERE o.id_customer = ' . (int) $cart->id_customer . '
                      AND o.module = \'ciklik\'
                      AND o.current_state IN (SELECT id_order_state FROM ' . _DB_PREFIX_ . 'order_state WHERE paid = 1)
                    ORDER BY o.date_add DESC';

            $lastPaidCartId = \Db::getInstance()->getValue($sql);

            if (!$lastPaidCartId) {
                return; // Aucune commande payée trouvée
            }

            // 3. Trouver dans la table colissimo_cart_pickup_point la ligne avec le cart_id du résultat au point 2
            $query = new \DbQuery();
            $query->select('*')
                ->from('colissimo_cart_pickup_point')
                ->where('id_cart = ' . (int) $lastPaidCartId);

            $existingPickupPoint = \Db::getInstance()->getRow($query);

            if (!$existingPickupPoint) {
                return; // Aucune ligne de pickup point trouvée
            }

            // 4. Dupliquer la ligne, en y remplaçant le cart_id du point 2, par le cart_id courant
            $newPickupPoint = [
                'id_cart' => (int) $cart->id,
                'id_colissimo_pickup_point' => (int) $existingPickupPoint['id_colissimo_pickup_point'],
                'mobile_phone' => pSQL($existingPickupPoint['mobile_phone']),
            ];

            // Insérer la nouvelle ligne
            $result = \Db::getInstance()->insert('colissimo_cart_pickup_point', $newPickupPoint);

            if ($result) {
                \PrestaShopLogger::addLog(
                    'DeliveryModuleManager::handleColissimo - Ligne clonée avec succès - Cart ID: ' . (int) $cart->id,
                    1,
                    null,
                    'DeliveryModuleManager',
                    null,
                    true
                );
            }
        } catch (\Exception $e) {
            \PrestaShopLogger::addLog(
                'DeliveryModuleManager::handleColissimo - Erreur: ' . $e->getMessage() . ' - Cart ID: ' . (int) $cart->id,
                3,
                null,
                'DeliveryModuleManager',
                null,
                true
            );
        }
    }

    /**
     * Insère la ligne relais Colissimo du panier depuis l'override marchand.
     *
     * La table panier référence une ligne de détail (colissimo_pickup_point) :
     * on la retrouve par code relais, ou on la crée depuis le payload si le
     * relais n'a jamais été utilisé sur la boutique.
     *
     * Payload attendu (clés normalisées) : name, address1, address2, zipcode,
     * city, country_iso, phone ; extras Colissimo : product_code, network.
     *
     * @param \Cart $cart
     * @param array $override ['relay_id' => string, 'payload' => array]
     *
     * @return bool True si la ligne a été insérée depuis l'override
     */
    private static function applyColissimoOverride($cart, array $override)
    {
        $payload = $override['payload'];

        $idPickupPoint = self::findOrCreateColissimoPickupPoint($override['relay_id'], $payload);

        if (!$idPickupPoint) {
            self::logOverride('colissimo', $cart->id, 'ligne de détail du relais introuvable et non créable, repli sur le clonage');

            return false;
        }

        // Téléphone mobile : payload, sinon celui de la dernière ligne du client
        $mobilePhone = isset($payload['phone']) ? (string) $payload['phone'] : '';
        if ('' === $mobilePhone) {
            $mobilePhone = (string) \Db::getInstance()->getValue(
                'SELECT ccp.mobile_phone
                 FROM ' . _DB_PREFIX_ . 'colissimo_cart_pickup_point ccp
                 INNER JOIN ' . _DB_PREFIX_ . 'orders o ON o.id_cart = ccp.id_cart
                 WHERE o.id_customer = ' . (int) $cart->id_customer . "
                   AND o.module = 'ciklik'
                 ORDER BY o.date_add DESC"
            );
        }

        $result = \Db::getInstance()->insert('colissimo_cart_pickup_point', [
            'id_cart' => (int) $cart->id,
            'id_colissimo_pickup_point' => (int) $idPickupPoint,
            'mobile_phone' => pSQL($mobilePhone),
        ]);

        if ($result) {
            self::logOverride('colissimo', $cart->id, 'relais override appliqué: ' . $override['relay_id'], 1);
        }

        return (bool) $result;
    }

    /**
     * Retrouve la ligne de détail Colissimo par code relais, ou la crée depuis
     * le payload. Les colonnes de colissimo_pickup_point varient selon les
     * versions du module Colissimo : insertion défensive restreinte aux
     * colonnes réellement présentes.
     *
     * @param string $relayId Code du point de retrait Colissimo
     * @param array $payload Champs normalisés + extras
     *
     * @return int ID de la ligne de détail, 0 si introuvable et non créable
     */
    private static function findOrCreateColissimoPickupPoint($relayId, array $payload)
    {
        if (!self::tableExists(_DB_PREFIX_ . 'colissimo_pickup_point')) {
            return 0;
        }

        $existing = \Db::getInstance()->getValue(
            'SELECT id_colissimo_pickup_point FROM ' . _DB_PREFIX_ . "colissimo_pickup_point
             WHERE colissimo_id = '" . pSQL($relayId) . "'"
        );

        if ($existing) {
            return (int) $existing;
        }

        $columns = [];
        $rows = \Db::getInstance()->executeS('SHOW COLUMNS FROM `' . _DB_PREFIX_ . 'colissimo_pickup_point`');
        foreach ((array) $rows as $row) {
            $columns[$row['Field']] = true;
        }

        // Longueurs et colonnes NOT NULL alignées sur le CREATE TABLE du module
        // officiel Colissimo : colissimo_id VARCHAR(8), company_name 64,
        // address1/2 120, city 80, zipcode 10, country (nom, NOT NULL),
        // iso_country 2, product_code 3 NOT NULL, network 10,
        // date_add/date_upd DATETIME NOT NULL.
        $isoCountry = isset($payload['country_iso']) ? strtoupper($payload['country_iso']) : 'FR';
        $idCountry = (int) \Country::getByIso($isoCountry);
        $countryName = $idCountry
            ? (string) \Country::getNameById((int) \Configuration::get('PS_LANG_DEFAULT'), $idCountry)
            : $isoCountry;
        $now = date('Y-m-d H:i:s');

        $candidate = [
            'colissimo_id' => pSQL(\Tools::substr($relayId, 0, 8)),
            'company_name' => pSQL(\Tools::substr(isset($payload['name']) ? $payload['name'] : '', 0, 64)),
            'address1' => pSQL(\Tools::substr(isset($payload['address1']) ? $payload['address1'] : '', 0, 120)),
            'address2' => pSQL(\Tools::substr(isset($payload['address2']) ? $payload['address2'] : '', 0, 120)),
            'zipcode' => pSQL(\Tools::substr(isset($payload['zipcode']) ? $payload['zipcode'] : '', 0, 10)),
            'city' => pSQL(\Tools::substr(isset($payload['city']) ? $payload['city'] : '', 0, 80)),
            'country' => pSQL(\Tools::substr($countryName, 0, 64)),
            'iso_country' => pSQL(\Tools::substr($isoCountry, 0, 2)),
            'product_code' => pSQL(\Tools::substr(isset($payload['product_code']) ? $payload['product_code'] : '', 0, 3)),
            'network' => pSQL(\Tools::substr(isset($payload['network']) ? $payload['network'] : '', 0, 10)),
            'date_add' => pSQL($now),
            'date_upd' => pSQL($now),
        ];

        $insert = array_intersect_key($candidate, $columns);

        if (!isset($insert['colissimo_id'])) {
            return 0;
        }

        if (!\Db::getInstance()->insert('colissimo_pickup_point', $insert)) {
            return 0;
        }

        return (int) \Db::getInstance()->Insert_ID();
    }

    /**
     * Pour GLS (module nkmgls)
     * Clone la ligne la plus récente avec le même customer pour le nouveau panier
     */
    protected static function handleNkmgls($cart)
    {
        try {
            // Vérifier que la table existe
            if (!self::tableExists(_DB_PREFIX_ . 'gls_cart_carrier')) {
                return;
            }

            // Vérifier que le transporteur est bien GLS point relais
            $carrier = new \Carrier($cart->id_carrier);
            if (!$carrier->id) {
                return; // Transporteur invalide
            }

            $glsRelaisId = (int) \Configuration::get('GLS_GLSRELAIS_ID');
            if (!$glsRelaisId) {
                return; // Configuration GLS non trouvée
            }

            // Vérifier que l'id_carrier correspond au transporteur GLS point relais
            // ou que l'id_reference correspond (au cas où il y aurait plusieurs instances du même transporteur)
            $glsRelaisCarrier = new \Carrier($glsRelaisId);
            if ($cart->id_carrier != $glsRelaisId && $carrier->id_reference != $glsRelaisCarrier->id_reference) {
                return; // Ce n'est pas le transporteur GLS point relais
            }

            // 1. Vérifier que l'id_cart n'a pas déjà une ligne
            $query = new \DbQuery();
            $query->select('COUNT(*)')
                ->from('gls_cart_carrier')
                ->where('id_cart = ' . (int) $cart->id)
                ->where('id_customer = ' . (int) $cart->id_customer);

            if (\Db::getInstance()->getValue($query) > 0) {
                return; // Une ligne existe déjà pour ce panier
            }

            // Override marchand : prioritaire sur le clonage historique. Toute
            // exception pendant son application est neutralisée localement pour
            // garantir le repli sur le clonage (le rebill ne doit jamais échouer
            // à cause de l'override).
            try {
                $override = CiklikDeliveryOverride::get((int) $cart->id_customer, 'nkmgls');
                if ($override && self::applyNkmglsOverride($cart, $override)) {
                    return;
                }
            } catch (\Exception $e) {
                self::logOverride('nkmgls', $cart->id, 'exception: ' . $e->getMessage());
            }

            // 2. Trouver la dernière commande payée par le customer_id, dont la colonne module vaut 'ciklik'
            $sql = 'SELECT o.id_cart FROM ' . _DB_PREFIX_ . 'orders o
                    WHERE o.id_customer = ' . (int) $cart->id_customer . '
                      AND o.module = \'ciklik\'
                      AND o.current_state IN (SELECT id_order_state FROM ' . _DB_PREFIX_ . 'order_state WHERE paid = 1)
                    ORDER BY o.date_add DESC';

            $lastPaidCartId = \Db::getInstance()->getValue($sql);

            if (!$lastPaidCartId) {
                return; // Aucune commande payée trouvée
            }

            // 3. Trouver dans la table gls_cart_carrier la ligne avec le cart_id du résultat au point 2
            $query = new \DbQuery();
            $query->select('*')
                ->from('gls_cart_carrier')
                ->where('id_cart = ' . (int) $lastPaidCartId)
                ->where('id_customer = ' . (int) $cart->id_customer);

            $existingGlsCarrier = \Db::getInstance()->getRow($query);

            if (!$existingGlsCarrier) {
                return; // Aucune ligne de GLS trouvée
            }

            // 4. Dupliquer la ligne, en y remplaçant le cart_id du point 2, par le cart_id courant
            // Utiliser l'id_carrier du panier actuel (qui est déjà validé comme GLS point relais)
            $newGlsCarrier = [
                'id_cart' => (int) $cart->id,
                'id_customer' => (int) $cart->id_customer,
                'id_carrier' => (int) $cart->id_carrier,
                'original_id_address_delivery' => (int) $cart->id_address_delivery,
                'gls_product' => pSQL($existingGlsCarrier['gls_product']),
                'parcel_shop_id' => pSQL($existingGlsCarrier['parcel_shop_id']),
                'name' => pSQL($existingGlsCarrier['name']),
                'address1' => pSQL($existingGlsCarrier['address1']),
                'address2' => pSQL($existingGlsCarrier['address2']),
                'postcode' => pSQL($existingGlsCarrier['postcode']),
                'city' => pSQL($existingGlsCarrier['city']),
                'phone' => pSQL($existingGlsCarrier['phone']),
                'phone_mobile' => pSQL($existingGlsCarrier['phone_mobile']),
                'customer_phone_mobile' => pSQL($existingGlsCarrier['customer_phone_mobile']),
                'id_country' => isset($existingGlsCarrier['id_country']) ? (int) $existingGlsCarrier['id_country'] : null,
                'parcel_shop_working_day' => pSQL($existingGlsCarrier['parcel_shop_working_day']),
            ];

            // Insérer la nouvelle ligne
            $result = \Db::getInstance()->insert('gls_cart_carrier', $newGlsCarrier);

            if ($result) {
                \PrestaShopLogger::addLog(
                    'DeliveryModuleManager::handleNkmgls - Ligne clonée avec succès - Cart ID: ' . (int) $cart->id . ' - Parcel Shop ID: ' . pSQL($existingGlsCarrier['parcel_shop_id']),
                    1,
                    null,
                    'DeliveryModuleManager',
                    null,
                    true
                );
            }
        } catch (\Exception $e) {
            \PrestaShopLogger::addLog(
                'DeliveryModuleManager::handleNkmgls - Erreur: ' . $e->getMessage() . ' - Cart ID: ' . (int) $cart->id,
                3,
                null,
                'DeliveryModuleManager',
                null,
                true
            );
        }
    }

    /**
     * Insère la ligne relais GLS du panier depuis l'override marchand.
     *
     * Le champ technique « gls_product » n'est pas reconstituable depuis le
     * BO : il est repris de la dernière ligne historique du client. Sans
     * historique, l'override est inapplicable (repli sur le clonage).
     *
     * Payload attendu (clés normalisées) : name, address1, address2, zipcode,
     * city, country_iso, phone ; extra GLS : parcel_shop_working_day.
     *
     * @param \Cart $cart
     * @param array $override ['relay_id' => string, 'payload' => array]
     *
     * @return bool True si la ligne a été insérée depuis l'override
     */
    private static function applyNkmglsOverride($cart, array $override)
    {
        $payload = $override['payload'];

        $technicalSource = \Db::getInstance()->getRow(
            'SELECT * FROM ' . _DB_PREFIX_ . 'gls_cart_carrier
             WHERE id_customer = ' . (int) $cart->id_customer . '
             ORDER BY id_cart DESC'
        );

        if (!$technicalSource) {
            self::logOverride('nkmgls', $cart->id, 'aucune ligne historique pour le champ gls_product, repli sur le clonage');

            return false;
        }

        $idCountry = null;
        if (!empty($payload['country_iso'])) {
            $idCountry = (int) \Country::getByIso(strtoupper($payload['country_iso']));
        }
        if (!$idCountry && isset($technicalSource['id_country'])) {
            $idCountry = (int) $technicalSource['id_country'];
        }

        $result = \Db::getInstance()->insert('gls_cart_carrier', [
            'id_cart' => (int) $cart->id,
            'id_customer' => (int) $cart->id_customer,
            'id_carrier' => (int) $cart->id_carrier,
            'original_id_address_delivery' => (int) $cart->id_address_delivery,
            'gls_product' => pSQL($technicalSource['gls_product']),
            'parcel_shop_id' => pSQL($override['relay_id']),
            'name' => pSQL(isset($payload['name']) ? $payload['name'] : ''),
            'address1' => pSQL(isset($payload['address1']) ? $payload['address1'] : ''),
            'address2' => pSQL(isset($payload['address2']) ? $payload['address2'] : ''),
            'postcode' => pSQL(isset($payload['zipcode']) ? $payload['zipcode'] : ''),
            'city' => pSQL(isset($payload['city']) ? $payload['city'] : ''),
            'phone' => pSQL(isset($payload['phone']) ? $payload['phone'] : ''),
            'phone_mobile' => pSQL($technicalSource['phone_mobile']),
            'customer_phone_mobile' => pSQL($technicalSource['customer_phone_mobile']),
            'id_country' => $idCountry,
            'parcel_shop_working_day' => pSQL(isset($payload['parcel_shop_working_day'])
                ? $payload['parcel_shop_working_day']
                : $technicalSource['parcel_shop_working_day']),
        ]);

        if ($result) {
            self::logOverride('nkmgls', $cart->id, 'relais override appliqué: ' . $override['relay_id'], 1);
        }

        return (bool) $result;
    }

    /**
     * Retourne l'ID du panier de la dernière commande Ciklik payée du client
     * (la source du clonage historique pour la plupart des drivers).
     *
     * @param int $idCustomer
     *
     * @return int 0 si aucune
     */
    private static function getLastPaidCiklikCartId($idCustomer)
    {
        return (int) \Db::getInstance()->getValue(
            'SELECT o.id_cart FROM ' . _DB_PREFIX_ . 'orders o
             WHERE o.id_customer = ' . (int) $idCustomer . '
               AND o.module = \'ciklik\'
               AND o.current_state IN (SELECT id_order_state FROM ' . _DB_PREFIX_ . 'order_state WHERE paid = 1)
             ORDER BY o.date_add DESC'
        );
    }

    /**
     * Lecture seule : le relais que le clonage historique utiliserait au
     * prochain rebill (sans tenir compte d'un éventuel override). Sert au
     * bloc BO pour afficher l'état « automatique » courant.
     *
     * @param int $idCustomer
     * @param string $module Nom du module transporteur (minuscules)
     *
     * @return array|null ['relay_id' => string, 'label' => string] ou null
     */
    public static function peekLegacyRelay($idCustomer, $module)
    {
        try {
            switch ($module) {
                case 'mondialrelay':
                    if (!self::tableExists(_DB_PREFIX_ . 'mondialrelay_selected_relay')) {
                        return null;
                    }
                    $row = \Db::getInstance()->getRow(
                        'SELECT selected_relay_num, selected_relay_adr1, selected_relay_city
                         FROM ' . _DB_PREFIX_ . 'mondialrelay_selected_relay
                         WHERE id_customer = ' . (int) $idCustomer . '
                           AND id_order IS NOT NULL
                         ORDER BY date_add DESC'
                    );

                    return $row ? [
                        'relay_id' => (string) $row['selected_relay_num'],
                        'label' => trim($row['selected_relay_adr1'] . ' - ' . $row['selected_relay_city'], ' -'),
                    ] : null;

                case 'colissimo':
                    if (!self::tableExists(_DB_PREFIX_ . 'colissimo_cart_pickup_point')) {
                        return null;
                    }
                    $cartId = self::getLastPaidCiklikCartId($idCustomer);
                    if (!$cartId) {
                        return null;
                    }
                    $row = \Db::getInstance()->getRow(
                        'SELECT id_colissimo_pickup_point FROM ' . _DB_PREFIX_ . 'colissimo_cart_pickup_point
                         WHERE id_cart = ' . (int) $cartId
                    );
                    if (!$row) {
                        return null;
                    }
                    $detail = null;
                    if (self::tableExists(_DB_PREFIX_ . 'colissimo_pickup_point')) {
                        $detail = \Db::getInstance()->getRow(
                            'SELECT * FROM ' . _DB_PREFIX_ . 'colissimo_pickup_point
                             WHERE id_colissimo_pickup_point = ' . (int) $row['id_colissimo_pickup_point']
                        );
                    }

                    return [
                        'relay_id' => $detail && isset($detail['colissimo_id'])
                            ? (string) $detail['colissimo_id']
                            : (string) $row['id_colissimo_pickup_point'],
                        'label' => $detail
                            ? trim((isset($detail['company_name']) ? $detail['company_name'] : '') . ' - ' . (isset($detail['city']) ? $detail['city'] : ''), ' -')
                            : '',
                    ];

                case 'dpdfrance':
                    if (!self::tableExists(_DB_PREFIX_ . 'dpdfrance_shipping')) {
                        return null;
                    }
                    $cartId = self::getLastPaidCiklikCartId($idCustomer);
                    if (!$cartId) {
                        return null;
                    }
                    $row = \Db::getInstance()->getRow(
                        'SELECT relay_id, company, city FROM ' . _DB_PREFIX_ . 'dpdfrance_shipping
                         WHERE id_cart = ' . (int) $cartId
                    );

                    return $row ? [
                        'relay_id' => (string) $row['relay_id'],
                        'label' => trim($row['company'] . ' - ' . $row['city'], ' -'),
                    ] : null;

                case 'nkmgls':
                    if (!self::tableExists(_DB_PREFIX_ . 'gls_cart_carrier')) {
                        return null;
                    }
                    $cartId = self::getLastPaidCiklikCartId($idCustomer);
                    if (!$cartId) {
                        return null;
                    }
                    $row = \Db::getInstance()->getRow(
                        'SELECT parcel_shop_id, name, city FROM ' . _DB_PREFIX_ . 'gls_cart_carrier
                         WHERE id_cart = ' . (int) $cartId . '
                           AND id_customer = ' . (int) $idCustomer
                    );

                    return $row ? [
                        'relay_id' => (string) $row['parcel_shop_id'],
                        'label' => trim($row['name'] . ' - ' . $row['city'], ' -'),
                    ] : null;

                case 'chronopost':
                    if (!self::tableExists(_DB_PREFIX_ . 'chrono_cart_relais')) {
                        return null;
                    }
                    $idPr = \Db::getInstance()->getValue(
                        'SELECT ccr.id_pr
                         FROM ' . _DB_PREFIX_ . 'chrono_cart_relais ccr
                         INNER JOIN ' . _DB_PREFIX_ . 'orders o ON o.id_cart = ccr.id_cart
                         WHERE o.id_customer = ' . (int) $idCustomer . '
                           AND o.module = \'ciklik\'
                           AND o.current_state IN (SELECT id_order_state FROM ' . _DB_PREFIX_ . 'order_state WHERE paid = 1)
                           AND ccr.id_pr IS NOT NULL AND ccr.id_pr != \'\'
                         ORDER BY o.date_add DESC'
                    );

                    return $idPr ? ['relay_id' => (string) $idPr, 'label' => ''] : null;
            }
        } catch (\Exception $e) {
            return null;
        }

        return null;
    }

    /**
     * Lecture seule : relais déjà utilisés par le client sur ce transporteur
     * (repli de sélection du bloc BO quand la recherche est indisponible).
     * Résultats normalisés et dédoublonnés par relay_id.
     *
     * @param int $idCustomer
     * @param string $module Nom du module transporteur (minuscules)
     *
     * @return array Liste de ['relay_id', 'name', 'address1', 'address2', 'zipcode', 'city', 'country_iso', ...extras]
     */
    public static function getKnownRelays($idCustomer, $module)
    {
        $items = [];

        try {
            switch ($module) {
                case 'mondialrelay':
                    if (!self::tableExists(_DB_PREFIX_ . 'mondialrelay_selected_relay')) {
                        return [];
                    }
                    $rows = \Db::getInstance()->executeS(
                        'SELECT selected_relay_num, selected_relay_adr1, selected_relay_adr2, selected_relay_adr3,
                                selected_relay_adr4, selected_relay_postcode, selected_relay_city, selected_relay_country_iso
                         FROM ' . _DB_PREFIX_ . 'mondialrelay_selected_relay
                         WHERE id_customer = ' . (int) $idCustomer . '
                           AND id_order IS NOT NULL
                         ORDER BY date_add DESC'
                    );
                    foreach ((array) $rows as $row) {
                        $items[] = [
                            'relay_id' => (string) $row['selected_relay_num'],
                            'name' => (string) $row['selected_relay_adr1'],
                            'name2' => (string) $row['selected_relay_adr2'],
                            'address1' => (string) $row['selected_relay_adr3'],
                            'address2' => (string) $row['selected_relay_adr4'],
                            'zipcode' => (string) $row['selected_relay_postcode'],
                            'city' => (string) $row['selected_relay_city'],
                            'country_iso' => (string) $row['selected_relay_country_iso'],
                        ];
                    }
                    break;

                case 'colissimo':
                    if (!self::tableExists(_DB_PREFIX_ . 'colissimo_cart_pickup_point')
                        || !self::tableExists(_DB_PREFIX_ . 'colissimo_pickup_point')) {
                        return [];
                    }
                    $rows = \Db::getInstance()->executeS(
                        'SELECT DISTINCT pp.*
                         FROM ' . _DB_PREFIX_ . 'colissimo_cart_pickup_point ccp
                         INNER JOIN ' . _DB_PREFIX_ . 'cart c ON c.id_cart = ccp.id_cart
                         INNER JOIN ' . _DB_PREFIX_ . 'colissimo_pickup_point pp
                            ON pp.id_colissimo_pickup_point = ccp.id_colissimo_pickup_point
                         WHERE c.id_customer = ' . (int) $idCustomer . '
                         ORDER BY pp.id_colissimo_pickup_point DESC'
                    );
                    foreach ((array) $rows as $row) {
                        $items[] = [
                            'relay_id' => isset($row['colissimo_id']) ? (string) $row['colissimo_id'] : (string) $row['id_colissimo_pickup_point'],
                            'name' => isset($row['company_name']) ? (string) $row['company_name'] : '',
                            'address1' => isset($row['address1']) ? (string) $row['address1'] : '',
                            'address2' => isset($row['address2']) ? (string) $row['address2'] : '',
                            'zipcode' => isset($row['zipcode']) ? (string) $row['zipcode'] : '',
                            'city' => isset($row['city']) ? (string) $row['city'] : '',
                            'country_iso' => isset($row['iso_country']) ? (string) $row['iso_country'] : '',
                            'product_code' => isset($row['product_code']) ? (string) $row['product_code'] : '',
                            'network' => isset($row['network']) ? (string) $row['network'] : '',
                        ];
                    }
                    break;

                case 'dpdfrance':
                    if (!self::tableExists(_DB_PREFIX_ . 'dpdfrance_shipping')) {
                        return [];
                    }
                    $rows = \Db::getInstance()->executeS(
                        'SELECT relay_id, company, address1, address2, postcode, city
                         FROM ' . _DB_PREFIX_ . 'dpdfrance_shipping
                         WHERE id_customer = ' . (int) $idCustomer . '
                         ORDER BY id_cart DESC'
                    );
                    foreach ((array) $rows as $row) {
                        $items[] = [
                            'relay_id' => (string) $row['relay_id'],
                            'name' => (string) $row['company'],
                            'address1' => (string) $row['address1'],
                            'address2' => (string) $row['address2'],
                            'zipcode' => (string) $row['postcode'],
                            'city' => (string) $row['city'],
                            'country_iso' => '',
                        ];
                    }
                    break;

                case 'nkmgls':
                    if (!self::tableExists(_DB_PREFIX_ . 'gls_cart_carrier')) {
                        return [];
                    }
                    $rows = \Db::getInstance()->executeS(
                        'SELECT parcel_shop_id, name, address1, address2, postcode, city, parcel_shop_working_day
                         FROM ' . _DB_PREFIX_ . 'gls_cart_carrier
                         WHERE id_customer = ' . (int) $idCustomer . '
                         ORDER BY id_cart DESC'
                    );
                    foreach ((array) $rows as $row) {
                        $items[] = [
                            'relay_id' => (string) $row['parcel_shop_id'],
                            'name' => (string) $row['name'],
                            'address1' => (string) $row['address1'],
                            'address2' => (string) $row['address2'],
                            'zipcode' => (string) $row['postcode'],
                            'city' => (string) $row['city'],
                            'country_iso' => '',
                            'parcel_shop_working_day' => (string) $row['parcel_shop_working_day'],
                        ];
                    }
                    break;

                case 'chronopost':
                    if (!self::tableExists(_DB_PREFIX_ . 'chrono_cart_relais')) {
                        return [];
                    }
                    $rows = \Db::getInstance()->executeS(
                        'SELECT DISTINCT ccr.id_pr
                         FROM ' . _DB_PREFIX_ . 'chrono_cart_relais ccr
                         INNER JOIN ' . _DB_PREFIX_ . 'cart c ON c.id_cart = ccr.id_cart
                         WHERE c.id_customer = ' . (int) $idCustomer . '
                           AND ccr.id_pr IS NOT NULL AND ccr.id_pr != \'\'
                         ORDER BY ccr.id_pr DESC'
                    );
                    foreach ((array) $rows as $row) {
                        $items[] = [
                            'relay_id' => (string) $row['id_pr'],
                            'name' => '',
                            'address1' => '',
                            'address2' => '',
                            'zipcode' => '',
                            'city' => '',
                            'country_iso' => '',
                        ];
                    }
                    break;
            }
        } catch (\Exception $e) {
            return [];
        }

        // Dédoublonnage par relay_id, en conservant la plus récente (première)
        $deduped = [];
        foreach ($items as $item) {
            if ('' === $item['relay_id'] || isset($deduped[$item['relay_id']])) {
                continue;
            }
            $deduped[$item['relay_id']] = $item;
        }

        return array_values($deduped);
    }

    /**
     * Vérifie si une table existe dans la base de données
     *
     * @param string $tableName Nom de la table (avec préfixe)
     *
     * @return bool
     */
    private static function tableExists($tableName)
    {
        try {
            return (bool) \Db::getInstance()->getValue("SELECT COUNT(*) FROM `information_schema`.`TABLES` WHERE `TABLE_SCHEMA` = '" . _DB_NAME_ . "' AND `TABLE_NAME` = '" . bqSQL($tableName) . "'");
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

    public static function handleChronopost($cart)
    {
        try {
            $carrier = new \Carrier($cart->id_carrier);

            // Vérifier que $carrier->id_reference est un des id dans getChronoRelaisIDs
            if (!in_array($carrier->id_reference, self::getChronoRelaisIDs())) {
                return;
            }

            // Vérifier que la table existe
            if (!self::tableExists(_DB_PREFIX_ . 'chrono_cart_relais')) {
                return;
            }

            // 1. Vérifier que l'id_cart n'a pas déjà une ligne
            $query = new \DbQuery();
            $query->select('COUNT(*)')
                ->from('chrono_cart_relais')
                ->where('id_cart = ' . (int) $cart->id);

            if (\Db::getInstance()->getValue($query) > 0) {
                return; // Une ligne existe déjà pour ce panier
            }

            // Override marchand : prioritaire sur le clonage historique.
            // Chronopost ne stocke que l'identifiant du relais : insertion directe.
            // Toute exception est neutralisée localement pour garantir le repli
            // sur le clonage.
            try {
                $override = CiklikDeliveryOverride::get((int) $cart->id_customer, 'chronopost');
                if ($override) {
                    $result = \Db::getInstance()->insert('chrono_cart_relais', [
                        'id_cart' => (int) $cart->id,
                        'id_pr' => pSQL($override['relay_id']),
                    ]);

                    if ($result) {
                        self::logOverride('chronopost', $cart->id, 'relais override appliqué: ' . $override['relay_id'], 1);

                        return;
                    }

                    self::logOverride('chronopost', $cart->id, 'échec insertion override, repli sur le clonage');
                }
            } catch (\Exception $e) {
                self::logOverride('chronopost', $cart->id, 'exception: ' . $e->getMessage());
            }

            // 2. Trouver les commandes payées du client avec le module 'ciklik'
            $sql = 'SELECT o.id_cart FROM ' . _DB_PREFIX_ . 'orders o
                    WHERE o.id_customer = ' . (int) $cart->id_customer . '
                      AND o.module = \'ciklik\'
                      AND o.current_state IN (SELECT id_order_state FROM ' . _DB_PREFIX_ . 'order_state WHERE paid = 1)
                    ORDER BY o.date_add DESC';

            $paidCartIds = \Db::getInstance()->executeS($sql);

            if (!$paidCartIds || empty($paidCartIds)) {
                return; // Aucune commande payée trouvée
            }

            // 3. Extraire les IDs des cartes
            $cartIds = array_column($paidCartIds, 'id_cart');

            // 4. Trouver dans la table chrono_cart_relais les entrées avec ces cartes qui ont un id_pr
            $query = new \DbQuery();
            $query->select('*')
                ->from('chrono_cart_relais')
                ->where('id_cart IN (' . implode(',', array_map('intval', $cartIds)) . ')')
                ->where('id_pr IS NOT NULL AND id_pr != \'\'')
                ->orderBy('id_cart DESC');

            $existingRelais = \Db::getInstance()->getRow($query);

            if (!$existingRelais) {
                return; // Aucune ligne de relais trouvée
            }

            // 5. Cloner la ligne avec le nouveau id_cart
            $newRelais = [
                'id_cart' => (int) $cart->id,
                'id_pr' => pSQL($existingRelais['id_pr']),
            ];

            // Insérer la nouvelle ligne
            $result = \Db::getInstance()->insert('chrono_cart_relais', $newRelais);

            if ($result) {
                \PrestaShopLogger::addLog(
                    'DeliveryModuleManager::handleChronopost - Ligne clonée avec succès - Cart ID: ' . (int) $cart->id . ' - PR ID: ' . (int) $existingRelais['id_pr'],
                    1,
                    null,
                    'DeliveryModuleManager',
                    null,
                    true
                );
            }
        } catch (\Exception $e) {
            \PrestaShopLogger::addLog(
                'DeliveryModuleManager::handleChronopost - Erreur: ' . $e->getMessage() . ' - Cart ID: ' . (int) $cart->id,
                3,
                null,
                'DeliveryModuleManager',
                null,
                true
            );
        }
    }

    public static function getChronoRelaisIDs()
    {
        return [
            (int) \Configuration::get('CHRONOPOST_CHRONORELAIS_AMBIENT_ID'),
            (int) \Configuration::get('CHRONOPOST_CHRONORELAIS_ID'),
            (int) \Configuration::get('CHRONOPOST_RELAISEUROPE_ID'),
            (int) \Configuration::get('CHRONOPOST_RELAISDOM_ID'),
        ];
    }

    public static function isRelais($idCarrier)
    {
        $carrier = new \Carrier($idCarrier);

        return in_array($carrier->id_reference, self::getChronoRelaisIDs());
    }
}
