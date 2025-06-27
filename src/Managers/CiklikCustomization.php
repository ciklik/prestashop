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
use DbQuery;
use PrestaShopLogger;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CiklikCustomization
{
    /**
     * Récupère les données de customization basiques depuis un panier
     * 
     * @param Cart $cart
     * @return array
     */
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

    /**
     * Récupère les données de customization basiques depuis une commande
     * 
     * @param Order $order
     * @return array
     */
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
            $customizationData = [];
            foreach ($cart->getProducts() as $product) {
                $productCustomizations = self::getProductCustomizations(
                    (int) $product['id_product'],
                    (int) $product['id_product_attribute'],
                    (int) $cart->id
                );
                if (!empty($productCustomizations['fields']) || !empty($productCustomizations['files'])) {
                    $customizationData[] = [
                        'id_product' => (int) $product['id_product'],
                        'id_product_attribute' => (int) $product['id_product_attribute'],
                        'customizations' => $productCustomizations,
                    ];
                }
            }
            return $customizationData;
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
        // Pour PrestaShop, il n'y a pas de lien direct entre commande et customizations, il faut passer par le cart d'origine
        // On suppose ici que la commande a un id_cart d'origine
        $idCart = (int)$order->id_cart;
        if (!$idCart) {
            return [];
        }
        // On réutilise la logique du panier
        return self::getDetailedCustomizationDataFromCart(new Cart($idCart));
    }

    /**
     * Applique les customizations à un panier lors du rebill
     * 
     * @param Cart $cart
     * @param array $customizations
     * @return bool
     */
    public static function applyCustomizationsToCart(Cart $cart, array $customizations)
    {
        foreach ($customizations as $productCustomization) {
            $id_product = $productCustomization['id_product'];
            $id_product_attribute = $productCustomization['id_product_attribute'];
            if (isset($productCustomization['customizations']['fields']) && is_array($productCustomization['customizations']['fields'])) {
                foreach ($productCustomization['customizations']['fields'] as $field) {
                    self::applyCustomizationField($cart, $id_product, $id_product_attribute, $field);
                }
            }
            if (isset($productCustomization['customizations']['files']) && is_array($productCustomization['customizations']['files'])) {
                foreach ($productCustomization['customizations']['files'] as $file) {
                    self::applyCustomizationFile($cart, $id_product, $id_product_attribute, $file);
                }
            }
        }
        return true;
    }

    /**
     * Récupère les customizations détaillées d'un produit (panier ou commande)
     *
     * @param int $idProduct
     * @param int $idProductAttribute
     * @param int|null $idCart
     * @return array
     */
    private static function getProductCustomizations(int $idProduct, int $idProductAttribute, ?int $idCart = null)
    {
        $customizations = [
            'fields' => [],
            'files' => [],
        ];
        if (!$idCart) {
            // Pour les commandes, il faudrait retrouver le cart d'origine ou adapter la logique
            return $customizations;
        }
        $idLang = (int)\Configuration::get('PS_LANG_DEFAULT');
        // 1. Récupérer toutes les personnalisations pour ce produit dans le panier
        $customizationRows = Db::getInstance()->executeS(
            'SELECT id_customization FROM '._DB_PREFIX_.'customization
             WHERE id_cart = '.(int)$idCart.'
               AND id_product = '.(int)$idProduct.'
               AND id_product_attribute = '.(int)$idProductAttribute
        );
        if (!$customizationRows) {
            return $customizations;
        }
        // 2. Récupérer les labels des champs pour ce produit
        $fieldsLabels = [];
        $fieldsRows = Db::getInstance()->executeS(
            'SELECT cf.id_customization_field, cf.type, cf.required, cfl.name
             FROM '._DB_PREFIX_.'customization_field cf
             LEFT JOIN '._DB_PREFIX_.'customization_field_lang cfl ON cf.id_customization_field = cfl.id_customization_field AND cfl.id_lang = '.(int)$idLang.'
             WHERE cf.id_product = '.(int)$idProduct
        );
        foreach ($fieldsRows as $row) {
            $fieldsLabels[$row['id_customization_field']] = $row;
        }
        // 3. Pour chaque personnalisation, récupérer les valeurs
        foreach ($customizationRows as $row) {
            $idCustomization = (int)$row['id_customization'];
            $dataRows = Db::getInstance()->executeS(
                'SELECT type, value, `index` FROM '._DB_PREFIX_.'customized_data
                 WHERE id_customization = '.(int)$idCustomization
            );
            foreach ($dataRows as $custom) {
                // On tente de retrouver le label du champ si possible
                $fieldLabel = isset($fieldsLabels[$custom['index']]) ? $fieldsLabels[$custom['index']]['name'] : '';
                $type = $custom['type'];
                $entry = [
                    'type' => $type,
                    'value' => $custom['value'],
                    'index' => $custom['index'],
                    'name' => $fieldLabel,
                ];
                if ($type === '0') {
                    $customizations['files'][] = $entry;
                } else {
                    $customizations['fields'][] = $entry;
                }
            }
        }
        return $customizations;
    }

    /**
     * Applique les customizations à un produit spécifique dans le panier
     * 
     * @param Cart $cart
     * @param int $idProduct
     * @param int $idProductAttribute
     * @param array $customizations
     * @return bool
     */
    private static function applyProductCustomizations(Cart $cart, int $idProduct, int $idProductAttribute, array $customizations)
    {
        try {
            $idCustomization = Db::getInstance()->getValue(
                'SELECT id_customization FROM '._DB_PREFIX_.'customization
                 WHERE id_cart = '.(int)$cart->id.'
                   AND id_product = '.(int)$idProduct.'
                   AND id_product_attribute = '.(int)$idProductAttribute
            );
            if (!$idCustomization) {
                // Récupérer la quantité du produit dans le panier
                $productQuantity = 1;
                if (isset($cart) && method_exists($cart, 'getProducts')) {
                    foreach ($cart->getProducts() as $product) {
                        if ($product['id_product'] == $idProduct && $product['id_product_attribute'] == $idProductAttribute) {
                            $productQuantity = (int)$product['cart_quantity'];
                            break;
                        }
                    }
                }
                Db::getInstance()->insert('customization', [
                    'id_cart' => (int)$cart->id,
                    'id_product' => (int)$idProduct,
                    'id_product_attribute' => (int)$idProductAttribute,
                    'id_address_delivery' => (int)$cart->id_address_delivery,
                    'quantity' => $productQuantity,
                    'in_cart' => 1,
                ]);
                $idCustomization = Db::getInstance()->Insert_ID();
            }
            if (isset($customizations['fields']) && !empty($customizations['fields'])) {
                foreach ($customizations['fields'] as $field) {
                    self::applyCustomizationFieldById($idCustomization, $field);
                }
            }
            if (isset($customizations['files']) && !empty($customizations['files'])) {
                foreach ($customizations['files'] as $file) {
                    self::applyCustomizationFileById($idCustomization, $file);
                }
            }
            return true;
        } catch (\Exception $e) {
            \PrestaShopLogger::addLog(
                'CiklikCustomization::applyProductCustomizations - Erreur: ' . $e->getMessage(),
                3,
                null,
                'CiklikCustomization',
                $cart->id,
                true
            );
            return false;
        }
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
}