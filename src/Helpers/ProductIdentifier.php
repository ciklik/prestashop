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
 * Extraction des identifiants produit depuis l'external_id des abonnements Ciklik.
 *
 * Le format de l'external_id varie selon le mode du module :
 * - Mode fréquence : "id_product:id_product_attribute" (ex: "42:108")
 * - Mode fréquence avec customization : "id_product:id_product_attribute_hash" (ex: "42:108_abc123")
 * - Mode attributs : "id_product_attribute" (ex: "108")
 */
class ProductIdentifier
{
    /**
     * Extrait id_product et id_product_attribute depuis un external_id
     *
     * @param string $externalId L'identifiant externe de l'abonnement
     * @param bool $isFrequencyMode Mode fréquence (true) ou mode attributs (false)
     *
     * @return array|null ['id_product' => int, 'id_product_attribute' => int] ou null si invalide
     */
    public static function extract($externalId, $isFrequencyMode)
    {
        $externalId = trim((string) $externalId);

        if ($externalId === '') {
            return null;
        }

        if ($isFrequencyMode) {
            return self::extractFrequencyMode($externalId);
        }

        return self::extractAttributeMode($externalId);
    }

    /**
     * Résout les id_product manquants (= 0) par un SELECT batch sur product_attribute
     *
     * Utilisé en mode attributs où l'external_id ne contient que l'id_product_attribute.
     *
     * @param array $items Tableau de ['id_product' => int, 'id_product_attribute' => int]
     *
     * @return array Le même tableau avec les id_product résolus
     */
    public static function resolveProductIds(array $items)
    {
        if (empty($items)) {
            return [];
        }

        // Collecter les id_product_attribute à résoudre
        $toResolve = [];
        foreach ($items as $item) {
            if ($item['id_product'] === 0 && $item['id_product_attribute'] > 0) {
                $toResolve[] = (int) $item['id_product_attribute'];
            }
        }

        if (empty($toResolve)) {
            return $items;
        }

        // Résolution batch en une seule requête
        $ids = implode(',', array_map('intval', array_unique($toResolve)));
        $query = new \DbQuery();
        $query->select('id_product_attribute, id_product');
        $query->from('product_attribute');
        $query->where('id_product_attribute IN (' . $ids . ')');
        $results = \Db::getInstance()->executeS($query);

        // Construire le mapping id_product_attribute => id_product
        $mapping = [];
        if (is_array($results)) {
            foreach ($results as $row) {
                $mapping[(int) $row['id_product_attribute']] = (int) $row['id_product'];
            }
        }

        // Appliquer le mapping
        foreach ($items as &$item) {
            if ($item['id_product'] === 0 && isset($mapping[$item['id_product_attribute']])) {
                $item['id_product'] = $mapping[$item['id_product_attribute']];
            }
        }

        return $items;
    }

    /**
     * Extraction en mode fréquence : "id_product:id_product_attribute[_hash]"
     *
     * @param string $externalId
     *
     * @return array|null
     */
    private static function extractFrequencyMode($externalId)
    {
        $parts = explode(':', $externalId);

        if (count($parts) !== 2) {
            return null;
        }

        $idProduct = trim($parts[0]);
        $idAttributePart = trim($parts[1]);

        // Extraire l'id_product_attribute avant le hash (underscore)
        if (strpos($idAttributePart, '_') !== false) {
            $attributeParts = explode('_', $idAttributePart);
            $idAttributePart = $attributeParts[0];
        }

        if (!is_numeric($idProduct) || !is_numeric($idAttributePart)) {
            return null;
        }

        $idProduct = (int) $idProduct;
        $idProductAttribute = (int) $idAttributePart;

        if ($idProduct < 0 || $idProductAttribute < 0) {
            return null;
        }

        return [
            'id_product' => $idProduct,
            'id_product_attribute' => $idProductAttribute,
        ];
    }

    /**
     * Extraction en mode attributs : "id_product_attribute"
     *
     * @param string $externalId
     *
     * @return array|null
     */
    private static function extractAttributeMode($externalId)
    {
        $externalId = trim($externalId);

        if (!is_numeric($externalId)) {
            return null;
        }

        $idProductAttribute = (int) $externalId;

        if ($idProductAttribute < 0) {
            return null;
        }

        return [
            'id_product' => 0,
            'id_product_attribute' => $idProductAttribute,
        ];
    }
}
