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

                if (!empty($productCustomizations)) {
                    $customizationData[] = [
                        'id_product' => (int) $product['id_product'],
                        'id_product_attribute' => (int) $product['id_product_attribute'],
                        'customizations' => $productCustomizations,
                    ];
                }
            }

            PrestaShopLogger::addLog(
                'CiklikCustomization::getDetailedCustomizationDataFromCart - Customizations récupérées pour le panier ' . $cart->id,
                1,
                null,
                'CiklikCustomization',
                $cart->id,
                true
            );

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
        try {
            $customizationData = [];

            foreach ($order->getProducts() as $product) {
                $productCustomizations = self::getProductCustomizations(
                    (int) $product['id_product'],
                    (int) ($product['id_product_attribute'] ?? 0),
                    null,
                    (int) $order->id
                );

                if (!empty($productCustomizations)) {
                    $customizationData[] = [
                        'id_product' => (int) $product['id_product'],
                        'id_product_attribute' => (int) ($product['id_product_attribute'] ?? 0),
                        'customizations' => $productCustomizations,
                    ];
                }
            }

            PrestaShopLogger::addLog(
                'CiklikCustomization::getDetailedCustomizationDataFromOrder - Customizations récupérées pour la commande ' . $order->id,
                1,
                null,
                'CiklikCustomization',
                $order->id,
                true
            );

            return $customizationData;
        } catch (\Exception $e) {
            PrestaShopLogger::addLog(
                'CiklikCustomization::getDetailedCustomizationDataFromOrder - Erreur: ' . $e->getMessage() . ' - Order ID: ' . $order->id,
                3,
                null,
                'CiklikCustomization',
                $order->id,
                true
            );
            return [];
        }
    }

    /**
     * Applique les customizations à un panier lors du rebill
     * 
     * @param Cart $cart
     * @param array $customizationData
     * @return bool
     */
    public static function applyCustomizationsToCart(Cart $cart, array $customizationData)
    {
        try {
            if (empty($customizationData)) {
                return true; // Pas de customizations à appliquer
            }

            foreach ($customizationData as $productCustomization) {
                $idProduct = (int) $productCustomization['id_product'];
                $idProductAttribute = (int) $productCustomization['id_product_attribute'];
                $customizations = $productCustomization['customizations'] ?? [];

                if (empty($customizations)) {
                    continue;
                }

                // Vérifier que le produit existe dans le panier
                $cartProduct = $cart->getProductQuantity($idProduct, $idProductAttribute);
                if (!$cartProduct['quantity']) {
                    PrestaShopLogger::addLog(
                        'CiklikCustomization::applyCustomizationsToCart - Produit non trouvé dans le panier - Product: ' . $idProduct . ', Attribute: ' . $idProductAttribute,
                        2,
                        null,
                        'CiklikCustomization',
                        $cart->id,
                        true
                    );
                    continue;
                }

                // Appliquer les customizations
                $result = self::applyProductCustomizations($cart, $idProduct, $idProductAttribute, $customizations);
                
                if (!$result) {
                    PrestaShopLogger::addLog(
                        'CiklikCustomization::applyCustomizationsToCart - Échec application customizations - Product: ' . $idProduct . ', Attribute: ' . $idProductAttribute,
                        3,
                        null,
                        'CiklikCustomization',
                        $cart->id,
                        true
                    );
                }
            }

            PrestaShopLogger::addLog(
                'CiklikCustomization::applyCustomizationsToCart - Customizations appliquées avec succès au panier ' . $cart->id,
                1,
                null,
                'CiklikCustomization',
                $cart->id,
                true
            );

            return true;
        } catch (\Exception $e) {
            PrestaShopLogger::addLog(
                'CiklikCustomization::applyCustomizationsToCart - Erreur: ' . $e->getMessage() . ' - Cart ID: ' . $cart->id,
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
     * Récupère les customizations détaillées d'un produit
     * 
     * @param int $idProduct
     * @param int $idProductAttribute
     * @param int|null $idCart
     * @param int|null $idOrder
     * @return array
     */
    private static function getProductCustomizations(int $idProduct, int $idProductAttribute, ?int $idCart = null, ?int $idOrder = null)
    {
        $customizations = [];

        // Récupérer les champs de customization
        $customizationFields = self::getCustomizationFields($idProduct, $idProductAttribute, $idCart, $idOrder);
        if (!empty($customizationFields)) {
            $customizations['fields'] = $customizationFields;
        }

        // Récupérer les fichiers de customization
        $customizationFiles = self::getCustomizationFiles($idProduct, $idProductAttribute, $idCart, $idOrder);
        if (!empty($customizationFiles)) {
            $customizations['files'] = $customizationFiles;
        }

        return $customizations;
    }

    /**
     * Récupère les champs de customization
     * 
     * @param int $idProduct
     * @param int $idProductAttribute
     * @param int|null $idCart
     * @param int|null $idOrder
     * @return array
     */
    private static function getCustomizationFields(int $idProduct, int $idProductAttribute, ?int $idCart = null, ?int $idOrder = null)
    {
        try {
            $query = new DbQuery();
            $query->select('cf.id_customization_field, cf.type, cf.required, cfd.name, cfd.description, cd.value')
                ->from('customization_field', 'cf')
                ->leftJoin('customization_field_lang', 'cfd', 'cf.id_customization_field = cfd.id_customization_field')
                ->leftJoin('customization_data', 'cd', 'cf.id_customization_field = cd.id_customization_field');

            if ($idCart) {
                $query->where('cd.id_cart = ' . (int) $idCart);
            } elseif ($idOrder) {
                $query->where('cd.id_order = ' . (int) $idOrder);
            }

            $query->where('cf.id_product = ' . (int) $idProduct)
                ->where('cfd.id_lang = ' . (int) \Configuration::get('PS_LANG_DEFAULT'));

            $fields = Db::getInstance()->executeS($query);

            if (!$fields) {
                return [];
            }

            $customizationFields = [];
            foreach ($fields as $field) {
                if (!empty($field['value'])) {
                    $customizationFields[] = [
                        'id_customization_field' => (int) $field['id_customization_field'],
                        'type' => $field['type'],
                        'required' => (bool) $field['required'],
                        'name' => $field['name'],
                        'description' => $field['description'],
                        'value' => $field['value'],
                    ];
                }
            }

            return $customizationFields;
        } catch (\Exception $e) {
            PrestaShopLogger::addLog(
                'CiklikCustomization::getCustomizationFields - Erreur: ' . $e->getMessage(),
                3,
                null,
                'CiklikCustomization',
                $idProduct,
                true
            );
            return [];
        }
    }

    /**
     * Récupère les fichiers de customization
     * 
     * @param int $idProduct
     * @param int $idProductAttribute
     * @param int|null $idCart
     * @param int|null $idOrder
     * @return array
     */
    private static function getCustomizationFiles(int $idProduct, int $idProductAttribute, ?int $idCart = null, ?int $idOrder = null)
    {
        try {
            $query = new DbQuery();
            $query->select('cf.id_customization_field, cf.type, cfd.name, cfd.description, cd.value, cd.filename')
                ->from('customization_field', 'cf')
                ->leftJoin('customization_field_lang', 'cfd', 'cf.id_customization_field = cfd.id_customization_field')
                ->leftJoin('customization_data', 'cd', 'cf.id_customization_field = cd.id_customization_field');

            if ($idCart) {
                $query->where('cd.id_cart = ' . (int) $idCart);
            } elseif ($idOrder) {
                $query->where('cd.id_order = ' . (int) $idOrder);
            }

            $query->where('cf.id_product = ' . (int) $idProduct)
                ->where('cf.type = \'file\'')
                ->where('cfd.id_lang = ' . (int) \Configuration::get('PS_LANG_DEFAULT'));

            $files = Db::getInstance()->executeS($query);

            if (!$files) {
                return [];
            }

            $customizationFiles = [];
            foreach ($files as $file) {
                if (!empty($file['filename'])) {
                    $customizationFiles[] = [
                        'id_customization_field' => (int) $file['id_customization_field'],
                        'type' => $file['type'],
                        'name' => $file['name'],
                        'description' => $file['description'],
                        'filename' => $file['filename'],
                        'value' => $file['value'], // Contient le chemin du fichier
                    ];
                }
            }

            return $customizationFiles;
        } catch (\Exception $e) {
            PrestaShopLogger::addLog(
                'CiklikCustomization::getCustomizationFiles - Erreur: ' . $e->getMessage(),
                3,
                null,
                'CiklikCustomization',
                $idProduct,
                true
            );
            return [];
        }
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
            // Appliquer les champs de customization
            if (isset($customizations['fields']) && !empty($customizations['fields'])) {
                foreach ($customizations['fields'] as $field) {
                    $result = self::applyCustomizationField($cart, $idProduct, $idProductAttribute, $field);
                    if (!$result) {
                        return false;
                    }
                }
            }

            // Appliquer les fichiers de customization
            if (isset($customizations['files']) && !empty($customizations['files'])) {
                foreach ($customizations['files'] as $file) {
                    $result = self::applyCustomizationFile($cart, $idProduct, $idProductAttribute, $file);
                    if (!$result) {
                        return false;
                    }
                }
            }

            return true;
        } catch (\Exception $e) {
            PrestaShopLogger::addLog(
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
    private static function applyCustomizationField(Cart $cart, int $idProduct, int $idProductAttribute, array $field)
    {
        try {
            // Vérifier si le champ existe déjà
            $query = new DbQuery();
            $query->select('id_customization_data')
                ->from('customization_data')
                ->where('id_cart = ' . (int) $cart->id)
                ->where('id_customization_field = ' . (int) $field['id_customization_field']);

            $existingData = Db::getInstance()->getValue($query);

            if ($existingData) {
                // Mettre à jour l'existant
                $result = Db::getInstance()->update(
                    'customization_data',
                    [
                        'value' => pSQL($field['value']),
                        'date_upd' => pSQL(date('Y-m-d H:i:s')),
                    ],
                    'id_customization_data = ' . (int) $existingData
                );
            } else {
                // Créer une nouvelle entrée
                $result = Db::getInstance()->insert('customization_data', [
                    'id_cart' => (int) $cart->id,
                    'id_customization_field' => (int) $field['id_customization_field'],
                    'value' => pSQL($field['value']),
                    'date_add' => pSQL(date('Y-m-d H:i:s')),
                    'date_upd' => pSQL(date('Y-m-d H:i:s')),
                ]);
            }

            return $result;
        } catch (\Exception $e) {
            PrestaShopLogger::addLog(
                'CiklikCustomization::applyCustomizationField - Erreur: ' . $e->getMessage(),
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
    private static function applyCustomizationFile(Cart $cart, int $idProduct, int $idProductAttribute, array $file)
    {
        try {
            // Vérifier si le fichier existe déjà
            $query = new DbQuery();
            $query->select('id_customization_data')
                ->from('customization_data')
                ->where('id_cart = ' . (int) $cart->id)
                ->where('id_customization_field = ' . (int) $file['id_customization_field']);

            $existingData = Db::getInstance()->getValue($query);

            if ($existingData) {
                // Mettre à jour l'existant
                $result = Db::getInstance()->update(
                    'customization_data',
                    [
                        'value' => pSQL($file['value']),
                        'filename' => pSQL($file['filename']),
                        'date_upd' => pSQL(date('Y-m-d H:i:s')),
                    ],
                    'id_customization_data = ' . (int) $existingData
                );
            } else {
                // Créer une nouvelle entrée
                $result = Db::getInstance()->insert('customization_data', [
                    'id_cart' => (int) $cart->id,
                    'id_customization_field' => (int) $file['id_customization_field'],
                    'value' => pSQL($file['value']),
                    'filename' => pSQL($file['filename']),
                    'date_add' => pSQL(date('Y-m-d H:i:s')),
                    'date_upd' => pSQL(date('Y-m-d H:i:s')),
                ]);
            }

            return $result;
        } catch (\Exception $e) {
            PrestaShopLogger::addLog(
                'CiklikCustomization::applyCustomizationFile - Erreur: ' . $e->getMessage(),
                3,
                null,
                'CiklikCustomization',
                $cart->id,
                true
            );
            return false;
        }
    }
}