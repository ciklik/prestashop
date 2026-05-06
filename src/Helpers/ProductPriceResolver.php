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
 * Résolution des prix produit/combinaison selon le mode de calcul configuré
 * pour les réductions de fréquence (gross vs net).
 *
 * Deux responsabilités :
 *  - Construire la map {id_product_attribute => prix} pour toutes les
 *    combinaisons d'un produit, utilisée côté JS pour afficher le bon prix
 *    lors d'un changement de déclinaison sans dépendre du DOM principal.
 *  - Sérialiser cette map en JSON garanti "objet" (JSON_FORCE_OBJECT), pour
 *    que les IDs séquentiels de combinaisons ne soient pas interprétés comme
 *    un array JS et que l'accès par ID reste possible côté JavaScript.
 *
 * Les appels à Product::getPriceStatic() ne sont PAS intégrés ici pour
 * permettre de tester la classe en unit sans dépendance PrestaShop : le
 * fetcher est injecté via une callable.
 */
class ProductPriceResolver
{
    public const MODE_GROSS = 'gross';
    public const MODE_NET = 'net';

    /**
     * Normalise une valeur de mode en une des constantes MODE_*.
     *
     * @param string|null $rawMode Valeur brute de la configuration
     *
     * @return string MODE_GROSS ou MODE_NET (défaut : MODE_NET)
     */
    public static function normalizeMode($rawMode): string
    {
        return $rawMode === self::MODE_GROSS ? self::MODE_GROSS : self::MODE_NET;
    }

    /**
     * Construit la map des prix par combinaison pour un produit.
     *
     * La map contient toujours l'entrée `0` (prix du produit sans
     * combinaison) en première position, suivie d'une entrée par
     * combinaison fournie.
     *
     * @param float $baseProductPrice Prix du produit sans combinaison
     *                                (déjà résolu selon le mode actif)
     * @param int[] $combinationIds Liste des id_product_attribute à résoudre
     * @param callable $priceFetcher fn(int $idProductAttribute): float
     *                               Doit retourner le prix correspondant au
     *                               mode actif (gross ou net). Appelé une
     *                               seule fois par combinaison.
     *
     * @return array<int, float> Map {id_product_attribute => prix}
     */
    public static function buildCombinationPricesMap(
        float $baseProductPrice,
        array $combinationIds,
        callable $priceFetcher
    ): array {
        $map = [0 => $baseProductPrice];

        foreach ($combinationIds as $rawId) {
            $idPa = (int) $rawId;
            if ($idPa <= 0) {
                continue;
            }
            // Ne pas ré-appeler le fetcher si déjà résolu (dédoublonnage)
            if (isset($map[$idPa])) {
                continue;
            }
            $map[$idPa] = (float) $priceFetcher($idPa);
        }

        return $map;
    }

    /**
     * Sérialise la map en JSON en garantissant un objet JavaScript.
     *
     * json_encode() produit normalement un array JSON si les clés sont
     * séquentielles à partir de 0 — ce qui casserait l'accès par id de
     * combinaison côté JS (on veut `prices[42]`, pas `prices[2]`).
     * JSON_FORCE_OBJECT contourne ce comportement.
     *
     * @param array<int, float> $combinationPrices
     *
     * @return string JSON valide, toujours un objet
     */
    public static function encodeCombinationPricesJson(array $combinationPrices): string
    {
        $encoded = json_encode($combinationPrices, JSON_FORCE_OBJECT);

        return $encoded === false ? '{}' : $encoded;
    }
}
