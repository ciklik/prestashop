<?php

/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Managers;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CiklikSpecificPrice
{
    /**
     * Crée un prix spécifique pour une fréquence d'abonnement
     *
     * @param int $idProduct ID du produit
     * @param int $idProductAttribute ID de l'attribut produit
     * @param \Cart $cart Panier
     * @param array $frequency Données de la fréquence avec discount_percent et discount_price
     * @param int|null $idCustomer ID du client (null pour les invités)
     * @param int|null $idGuest ID de l'invité (null pour les clients connectés)
     *
     * @return bool True si le prix spécifique a été créé avec succès
     */
    public static function createForFrequency(
        int $idProduct,
        int $idProductAttribute,
        \Cart $cart,
        array $frequency
    ): bool {
        try {
            // Log initial
            \PrestaShopLogger::addLog(
                'CiklikSpecificPrice::createForFrequency started - Product: ' . $idProduct . ', Cart: ' . $cart->id,
                1,
                null,
                'ciklik',
                $idProduct,
            );

            // Vérifier si un prix spécifique existe déjà
            if (self::exists($idProduct, $idProductAttribute, $cart->id, $cart->id_customer)) {
                \PrestaShopLogger::addLog(
                    'Specific price already exists for this product/cart combination',
                    1,
                    null,
                    'ciklik',
                    $idProduct,
                );

                return false;
            }

            $specificPrice = new \SpecificPrice();
            $specificPrice->id_product = $idProduct;
            $specificPrice->id_product_attribute = $idProductAttribute;
            $specificPrice->id_customer = $cart->id_customer ? $cart->id_customer : 0;
            $specificPrice->id_cart = $cart->id;
            $specificPrice->id_shop = $cart->id_shop;
            $specificPrice->id_currency = $cart->id_currency;
            $specificPrice->id_country = $cart->id_address_delivery ?
                (new \Address($cart->id_address_delivery))->id_country : 0;
            $specificPrice->id_group = 0;

            $priceBase = \Configuration::get('CIKLIK_FREQUENCY_PRICE_BASE');

            if ($priceBase === 'net') {
                // Mode net : calcul du prix final HT après réductions existantes
                $basePriceHT = \Product::getPriceStatic(
                    $idProduct,
                    false,
                    $idProductAttribute,
                    6,
                    null,
                    false,
                    true,
                    1,
                    false,
                    $cart->id_customer ?: null,
                    $cart->id,
                );

                $finalPriceHT = self::computeNetPrice($basePriceHT, $frequency);

                if ($finalPriceHT === false) {
                    \PrestaShopLogger::addLog(
                        'Net mode: no valid discount or invalid base price (base=' . $basePriceHT . ')',
                        2,
                        null,
                        'ciklik',
                        $idProduct,
                    );

                    return false;
                }

                $specificPrice->price = $finalPriceHT;
                $specificPrice->reduction = 0;
                $specificPrice->reduction_type = 'amount';

                \PrestaShopLogger::addLog(
                    'Net mode: base=' . $basePriceHT . ', final=' . $finalPriceHT,
                    1,
                    null,
                    'ciklik',
                    $idProduct,
                );
            } else {
                // Mode gross : comportement par défaut, réduction sur prix brut catalogue
                if (!empty($frequency['discount_price']) && (float) $frequency['discount_price'] > 0) {
                    $specificPrice->reduction_type = 'amount';
                    $specificPrice->reduction = (float) $frequency['discount_price'];
                    \PrestaShopLogger::addLog(
                        'Using amount reduction: ' . $frequency['discount_price'],
                        1,
                        null,
                        'ciklik',
                        $idProduct,
                    );
                } elseif (!empty($frequency['discount_percent']) && (float) $frequency['discount_percent'] > 0) {
                    $specificPrice->reduction_type = 'percentage';
                    $specificPrice->reduction = (float) $frequency['discount_percent'] / 100;
                    \PrestaShopLogger::addLog(
                        'Using percentage reduction: ' . $frequency['discount_percent'] . '%',
                        1,
                        null,
                        'ciklik',
                        $idProduct,
                    );
                } else {
                    \PrestaShopLogger::addLog(
                        'No valid discount found in frequency data',
                        2,
                        null,
                        'ciklik',
                        $idProduct,
                    );

                    return false;
                }

                $specificPrice->price = -1; // Utilise le prix du produit comme base
            }

            $specificPrice->from_quantity = 1;
            $specificPrice->from = date('Y-m-d H:i:s', strtotime('-1 day'));
            $specificPrice->to = date('Y-m-d H:i:s', strtotime('+1 year'));

            \PrestaShopLogger::addLog(
                'Attempting to save specific price with data: ' . json_encode([
                    'id_product' => $specificPrice->id_product,
                    'id_product_attribute' => $specificPrice->id_product_attribute,
                    'id_customer' => $specificPrice->id_customer,
                    'id_cart' => $specificPrice->id_cart,
                    'reduction_type' => $specificPrice->reduction_type,
                    'reduction' => $specificPrice->reduction,
                ]),
                1,
                null,
                'ciklik',
                $idProduct,
            );

            $result = $specificPrice->add();

            if ($result) {
                \PrestaShopLogger::addLog(
                    'Specific price created successfully with ID: ' . $specificPrice->id,
                    1,
                    null,
                    'ciklik',
                    $idProduct,
                );

                return true;
            }
            \PrestaShopLogger::addLog(
                'Failed to save specific price to database',
                3,
                null,
                'ciklik',
                $idProduct,
            );

            return false;
        } catch (\Exception $e) {
            \PrestaShopLogger::addLog(
                'Error creating specific price for frequency: ' . $e->getMessage(),
                3,
                null,
                'ciklik',
                $idProduct,
            );

            return false;
        }
    }

    /**
     * Calcule le prix final HT en mode net
     *
     * @param float $basePriceHT Prix HT après réductions existantes
     * @param array $frequency Données de la fréquence
     *
     * @return float|false Prix final HT ou false si pas de réduction
     */
    public static function computeNetPrice($basePriceHT, array $frequency)
    {
        if ($basePriceHT <= 0) {
            return false;
        }

        if (!empty($frequency['discount_price']) && (float) $frequency['discount_price'] > 0) {
            return round(max(0, $basePriceHT - (float) $frequency['discount_price']), 6);
        }

        if (!empty($frequency['discount_percent']) && (float) $frequency['discount_percent'] > 0) {
            return round($basePriceHT * (1 - ((float) $frequency['discount_percent'] / 100)), 6);
        }

        return false;
    }

    /**
     * Supprime les prix spécifiques pour un produit et/ou panier donné
     *
     * @param int $idProduct ID du produit
     * @param int|null $idProductAttribute ID de l'attribut produit (optionnel)
     * @param int|null $idCart ID du panier (optionnel)
     * @param int|null $idCustomer ID du client (optionnel)
     * @param int|null $idGuest ID de l'invité (optionnel)
     *
     * @return bool True si au moins un prix spécifique a été supprimé
     */
    public static function remove(
        int $idProduct,
        ?int $idProductAttribute = null,
        ?int $idCart = null,
        ?int $idCustomer = null,
        ?int $idGuest = null
    ): bool {
        $query = new \DbQuery();
        $query->select('id_specific_price');
        $query->from('specific_price');
        $query->where('id_product = ' . (int) $idProduct);

        if ($idProductAttribute !== null) {
            $query->where('id_product_attribute = ' . (int) $idProductAttribute);
        }

        if ($idCart !== null) {
            $query->where('id_cart = ' . (int) $idCart);
        }

        if ($idCustomer !== null) {
            $query->where('id_customer = ' . (int) $idCustomer);
        }

        if ($idGuest !== null) {
            // Pour les invités, on cherche les prix spécifiques avec id_customer = 0
            $query->where('id_customer = 0');
        }

        $specificPrices = \Db::getInstance()->executeS($query);
        $removed = false;

        foreach ($specificPrices as $specificPriceData) {
            try {
                $specificPrice = new \SpecificPrice($specificPriceData['id_specific_price']);
                if ($specificPrice->delete()) {
                    $removed = true;
                }
            } catch (\Exception $e) {
                \PrestaShopLogger::addLog(
                    'Error removing specific price: ' . $e->getMessage(),
                    3,
                    null,
                    'CiklikSpecificPrice',
                    $idProduct,
                );
            }
        }

        return $removed;
    }

    /**
     * Transfère les prix spécifiques d'un invité vers un client connecté
     *
     * @param int $idCart ID du panier
     * @param int $idCustomer ID du nouveau client
     * @param int $idGuest ID de l'ancien invité
     *
     * @return bool True si le transfert a été effectué avec succès
     */
    public static function transferFromGuestToCustomer(
        int $idCart,
        int $idCustomer
    ): bool {
        $query = new \DbQuery();
        $query->select('*');
        $query->from('specific_price');
        $query->where('id_cart = ' . (int) $idCart);
        $query->where('id_customer = 0'); // Prix spécifiques pour les invités

        $specificPrices = \Db::getInstance()->executeS($query);
        $transferred = false;

        if ($specificPrices) {
            foreach ($specificPrices as $specificPriceData) {
                try {
                    // Créer un nouveau prix spécifique pour le client
                    $newSpecificPrice = new \SpecificPrice();
                    $newSpecificPrice->id_product = $specificPriceData['id_product'];
                    $newSpecificPrice->id_product_attribute = $specificPriceData['id_product_attribute'];
                    $newSpecificPrice->id_customer = $idCustomer;
                    $newSpecificPrice->id_cart = $specificPriceData['id_cart'];
                    $newSpecificPrice->id_shop = $specificPriceData['id_shop'];
                    $newSpecificPrice->id_currency = $specificPriceData['id_currency'];
                    $newSpecificPrice->id_country = $specificPriceData['id_country'];
                    $newSpecificPrice->id_group = 0;
                    $newSpecificPrice->reduction_type = $specificPriceData['reduction_type'];
                    $newSpecificPrice->reduction = $specificPriceData['reduction'];
                    $newSpecificPrice->from_quantity = $specificPriceData['from_quantity'];
                    $newSpecificPrice->price = $specificPriceData['price'];
                    $newSpecificPrice->reduction_tax = $specificPriceData['reduction_tax'];
                    $newSpecificPrice->from = $specificPriceData['from'];
                    $newSpecificPrice->to = $specificPriceData['to'];

                    if ($newSpecificPrice->add()) {
                        // Supprimer l'ancien prix spécifique
                        $oldSpecificPrice = new \SpecificPrice($specificPriceData['id_specific_price']);
                        $oldSpecificPrice->delete();
                        $transferred = true;
                    }
                } catch (\Exception $e) {
                    \PrestaShopLogger::addLog(
                        'Error transferring specific price from guest to customer: ' . $e->getMessage(),
                        3,
                        null,
                        'CiklikSpecificPrice',
                        $specificPriceData['id_product'],
                    );
                }
            }
        }

        return $transferred;
    }

    /**
     * Vérifie si un prix spécifique existe pour les paramètres donnés
     *
     * @param int $idProduct ID du produit
     * @param int $idProductAttribute ID de l'attribut produit
     * @param int $idCart ID du panier
     * @param int|null $idCustomer ID du client (null pour les invités)
     *
     * @return bool True si un prix spécifique existe
     */
    public static function exists(
        int $idProduct,
        int $idProductAttribute,
        int $idCart,
        ?int $idCustomer = null
    ): bool {
        $query = new \DbQuery();
        $query->select('COUNT(*)');
        $query->from('specific_price');
        $query->where('id_product = ' . (int) $idProduct);
        $query->where('id_product_attribute = ' . (int) $idProductAttribute);
        $query->where('id_cart = ' . (int) $idCart);
        $query->where('id_customer = ' . (int) ($idCustomer ?: 0));

        return (int) \Db::getInstance()->getValue($query) > 0;
    }

    /**
     * Nettoie les prix spécifiques obsolètes (plus anciens que X jours)
     *
     * @param int $daysOld Nombre de jours (par défaut 30)
     *
     * @return int Nombre de prix spécifiques supprimés
     */
    public static function cleanup(int $daysOld = 30): int
    {
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$daysOld} days"));

        $query = new \DbQuery();
        $query->select('id_specific_price');
        $query->from('specific_price');
        $query->where('from < "' . pSQL($cutoffDate) . '"');
        $query->where('id_cart > 0'); // Seulement les prix spécifiques liés à des paniers

        $specificPrices = \Db::getInstance()->executeS($query);
        $deleted = 0;

        foreach ($specificPrices as $specificPriceData) {
            try {
                $specificPrice = new \SpecificPrice($specificPriceData['id_specific_price']);
                if ($specificPrice->delete()) {
                    ++$deleted;
                }
            } catch (\Exception $e) {
                \PrestaShopLogger::addLog(
                    'Error during specific price cleanup: ' . $e->getMessage(),
                    3,
                    null,
                    'CiklikSpecificPrice',
                );
            }
        }

        return $deleted;
    }
}
