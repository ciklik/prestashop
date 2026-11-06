<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Managers;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * « Réaligner le stock » (mode déclinaisons) : recopie, à la demande, la quantité de chaque
 * déclinaison « Une fois » sur les déclinaisons abonnement de la même combinaison (mêmes
 * attributs hors des deux groupes Ciklik). Une taille ne reçoit jamais le stock d'une autre.
 * Aucun automatisme : PrestaShop gère ensuite le stock comme d'habitude.
 */
class CiklikStockRealigner
{
    /** Nombre de changements détaillés dans l'aperçu */
    const PREVIEW_CHANGES = 20;

    /**
     * Signature d'une combinaison : ses attributs hors groupes Ciklik, triés
     *
     * @param int[] $attributeIds Attributs de la déclinaison
     * @param int[] $ciklikAttributeIds Attributs des groupes « Type d'achat » et « Fréquence d'abonnement »
     */
    public static function signature(array $attributeIds, array $ciklikAttributeIds): string
    {
        $own = array_values(array_diff(array_map('intval', $attributeIds), array_map('intval', $ciklikAttributeIds)));
        sort($own);

        return implode('-', $own);
    }

    /**
     * Quantités à poser, sans écriture
     *
     * @param array $combinations Lignes id_product_attribute, attributes (int[]), quantity
     * @param int $oneoffAttributeId Attribut « Une fois »
     * @param int[] $frequencyAttributeIds Attributs du groupe « Fréquence d'abonnement »
     * @param int[] $ciklikAttributeIds Attributs des deux groupes Ciklik
     *
     * @return array changes (id_product_attribute, from, to), orphans (abonnements sans « Une fois » de même combinaison), unchanged
     */
    public static function plan(array $combinations, int $oneoffAttributeId, array $frequencyAttributeIds, array $ciklikAttributeIds): array
    {
        $references = [];
        foreach ($combinations as $combination) {
            if (in_array($oneoffAttributeId, $combination['attributes'], true)) {
                $signature = self::signature($combination['attributes'], $ciklikAttributeIds);
                if (!isset($references[$signature])) {
                    $references[$signature] = (int) $combination['quantity'];
                }
            }
        }

        $changes = [];
        $orphans = [];
        $unchanged = 0;
        foreach ($combinations as $combination) {
            if (!array_intersect($combination['attributes'], $frequencyAttributeIds)) {
                continue;
            }
            $signature = self::signature($combination['attributes'], $ciklikAttributeIds);
            if (!isset($references[$signature])) {
                $orphans[] = (int) $combination['id_product_attribute'];
                continue;
            }
            if ($references[$signature] === (int) $combination['quantity']) {
                ++$unchanged;
                continue;
            }
            $changes[] = [
                'id_product_attribute' => (int) $combination['id_product_attribute'],
                'from' => (int) $combination['quantity'],
                'to' => $references[$signature],
            ];
        }

        return ['changes' => $changes, 'orphans' => $orphans, 'unchanged' => $unchanged];
    }

    /**
     * Aperçu pour un produit : changements, abonnements orphelins, avec les noms des déclinaisons
     */
    public static function preview(int $idProduct, int $idLang): array
    {
        $plan = self::planFor($idProduct);

        $names = self::combinationNames($idProduct, $idLang);
        $plan['details'] = [];
        foreach (array_slice($plan['changes'], 0, self::PREVIEW_CHANGES) as $change) {
            $id = $change['id_product_attribute'];
            $plan['details'][] = $change + ['name' => isset($names[$id]) ? $names[$id] : (string) $id];
        }
        $plan['orphan_names'] = [];
        foreach ($plan['orphans'] as $id) {
            $plan['orphan_names'][] = isset($names[$id]) ? $names[$id] : (string) $id;
        }

        return $plan;
    }

    /**
     * Recopie les quantités calculées
     *
     * @return int Nombre de déclinaisons dont la quantité a été posée
     */
    public static function apply(int $idProduct): int
    {
        $plan = self::planFor($idProduct);

        foreach ($plan['changes'] as $change) {
            \StockAvailable::setQuantity($idProduct, $change['id_product_attribute'], $change['to']);
        }

        return count($plan['changes']);
    }

    private static function planFor(int $idProduct): array
    {
        return self::plan(
            self::readCombinations($idProduct),
            (int) \Configuration::get(\Ciklik::CONFIG_ONEOFF_ATTRIBUTE_ID),
            self::groupAttributeIds([(int) \Configuration::get(\Ciklik::CONFIG_FREQUENCIES_ATTRIBUTE_GROUP_ID)]),
            self::ciklikAttributeIds()
        );
    }

    /**
     * Attributs des deux groupes Ciklik
     *
     * @return int[]
     */
    public static function ciklikAttributeIds(): array
    {
        return self::groupAttributeIds([
            (int) \Configuration::get(\Ciklik::CONFIG_PURCHASE_TYPE_ATTRIBUTE_GROUP_ID),
            (int) \Configuration::get(\Ciklik::CONFIG_FREQUENCIES_ATTRIBUTE_GROUP_ID),
        ]);
    }

    /**
     * @param int[] $groupIds
     *
     * @return int[]
     */
    public static function groupAttributeIds(array $groupIds): array
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT id_attribute FROM `' . _DB_PREFIX_ . 'attribute` WHERE id_attribute_group IN (' . implode(',', array_map('intval', $groupIds)) . ')'
        ) ?: [];

        return array_map('intval', array_column($rows, 'id_attribute'));
    }

    /**
     * Déclinaisons du produit avec leurs attributs et leur quantité dans la boutique du contexte
     */
    private static function readCombinations(int $idProduct): array
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT pa.id_product_attribute, pac.id_attribute
            FROM `' . _DB_PREFIX_ . 'product_attribute` pa
            LEFT JOIN `' . _DB_PREFIX_ . 'product_attribute_combination` pac ON pac.id_product_attribute = pa.id_product_attribute
            WHERE pa.id_product = ' . $idProduct
        ) ?: [];

        $combinations = [];
        foreach ($rows as $row) {
            $id = (int) $row['id_product_attribute'];
            if (!isset($combinations[$id])) {
                $combinations[$id] = [
                    'id_product_attribute' => $id,
                    'attributes' => [],
                    'quantity' => (int) \StockAvailable::getQuantityAvailableByProduct($idProduct, $id),
                ];
            }
            if ($row['id_attribute']) {
                $combinations[$id]['attributes'][] = (int) $row['id_attribute'];
            }
        }

        return array_values($combinations);
    }

    /**
     * Libellés des déclinaisons (« Taille - M, Abonnement, Mensuel »)
     *
     * @return array id_product_attribute => libellé
     */
    private static function combinationNames(int $idProduct, int $idLang): array
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT pac.id_product_attribute, GROUP_CONCAT(al.name ORDER BY a.id_attribute_group SEPARATOR \', \') AS name
            FROM `' . _DB_PREFIX_ . 'product_attribute` pa
            INNER JOIN `' . _DB_PREFIX_ . 'product_attribute_combination` pac ON pac.id_product_attribute = pa.id_product_attribute
            INNER JOIN `' . _DB_PREFIX_ . 'attribute` a ON a.id_attribute = pac.id_attribute
            LEFT JOIN `' . _DB_PREFIX_ . 'attribute_lang` al ON al.id_attribute = a.id_attribute AND al.id_lang = ' . $idLang . '
            WHERE pa.id_product = ' . $idProduct . '
            GROUP BY pac.id_product_attribute'
        ) ?: [];

        return array_column($rows, 'name', 'id_product_attribute');
    }
}
