<?php

/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Helpers;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Agrégation des besoins produits pour la prévision de stock.
 *
 * Traite les données brutes d'abonnements Ciklik pour :
 * - Filtrer par plage de date next_billing
 * - Agréger les quantités par produit/déclinaison
 * - Enrichir avec les données de stock PrestaShop
 */
class StockForecastAggregator
{
    /**
     * Filtre les abonnements actifs dont le next_billing est dans la plage donnée
     *
     * @param array $subscriptions Données brutes des abonnements (API)
     * @param string $dateFrom Date de début (format Y-m-d)
     * @param string $dateTo Date de fin (format Y-m-d)
     *
     * @return array Abonnements filtrés
     */
    public static function filterByDateRange(array $subscriptions, $dateFrom, $dateTo)
    {
        $result = [];

        foreach ($subscriptions as $subscription) {
            if (empty($subscription['active'])) {
                continue;
            }

            if (empty($subscription['next_billing'])) {
                continue;
            }

            // Extraire la date (ignorer l'heure)
            $nextBilling = substr($subscription['next_billing'], 0, 10);

            if ($nextBilling >= $dateFrom && $nextBilling <= $dateTo) {
                $result[] = $subscription;
            }
        }

        return $result;
    }

    /**
     * Agrège les quantités produits depuis un tableau d'abonnements bruts
     *
     * @param array $subscriptions Données brutes des abonnements
     * @param bool $isFrequencyMode Mode fréquence activé
     *
     * @return array Besoins agrégés ['id_product:id_product_attribute' => ['id_product' => int, 'id_product_attribute' => int, 'quantity' => int]]
     */
    public static function aggregateFromSubscriptions(array $subscriptions, $isFrequencyMode)
    {
        $needs = [];

        foreach ($subscriptions as $subscription) {
            if (empty($subscription['content']) || !is_array($subscription['content'])) {
                continue;
            }

            foreach ($subscription['content'] as $item) {
                if (empty($item['external_id'])) {
                    continue;
                }

                $ids = ProductIdentifier::extract($item['external_id'], $isFrequencyMode);
                if ($ids === null) {
                    continue;
                }

                $key = $ids['id_product'] . ':' . $ids['id_product_attribute'];
                $quantity = isset($item['quantity']) ? (int) $item['quantity'] : 1;

                if (!isset($needs[$key])) {
                    $needs[$key] = [
                        'id_product' => $ids['id_product'],
                        'id_product_attribute' => $ids['id_product_attribute'],
                        'quantity' => 0,
                    ];
                }

                $needs[$key]['quantity'] += $quantity;
            }
        }

        // Résoudre les id_product manquants (mode attributs)
        if (!$isFrequencyMode) {
            $needs = self::resolveAndRekey($needs);
        }

        return $needs;
    }

    /**
     * Enrichit les besoins avec les données de stock PrestaShop
     *
     * @param array $needs Besoins agrégés (sortie de aggregateFromSubscriptions)
     *
     * @return array Besoins enrichis avec stock, nom produit, alerte
     */
    public static function enrichWithStockData(array $needs)
    {
        $idLang = (int) \Configuration::get('PS_LANG_DEFAULT');

        foreach ($needs as &$need) {
            // Stock actuel
            $stock = (int) \StockAvailable::getQuantityAvailableByProduct(
                $need['id_product'],
                $need['id_product_attribute']
            );
            $need['current_stock'] = $stock;
            $need['stock_after'] = $stock - $need['quantity'];
            $need['alert'] = $need['stock_after'] < 0;

            // Nom produit
            $product = new \Product($need['id_product'], false, $idLang);
            $need['product_name'] = $product->name ?: ('Product #' . $need['id_product']);

            // Nom combinaison
            $need['combination_name'] = '';
            if ($need['id_product_attribute'] > 0) {
                $combination = new \Combination($need['id_product_attribute']);
                $attributes = $combination->getAttributesName($idLang);
                if (!empty($attributes)) {
                    $need['combination_name'] = implode(', ', array_column($attributes, 'name'));
                }
            }
        }
        unset($need);

        return $needs;
    }

    /**
     * Résout les id_product manquants et reconstruit les clés
     *
     * En mode attributs, l'external_id ne contient que l'id_product_attribute.
     * Cette méthode résout les id_product via la BDD et fusionne les entrées
     * qui pointeraient vers le même produit après résolution.
     *
     * @param array $needs Besoins avec id_product potentiellement à 0
     *
     * @return array Besoins avec id_product résolus et clés reconstruites
     */
    private static function resolveAndRekey(array $needs)
    {
        $items = array_values($needs);
        $resolved = ProductIdentifier::resolveProductIds($items);

        // Reconstruire les clés après résolution (fusion si même produit)
        $result = [];
        foreach ($resolved as $item) {
            $key = $item['id_product'] . ':' . $item['id_product_attribute'];
            if (isset($result[$key])) {
                $result[$key]['quantity'] += $item['quantity'];
            } else {
                $result[$key] = $item;
            }
        }

        return $result;
    }
}
