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
 * Règles de « Transformer en abonnement » (mode déclinaisons), sans base : éligibilité du produit,
 * fréquences acceptées, nombre de déclinaisons créées et plafond.
 *
 * Chaque déclinaison existante D devient « Une fois » (son id ne change pas) et reçoit une
 * déclinaison abonnement par fréquence choisie ; un produit simple reçoit d'abord une
 * déclinaison « Une fois ». La création se fait par étapes : ce qui reste à créer se déduit
 * des attributs des déclinaisons présentes, une reprise ne recrée donc jamais rien.
 */
class SubscriptionTransformPlan
{
    /** Plafond de déclinaisons par produit après transformation */
    const MAX_COMBINATIONS = 1000;

    /** Bornes acceptées par Ciklik pour une fréquence */
    const MAX_INTERVAL_COUNT = 28;
    const INTERVALS = ['day', 'week', 'month'];

    /** Motifs de refus */
    const ALREADY_SUBSCRIPTION = 'already_subscription';
    const INCOMPLETE_CONFIG = 'incomplete_config';
    const PACK = 'pack';
    const VIRTUAL = 'virtual';
    const ADVANCED_STOCK = 'advanced_stock';
    const NO_FREQUENCY = 'no_frequency';
    const UNKNOWN_FREQUENCY = 'unknown_frequency';
    const DEFAULT_FREQUENCY_MISSING = 'default_frequency_missing';
    const FREQUENCY_OUT_OF_BOUNDS = 'frequency_out_of_bounds';
    const TOO_MANY_COMBINATIONS = 'too_many_combinations';
    const JOB_RUNNING = 'job_running';
    const NO_JOB = 'no_job';
    const SHOP_CONTEXT = 'shop_context';
    const OTHER_SHOP = 'other_shop';

    /**
     * Motif pour lequel le produit ne peut pas être transformé, null s'il le peut
     *
     * @param array $product is_pack, is_virtual, depends_on_stock, has_ciklik_attributes, config_complete
     *
     * @return string|null
     */
    public static function productRefusal(array $product)
    {
        if (!empty($product['has_ciklik_attributes'])) {
            return self::ALREADY_SUBSCRIPTION;
        }
        if (empty($product['config_complete'])) {
            return self::INCOMPLETE_CONFIG;
        }
        if (!empty($product['is_pack'])) {
            return self::PACK;
        }
        if (!empty($product['is_virtual'])) {
            return self::VIRTUAL;
        }
        if (!empty($product['depends_on_stock'])) {
            return self::ADVANCED_STOCK;
        }

        return null;
    }

    /**
     * Motif pour lequel la sélection de fréquences est refusée, null si elle est acceptée
     *
     * @param int[] $selected Attributs de fréquence choisis
     * @param array $available id_attribute => [interval, interval_count] (attributs reliés à ciklik_frequencies)
     * @param int $defaultAttributeId Fréquence par défaut du module, obligatoire
     *
     * @return string|null
     */
    public static function frequencyRefusal(array $selected, array $available, int $defaultAttributeId)
    {
        if (!$selected) {
            return self::NO_FREQUENCY;
        }
        foreach ($selected as $idAttribute) {
            if (!isset($available[$idAttribute])) {
                return self::UNKNOWN_FREQUENCY;
            }
        }
        if (!in_array($defaultAttributeId, $selected, true)) {
            return self::DEFAULT_FREQUENCY_MISSING;
        }
        foreach ($selected as $idAttribute) {
            if (!self::isWithinCiklikBounds($available[$idAttribute])) {
                return self::FREQUENCY_OUT_OF_BOUNDS;
            }
        }

        return null;
    }

    /**
     * Fréquence acceptée par Ciklik (une fréquence hors bornes fait échouer tout son lot)
     */
    public static function isWithinCiklikBounds(array $frequency): bool
    {
        $count = (int) $frequency['interval_count'];

        return in_array($frequency['interval'], self::INTERVALS, true) && $count >= 1 && $count <= self::MAX_INTERVAL_COUNT;
    }

