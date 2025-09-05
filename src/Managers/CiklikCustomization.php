<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Managers;

use Order;
use Cart;
use Db;
use PrestaShopLogger;
use Product;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CiklikCustomization
{

    /**
     * Récupère les données de customization détaillées depuis un panier
     * Inclut les champs de customization et les fichiers uploadés
     * 
     * @param Cart $cart
     * @return array
     */
    public static function getDetailedCustomizationDataFromCart(Cart $cart)
    {
        try {
            // Retourne la structure native PrestaShop
            $data = Product::getAllCustomizedDatas($cart->id, null, true, $cart->id_shop);
            
            if ($data === false) {
                return [];
            }
            return $data;
        } catch (\Exception $e) {
            PrestaShopLogger::addLog(
                'CiklikCustomization::getDetailedCustomizationDataFromCart - Erreur: ' . $e->getMessage() . ' - Cart ID: ' . $cart->id,
                3,
                null,
                'CiklikCustomization',
                $cart->id,
                true
            );
            return [];
        }
    }

    /**
     * Récupère les données de customization détaillées depuis une commande
     * Inclut les champs de customization et les fichiers uploadés
     * 
     * @param Order $order
     * @return array
     */
    public static function getDetailedCustomizationDataFromOrder(Order $order)
    {
        $idCart = (int)$order->id_cart;
        if (!$idCart) {
            return [];
        }
        return self::getDetailedCustomizationDataFromCart(new Cart($idCart));
    }


    /**
     * Applique un champ de customization
     * 
     * @param Cart $cart
     * @param int $idProduct
     * @param int $idProductAttribute
     * @param array $field
     * @return bool
     */
    public static function applyCustomizationField(Cart $cart, int $idProduct, int $idProductAttribute, array $field)
    {
        // Retrouver ou créer l'id_customization pour ce produit dans ce panier
        $idCustomization = Db::getInstance()->getValue(
            'SELECT id_customization FROM '._DB_PREFIX_.'customization
             WHERE id_cart = '.(int)$cart->id.'
               AND id_product = '.(int)$idProduct.'
               AND id_product_attribute = '.(int)$idProductAttribute
        );
        if (!$idCustomization) {
            Db::getInstance()->insert('customization', [
                'id_cart' => (int)$cart->id,
                'id_product' => (int)$idProduct,
                'id_product_attribute' => (int)$idProductAttribute,
                'id_address_delivery' => (int)$cart->id_address_delivery,
                'quantity' => 1,
                'in_cart' => 1,
            ]);
            $idCustomization = Db::getInstance()->Insert_ID();
        }
        return self::applyCustomizationFieldById($idCustomization, $field);
    }

    /**
     * Applique un fichier de customization
     * Note: Pour les fichiers, on ne peut que copier les métadonnées
     * Le fichier physique devra être géré séparément si nécessaire
     * 
     * @param Cart $cart
     * @param int $idProduct
     * @param int $idProductAttribute
     * @param array $file
     * @return bool
     */
    public static function applyCustomizationFile(Cart $cart, int $idProduct, int $idProductAttribute, array $file)
    {
        // Retrouver ou créer l'id_customization pour ce produit dans ce panier
        $idCustomization = Db::getInstance()->getValue(
            'SELECT id_customization FROM '._DB_PREFIX_.'customization
             WHERE id_cart = '.(int)$cart->id.'
               AND id_product = '.(int)$idProduct.'
               AND id_product_attribute = '.(int)$idProductAttribute
        );
        if (!$idCustomization) {
            Db::getInstance()->insert('customization', [
                'id_cart' => (int)$cart->id,
                'id_product' => (int)$idProduct,
                'id_product_attribute' => (int)$idProductAttribute,
                'id_address_delivery' => (int)$cart->id_address_delivery,
                'quantity' => 1,
                'in_cart' => 1,
            ]);
            $idCustomization = Db::getInstance()->Insert_ID();
        }
        return self::applyCustomizationFileById($idCustomization, $file);
    }

    // Version privée utilisée quand on a déjà l'id_customization
    private static function applyCustomizationFieldById($idCustomization, array $field)
    {
        try {
            $existing = Db::getInstance()->getValue(
                'SELECT id_customization FROM '._DB_PREFIX_.'customized_data
                 WHERE id_customization = '.(int)$idCustomization.'
                   AND `index` = '.(int)$field['index'].'
                   AND type = 1'
            );
            if ($existing) {
                Db::getInstance()->update(
                    'customized_data',
                    [
                        'value' => pSQL($field['value'])
                    ],
                    'id_customization = '.(int)$idCustomization.' AND `index` = '.(int)$field['index'].' AND type = 1'
                );
            } else {
                Db::getInstance()->insert('customized_data', [
                    'id_customization' => (int)$idCustomization,
                    'type' => 1, // Champ texte
                    'index' => (int)$field['index'],
                    'value' => pSQL($field['value']),
                ]);
            }
            return true;
        } catch (\Exception $e) {
            \PrestaShopLogger::addLog(
                'CiklikCustomization::applyCustomizationFieldById - Erreur: ' . $e->getMessage(),
                3,
                null,
                'CiklikCustomization',
                $idCustomization,
                true
            );
            return false;
        }
    }

    private static function applyCustomizationFileById($idCustomization, array $file)
    {
        try {
            $existing = Db::getInstance()->getValue(
                'SELECT id_customization FROM '._DB_PREFIX_.'customized_data
                 WHERE id_customization = '.(int)$idCustomization.'
                   AND `index` = '.(int)$file['index'].'
                   AND type = 0'
            );
            if ($existing) {
                Db::getInstance()->update(
                    'customized_data',
                    [
                        'value' => pSQL($file['value'])
                    ],
                    'id_customization = '.(int)$idCustomization.' AND `index` = '.(int)$file['index'].' AND type = 0'
                );
            } else {
                Db::getInstance()->insert('customized_data', [
                    'id_customization' => (int)$idCustomization,
                    'type' => 0, // Fichier
                    'index' => (int)$file['index'],
                    'value' => pSQL($file['value']),
                ]);
            }
            return true;
        } catch (\Exception $e) {
            \PrestaShopLogger::addLog(
                'CiklikCustomization::applyCustomizationFileById - Erreur: ' . $e->getMessage(),
                3,
                null,
                'CiklikCustomization',
                $idCustomization,
                true
            );
            return false;
        }
    }

    /**
     * Adapte la structure native PrestaShop pour les réponses API
     * Transforme la structure complexe en une liste simple par produit
     * 
     * @param array $customizations Structure native PrestaShop
     * @return array Structure simplifiée pour API
     */
    public static function adaptForApiResponse(array $customizations)
    {
        $result = [];
        
        if (empty($customizations)) {
            return $result;
        }
        
        foreach ($customizations as $id_product => $productCustoms) {
            foreach ($productCustoms as $id_product_attribute => $attributeCustoms) {
                $productKey = $id_product . '_' . $id_product_attribute;
                $result[$productKey] = [
                    'id_product' => $id_product,
                    'id_product_attribute' => $id_product_attribute,
                    'customizations' => []
                ];
                
                foreach ($attributeCustoms as $id_address_delivery => $deliveryCustoms) {
                    foreach ($deliveryCustoms as $id_customization => $customization) {
                        if (isset($customization['datas'])) {
                            foreach ($customization['datas'] as $type => $fields) {
                                foreach ($fields as $field) {
                                    $result[$productKey]['customizations'][] = [
                                        'id_customization' => $id_customization,
                                        'id_address_delivery' => $id_address_delivery,
                                        'type' => $field['type'],
                                        'name' => $field['name'],
                                        'value' => $field['value'],
                                        'index' => $field['index'],
                                        'quantity' => $customization['quantity'],
                                    ];
                                }
                            }
                        }
                    }
                }
            }
        }
        
        return array_values($result);
    }
}