<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Managers;

use PrestaShop\Module\Ciklik\Helpers\CategoryFrequencyPlan;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Activation de fréquences par catégorie (mode fréquences) : lecture du catalogue, aperçu et
 * ajout des associations manquantes dans ciklik_product_frequency. Aucun retrait.
 */
class CiklikCategoryFrequencyAssigner
{
    /** Couples insérés par requête */
    const INSERT_BATCH = 500;

    /**
     * Catégories de la boutique du contexte, dans l'ordre de l'arbre (racine exclue)
     *
     * @return array id_category, name, level_depth, indent
     */
    public static function getCategories(int $idLang, int $idShop): array
    {
        $query = new \DbQuery();
        $query->select('c.id_category, c.level_depth, cl.name');
        $query->from('category', 'c');
        $query->innerJoin('category_shop', 'cs', 'cs.id_category = c.id_category AND cs.id_shop = ' . $idShop);
        $query->innerJoin('category_lang', 'cl', 'cl.id_category = c.id_category AND cl.id_lang = ' . $idLang . ' AND cl.id_shop = ' . $idShop);
        $query->where('c.id_parent > 0');
        $query->orderBy('c.nleft');

        $categories = \Db::getInstance()->executeS($query) ?: [];
        foreach ($categories as &$category) {
            // Indentation par espaces insécables, affichée telle quelle dans la liste déroulante
            $category['indent'] = str_repeat("\u{00A0}\u{00A0}", max(0, (int) $category['level_depth'] - 1));
        }

        return $categories;
    }

    /**
     * Calcule l'aperçu sans rien écrire
     *
     * @param int[] $frequencyIds
     *
     * @throws \InvalidArgumentException Catégorie ou fréquence inconnue
     */
    public static function preview(int $idCategory, bool $withChildren, array $frequencyIds, int $idLang, int $idShop): array
    {
        $root = \Db::getInstance()->getRow('SELECT nleft, nright FROM `' . _DB_PREFIX_ . 'category` WHERE id_category = ' . $idCategory);
        if (!$root) {
            throw new \InvalidArgumentException('unknown_category');
        }

        $products = self::findProducts($idCategory, $withChildren, (int) $root['nleft'], (int) $root['nright'], $idLang, $idShop);

        $knownFrequencyIds = array_column(\Db::getInstance()->executeS('SELECT id_frequency FROM `' . _DB_PREFIX_ . 'ciklik_frequency`') ?: [], 'id_frequency');

        $existingPairs = [];
        $ids = array_map('intval', array_column($products, 'id_product'));
        if ($ids) {
            $rows = \Db::getInstance()->executeS(
                'SELECT id_product, id_frequency FROM `' . _DB_PREFIX_ . 'ciklik_product_frequency` WHERE id_product IN (' . implode(',', $ids) . ')'
            ) ?: [];
            foreach ($rows as $row) {
                $existingPairs[(int) $row['id_product']][] = (int) $row['id_frequency'];
            }
        }

        return CategoryFrequencyPlan::build($products, $frequencyIds, $knownFrequencyIds, $existingPairs);
    }

    /**
     * Ajoute les associations de l'aperçu, en une transaction
     *
     * @param array $plan Résultat de preview()
     *
     * @return int Nombre de lignes ajoutées
     *
     * @throws \Throwable Échec d'écriture (transaction annulée)
     */
    public static function apply(array $plan): int
    {
        $db = \Db::getInstance();
        $db->execute('START TRANSACTION');

        try {
            foreach (array_chunk($plan['pairs'], self::INSERT_BATCH) as $chunk) {
                $values = [];
                foreach ($chunk as $pair) {
                    $values[] = '(' . (int) $pair[0] . ', ' . (int) $pair[1] . ')';
                }
                // INSERT IGNORE : la clé primaire composite rend l'ajout idempotent
                if (!$db->execute('INSERT IGNORE INTO `' . _DB_PREFIX_ . 'ciklik_product_frequency` (id_product, id_frequency) VALUES ' . implode(',', $values))) {
                    throw new \RuntimeException('insert_failed');
                }
            }
            $db->execute('COMMIT');
        } catch (\Throwable $e) {
            $db->execute('ROLLBACK');
            throw $e;
        }

        return count($plan['pairs']);
    }

    /**
     * Produits de la catégorie (principale ou associée), avec et sans sous-catégories,
     * exclusions comprises pour pouvoir les compter
     */
    private static function findProducts(int $idCategory, bool $withChildren, int $nleft, int $nright, int $idLang, int $idShop): array
    {
        $inTree = $withChildren
            ? '((c.nleft >= ' . $nleft . ' AND c.nright <= ' . $nright . ') OR (cd.nleft >= ' . $nleft . ' AND cd.nright <= ' . $nright . '))'
            : '(c.id_category = ' . $idCategory . ' OR p.id_category_default = ' . $idCategory . ')';

        // Multiboutique : l'état actif est celui de la boutique du contexte
        $multishop = \Shop::isFeatureActive();

        return \Db::getInstance()->executeS(
            'SELECT DISTINCT p.id_product, pl.name, p.cache_is_pack AS is_pack, p.is_virtual, '
            . ($multishop ? 'ps.active' : 'p.active') . ' AS active
            FROM `' . _DB_PREFIX_ . 'product` p
            ' . ($multishop ? 'INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.id_product = p.id_product AND ps.id_shop = ' . $idShop : '') . '
            LEFT JOIN `' . _DB_PREFIX_ . 'category_product` cp ON cp.id_product = p.id_product
            LEFT JOIN `' . _DB_PREFIX_ . 'category` c ON c.id_category = cp.id_category
            LEFT JOIN `' . _DB_PREFIX_ . 'category` cd ON cd.id_category = p.id_category_default
            LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON pl.id_product = p.id_product AND pl.id_lang = ' . $idLang . ' AND pl.id_shop = ' . $idShop . '
            WHERE ' . $inTree . '
            ORDER BY p.id_product'
        ) ?: [];
    }
}