    /**
     * Chiffres de l'aperçu
     *
     * @param array $combinations id_product_attribute => quantité des déclinaisons existantes (vide : produit simple)
     * @param int $productQuantity Quantité du produit simple
     * @param int $frequencyCount Nombre de fréquences choisies
     *
     * @return array simple, oneoff, created, total, quantity_before, quantity_after, refusal
     */
    public static function summarize(array $combinations, int $productQuantity, int $frequencyCount): array
    {
        $simple = !$combinations;
        $oneoff = $simple ? 1 : count($combinations);
        $quantity = $simple ? $productQuantity : (int) array_sum($combinations);
        $total = $oneoff * (1 + $frequencyCount);

        return [
            'simple' => $simple,
            'oneoff' => $oneoff,
            'created' => $oneoff * $frequencyCount,
            'total' => $total,
            'quantity_before' => $quantity,
            // PrestaShop affiche la somme des déclinaisons : chaque stock « Une fois » est recopié par fréquence
            'quantity_after' => $quantity * (1 + $frequencyCount),
            'refusal' => $total > self::MAX_COMBINATIONS ? self::TOO_MANY_COMBINATIONS : null,
        ];
    }

    /**
     * Fréquences dans l'ordre de création : la fréquence par défaut d'abord, celle dont la fiche
     * front a besoin pour proposer l'abonnement
     *
     * @param int[] $frequencyIds
     *
     * @return int[]
     */
    public static function creationOrder(array $frequencyIds, int $defaultAttributeId): array
    {
        $others = array_values(array_diff($frequencyIds, [$defaultAttributeId]));

        return in_array($defaultAttributeId, $frequencyIds, true) ? array_merge([$defaultAttributeId], $others) : $others;
    }

    /**
     * Signature d'une déclinaison : ses attributs triés
     *
     * @param int[] $attributeIds
     */
    public static function signature(array $attributeIds): string
    {
        $ids = array_map('intval', $attributeIds);
        sort($ids);

        return implode('-', $ids);
    }

    /**
     * Déclinaisons abonnement qui restent à créer, source par source. Une déclinaison abonnement
     * existe si une déclinaison du produit porte exactement les attributs de la source, « Abonnement »
     * et la fréquence : une étape interrompue ou rejouée ne crée jamais de doublon.
     *
     * @param array $sources id_product_attribute source => ses attributs, sans « Une fois »
     * @param int[] $frequencyIds Dans l'ordre de creationOrder()
     * @param string[] $existing Signatures des déclinaisons du produit
     *
     * @return array Liste de [id_product_attribute source, id_attribute de fréquence]
     */
    public static function pendingPairs(array $sources, array $frequencyIds, int $subscriptionAttribute, array $existing): array
    {
        $existing = array_flip($existing);
        $pending = [];
        foreach ($sources as $idSource => $attributes) {
            foreach ($frequencyIds as $idFrequency) {
                if (!isset($existing[self::signature(array_merge($attributes, [$subscriptionAttribute, $idFrequency]))])) {
                    $pending[] = [(int) $idSource, (int) $idFrequency];
                }
            }
        }

        return $pending;
    }

    /**
     * Déclinaisons retirées par « Annuler la transformation » : celles qui portent « Abonnement »
     * (le produit n'en avait aucune avant) et la déclinaison « Une fois » créée pour un produit
     * simple, en dernier. Jamais une déclinaison d'origine.
     *
     * @param array $combinations id_product_attribute => attributs
     * @param int $createdOneoffId Déclinaison « Une fois » créée par la transformation, 0 sinon
     *
     * @return int[]
     */
    public static function cancelTargets(array $combinations, int $subscriptionAttribute, int $createdOneoffId): array
    {
        $targets = [];
        foreach ($combinations as $id => $attributes) {
            if (in_array($subscriptionAttribute, array_map('intval', $attributes), true)) {
                $targets[] = (int) $id;
            }
        }
        // La « Une fois » d'un produit simple part en dernier : son stock est rendu au produit
        if ($createdOneoffId && isset($combinations[$createdOneoffId])) {
            $targets[] = $createdOneoffId;
        }

        return $targets;
    }
}
