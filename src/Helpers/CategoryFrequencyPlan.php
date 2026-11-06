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
 * Aperçu de l'activation de fréquences sur les produits d'une catégorie (mode fréquences).
 *
 * Calcul pur, sans base : à partir des produits trouvés dans la catégorie, des fréquences
 * choisies et des associations déjà présentes, il dit ce qui sera exclu, ajouté ou laissé
 * tel quel. On ajoute seulement, on ne retire jamais rien.
 */
class CategoryFrequencyPlan
{
    /** Nombre de noms de produits montrés dans l'aperçu */
    const PREVIEW_NAMES = 20;

    /**
     * @param array $products Lignes id_product, name, is_pack, is_virtual, active (tous les produits de la catégorie)
     * @param int[] $frequencyIds Fréquences choisies
     * @param int[] $knownFrequencyIds Fréquences existantes (ciklik_frequency)
     * @param array $existingPairs id_product => liste des id_frequency déjà associés
     *
     * @return array found, excluded (pack, virtual, inactive), updated, unchanged, pairs, rows, names, more, fingerprint
     *
     * @throws \InvalidArgumentException Aucune fréquence choisie, ou fréquence inconnue
     */
    public static function build(array $products, array $frequencyIds, array $knownFrequencyIds, array $existingPairs): array
    {
        $frequencyIds = array_values(array_unique(array_map('intval', $frequencyIds)));
        if (!$frequencyIds) {
            throw new \InvalidArgumentException('no_frequency');
        }
        if (array_diff($frequencyIds, array_map('intval', $knownFrequencyIds))) {
            throw new \InvalidArgumentException('unknown_frequency');
        }
        sort($frequencyIds);

        $excluded = ['pack' => 0, 'virtual' => 0, 'inactive' => 0];
        $pairs = [];
        $names = [];
        $updated = 0;
        $unchanged = 0;

        foreach ($products as $product) {
            // Un produit n'est compté que sous le premier motif d'exclusion qui le concerne
            if ((int) $product['is_pack']) {
                ++$excluded['pack'];
                continue;
            }
            if ((int) $product['is_virtual']) {
                ++$excluded['virtual'];
                continue;
            }
            if (!(int) $product['active']) {
                ++$excluded['inactive'];
                continue;
            }

            $idProduct = (int) $product['id_product'];
            $existing = isset($existingPairs[$idProduct]) ? array_map('intval', $existingPairs[$idProduct]) : [];
            $missing = array_diff($frequencyIds, $existing);

            if (!$missing) {
                ++$unchanged;
                continue;
            }

            ++$updated;
            foreach ($missing as $idFrequency) {
                $pairs[] = [$idProduct, $idFrequency];
            }
            if (count($names) < self::PREVIEW_NAMES) {
                $names[] = (string) $product['name'];
            }
        }

        return [
            'found' => count($products),
            'excluded' => $excluded,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'pairs' => $pairs,
            'rows' => count($pairs),
            'names' => $names,
            'more' => $updated - count($names),
            // Empreinte de l'aperçu : l'application n'écrit rien si le catalogue a changé depuis
            'fingerprint' => md5(json_encode($pairs)),
        ];
    }
}
