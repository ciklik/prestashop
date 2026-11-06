<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Managers;

use PrestaShop\Module\Ciklik\Helpers\SubscriptionTransformPlan;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * « Transformer en abonnement » (mode déclinaisons).
 *
 * Chaque déclinaison existante D reçoit l'attribut « Une fois » par simple insertion : jamais de
 * suppression ni de setAttributes() sur D, son id_product_attribute (référencé par les paniers
 * et les abonnements) ne change pas. Pour chaque fréquence, une déclinaison abonnement est
 * créée à l'image de D (mêmes références, impact prix, images, fournisseurs) et reçoit une copie
 * ponctuelle du stock de D. Un produit simple reçoit d'abord une déclinaison « Une fois » qui
 * reprend son stock.
 */
class CiklikSubscriptionTransformer
{
    /** Tables écrites, dont le moteur est vérifié (transaction effective en InnoDB seulement) */
    const TABLES = [
        'product_attribute',
        'product_attribute_shop',
        'product_attribute_combination',
        'product_attribute_image',
        'product_supplier',
        'stock_available',
    ];

    /**
     * État du produit pour le panneau de la fiche
     *
     * @return array refusal, has_subscription (déclinaisons abonnement présentes), frequencies, default_frequency
     */
    public static function getState(int $idProduct, int $idLang): array
    {
        $facts = self::productFacts($idProduct);

        return [
            'refusal' => SubscriptionTransformPlan::productRefusal($facts),
            'has_subscription' => $facts['has_ciklik_attributes'],
            'frequencies' => self::availableFrequencies($idLang),
            'default_frequency' => (int) \Configuration::get(\Ciklik::CONFIG_DEFAULT_SUBSCRIPTION_ATTRIBUTE_ID),
        ];
    }

    /**
     * Aperçu sans écriture
     *
     * @param int[] $frequencyIds Attributs de fréquence choisis
     *
     * @return array chiffres de SubscriptionTransformPlan::summarize() et non_innodb (tables sans transaction)
     *
     * @throws \DomainException Motif de refus (constantes de SubscriptionTransformPlan)
     */
    public static function preview(int $idProduct, array $frequencyIds): array
    {
        $summary = self::check($idProduct, self::uniqueIds($frequencyIds));

        $prefixed = array_map(function ($table) {
            return "'" . pSQL(_DB_PREFIX_ . $table) . "'";
        }, self::TABLES);
        $engines = \Db::getInstance()->executeS('SHOW TABLE STATUS WHERE Name IN (' . implode(',', $prefixed) . ')') ?: [];
        $summary['non_innodb'] = [];
        foreach ($engines as $table) {
            if ('innodb' !== strtolower((string) $table['Engine'])) {
                $summary['non_innodb'][] = $table['Name'];
            }
        }

        return $summary;
    }

    /**
     * Transforme le produit, puis l'envoie à Ciklik
     *
     * @param int[] $frequencyIds Attributs de fréquence choisis
     *
     * @return array created (déclinaisons créées), sync (résultat de l'envoi, null si l'envoi a échoué)
     *
     * @throws \DomainException Motif de refus
     * @throws \Throwable Échec de la transformation (rien n'est conservé)
     */
    public static function transform(int $idProduct, array $frequencyIds): array
    {
        $frequencyIds = self::uniqueIds($frequencyIds);
        // Des centaines de déclinaisons : chaque création déclenche les hooks des autres modules
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        return self::withLock($idProduct, function () use ($idProduct, $frequencyIds) {
            $summary = self::check($idProduct, $frequencyIds);
            $journal = ['oneoff' => [], 'created' => [], 'simple_quantity' => null];
            $db = \Db::getInstance();

            $db->execute('START TRANSACTION');
            try {
                self::create($idProduct, $frequencyIds, $summary['simple'], $journal);
                self::assertComplete($idProduct, $summary);
                if (!$db->execute('COMMIT')) {
                    throw new \RuntimeException('commit_failed');
                }
            } catch (\Throwable $e) {
                $db->execute('ROLLBACK');
                self::compensate($idProduct, $journal);
                throw $e;
            }

            \Search::indexation(false, $idProduct);
            if (method_exists('Product', 'flushPriceCache')) {
                \Product::flushPriceCache();
            }

            // L'envoi vient après la validation : un échec de l'API ne défait pas la transformation
            try {
                $sync = CiklikSubscribable::handle($idProduct);
            } catch (\Throwable $e) {
                \PrestaShopLogger::addLog('Ciklik - envoi du produit ' . $idProduct . ' après transformation échoué : ' . $e->getMessage(), 3);
                $sync = null;
            }

            // Déclinaisons abonnement seulement, sans la déclinaison « Une fois » d'un produit simple
            return ['created' => $summary['created'], 'sync' => $sync];
        });
    }

    /**
     * « Réaligner le stock » sous le même verrou que la transformation
     *
     * @return int Nombre de déclinaisons dont la quantité a été posée
     */
    public static function realignStock(int $idProduct): int
    {
        return self::withLock($idProduct, function () use ($idProduct) {
            return CiklikStockRealigner::apply($idProduct);
        });
    }

    /**
     * Vérifie le produit et les fréquences, au moment de l'aperçu comme de la transformation
     *
     * @throws \DomainException
     */
    private static function check(int $idProduct, array $frequencyIds): array
    {
        $refusal = SubscriptionTransformPlan::productRefusal(self::productFacts($idProduct));
        if (null === $refusal) {
            $available = [];
            foreach (self::availableFrequencies(0) as $frequency) {
                $available[(int) $frequency['id_attribute']] = $frequency;
            }
            $refusal = SubscriptionTransformPlan::frequencyRefusal(
                $frequencyIds,
                $available,
                (int) \Configuration::get(\Ciklik::CONFIG_DEFAULT_SUBSCRIPTION_ATTRIBUTE_ID)
            );
        }
        if (null !== $refusal) {
            throw new \DomainException($refusal);
        }

        $quantities = [];
        foreach (self::combinationIds($idProduct) as $id) {
            $quantities[$id] = (int) \StockAvailable::getQuantityAvailableByProduct($idProduct, $id);
        }
        $summary = SubscriptionTransformPlan::summarize(
            $quantities,
            (int) \StockAvailable::getQuantityAvailableByProduct($idProduct, 0),
            count($frequencyIds)
        );
        if (null !== $summary['refusal']) {
            throw new \DomainException($summary['refusal']);
        }

        return $summary;
    }

    /**
     * Écritures de la transformation, consignées dans $journal pour la compensation
     */
    private static function create(int $idProduct, array $frequencyIds, bool $simple, array &$journal)
    {
        $db = \Db::getInstance();
        $oneoffAttribute = (int) \Configuration::get(\Ciklik::CONFIG_ONEOFF_ATTRIBUTE_ID);
        $subscriptionAttribute = (int) \Configuration::get(\Ciklik::CONFIG_SUBSCRIPTION_ATTRIBUTE_ID);

        if ($simple) {
            $journal['simple_quantity'] = (int) \StockAvailable::getQuantityAvailableByProduct($idProduct, 0);
            $sources = [self::createOneoffFromProduct($idProduct, $oneoffAttribute, $journal)];
        } else {
            $sources = self::combinationIds($idProduct);
            foreach ($sources as $idSource) {
                // Insertion directe : D garde son id et ses autres attributs
                if (!$db->execute('INSERT INTO `' . _DB_PREFIX_ . 'product_attribute_combination` (id_attribute, id_product_attribute) VALUES (' . $oneoffAttribute . ', ' . $idSource . ')')) {
                    throw new \RuntimeException('oneoff_insert_failed');
                }
                $journal['oneoff'][] = $idSource;
            }
        }

        foreach ($sources as $idSource) {
            $source = new \Combination($idSource);
            $attributes = array_diff(self::column('SELECT id_attribute FROM `' . _DB_PREFIX_ . 'product_attribute_combination` WHERE id_product_attribute = ' . $idSource), [$oneoffAttribute]);
            $images = self::column('SELECT id_image FROM `' . _DB_PREFIX_ . 'product_attribute_image` WHERE id_product_attribute = ' . $idSource);
            $quantity = (int) \StockAvailable::getQuantityAvailableByProduct($idProduct, $idSource);

            foreach ($frequencyIds as $idFrequency) {
                $combination = new \Combination();
                foreach (\Combination::$definition['fields'] as $field => $unused) {
                    if (property_exists($source, $field)) {
                        $combination->$field = $source->$field;
                    }
                }
                $combination->id_product = $idProduct;
                $combination->default_on = null;
                self::addCombination($combination, $journal);

                self::ensure($combination->setAttributes(array_merge($attributes, [$subscriptionAttribute, $idFrequency])));
                self::ensure($combination->setImages($images));
                self::copySuppliers($idProduct, $idSource, (int) $combination->id);
                \StockAvailable::setQuantity($idProduct, (int) $combination->id, $quantity);
            }
        }
    }

    /**
     * Produit simple : déclinaison « Une fois » sans impact, par défaut, qui reprend les codes,
     * les images, les fournisseurs et le stock du produit (le produit garde les siens)
     */
    private static function createOneoffFromProduct(int $idProduct, int $oneoffAttribute, array &$journal): int
    {
        $product = new \Product($idProduct);
        $combination = new \Combination();
        $combination->id_product = $idProduct;
        foreach (['reference', 'ean13', 'isbn', 'upc', 'mpn', 'wholesale_price', 'minimal_quantity'] as $field) {
            if (property_exists($combination, $field) && property_exists($product, $field)) {
                $combination->$field = $product->$field;
            }
        }
        $combination->price = 0;
        $combination->weight = 0;
        $combination->unit_price_impact = 0;
        $combination->default_on = 1;
        self::addCombination($combination, $journal);

        $id = (int) $combination->id;
        self::ensure($combination->setAttributes([$oneoffAttribute]));
        self::ensure($combination->setImages(self::column('SELECT id_image FROM `' . _DB_PREFIX_ . 'image` WHERE id_product = ' . $idProduct)));
        self::copySuppliers($idProduct, 0, $id);
        \StockAvailable::setQuantity($idProduct, $id, (int) $journal['simple_quantity']);

        return $id;
    }

    private static function addCombination(\Combination $combination, array &$journal)
    {
        if (!$combination->add()) {
            throw new \RuntimeException('combination_add_failed');
        }
        $journal['created'][] = (int) $combination->id;
    }

    /**
     * Références fournisseur et prix d'achat fournisseur de la source
     */
    private static function copySuppliers(int $idProduct, int $idSource, int $idTarget)
    {
        self::ensure(\Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'product_supplier` (id_product, id_product_attribute, id_supplier, product_supplier_reference, product_supplier_price_te, id_currency)
            SELECT id_product, ' . $idTarget . ', id_supplier, product_supplier_reference, product_supplier_price_te, id_currency
            FROM `' . _DB_PREFIX_ . 'product_supplier`
            WHERE id_product = ' . $idProduct . ' AND id_product_attribute = ' . $idSource
        ));
    }

    /**
     * Une écriture refusée (blocage, interblocage) interrompt la transformation
     *
     * @param mixed $result Retour d'une écriture PrestaShop
     */
    private static function ensure($result)
    {
        if (false === $result) {
            throw new \RuntimeException('write_failed');
        }
    }

    /**
     * Contrôle avant validation : un interblocage MySQL annule toute la transaction en silence
     * (PrestaShop rend seulement false) et les écritures suivantes partent hors transaction.
     * Le compte attendu de déclinaisons et de « Une fois » le révèle.
     */
    private static function assertComplete(int $idProduct, array $summary)
    {
        $total = (int) \Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE id_product = ' . $idProduct, false);
        $oneoff = (int) \Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product_attribute` pa
            INNER JOIN `' . _DB_PREFIX_ . 'product_attribute_combination` pac ON pac.id_product_attribute = pa.id_product_attribute
            WHERE pa.id_product = ' . $idProduct . ' AND pac.id_attribute = ' . (int) \Configuration::get(\Ciklik::CONFIG_ONEOFF_ATTRIBUTE_ID),
            false
        );
        if ($total !== $summary['total'] || $oneoff !== $summary['oneoff']) {
            throw new \RuntimeException('incomplete_transformation');
        }
    }

    /**
     * Défait ce que la transformation a créé si l'annulation de la transaction n'a pas suffi
     * (table MyISAM, validation implicite par un module tiers). Les déclinaisons d'origine ne
     * sont jamais supprimées.
     */
    private static function compensate(int $idProduct, array $journal)
    {
        try {
            $db = \Db::getInstance();
            foreach (array_reverse($journal['created']) as $id) {
                if ($db->getValue('SELECT id_product_attribute FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE id_product_attribute = ' . (int) $id)) {
                    (new \Combination($id))->delete();
                }
            }
            if ($journal['oneoff']) {
                $db->execute(
                    'DELETE FROM `' . _DB_PREFIX_ . 'product_attribute_combination`
                    WHERE id_attribute = ' . (int) \Configuration::get(\Ciklik::CONFIG_ONEOFF_ATTRIBUTE_ID) . '
                    AND id_product_attribute IN (' . implode(',', array_map('intval', $journal['oneoff'])) . ')'
                );
            }
            if (null !== $journal['simple_quantity']) {
                \StockAvailable::setQuantity($idProduct, 0, $journal['simple_quantity'], null, false);
                \Product::updateDefaultAttribute($idProduct);
            }
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('Ciklik - compensation de la transformation du produit ' . $idProduct . ' incomplète : ' . $e->getMessage(), 3);
        }
    }

    /**
     * Exécute $callback sous un verrou MySQL propre au produit : un second clic ou un second
     * onglet est refusé tant que le premier n'a pas fini
     *
     * @throws \DomainException locked
     */
    private static function withLock(int $idProduct, callable $callback)
    {
        $db = \Db::getInstance();
        $lock = "'ciklik_transform_" . (int) \Context::getContext()->shop->id . '_' . $idProduct . "'";
        if (!(int) $db->getValue('SELECT GET_LOCK(' . $lock . ', 0)', false)) {
            throw new \DomainException('locked');
        }

        try {
            return $callback();
        } finally {
            $db->getValue('SELECT RELEASE_LOCK(' . $lock . ')', false);
        }
    }

    /**
     * Faits du produit pour SubscriptionTransformPlan::productRefusal()
     */
    private static function productFacts(int $idProduct): array
    {
        // Produit de la boutique du contexte seulement
        $product = \Db::getInstance()->getRow(
            'SELECT p.cache_is_pack, p.is_virtual FROM `' . _DB_PREFIX_ . 'product` p
            INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.id_product = p.id_product AND ps.id_shop = ' . (int) \Context::getContext()->shop->id . '
            WHERE p.id_product = ' . $idProduct
        );
        if (!$product) {
            throw new \DomainException('unknown_product');
        }

        $groups = [
            (int) \Configuration::get(\Ciklik::CONFIG_PURCHASE_TYPE_ATTRIBUTE_GROUP_ID),
            (int) \Configuration::get(\Ciklik::CONFIG_FREQUENCIES_ATTRIBUTE_GROUP_ID),
        ];
        $attributes = [
            (int) \Configuration::get(\Ciklik::CONFIG_ONEOFF_ATTRIBUTE_ID),
            (int) \Configuration::get(\Ciklik::CONFIG_SUBSCRIPTION_ATTRIBUTE_ID),
            (int) \Configuration::get(\Ciklik::CONFIG_DEFAULT_SUBSCRIPTION_ATTRIBUTE_ID),
        ];
        // Réglages complets : les deux groupes, « Une fois », « Abonnement » et la fréquence par défaut existent
        $configComplete = !in_array(0, array_merge($groups, $attributes), true)
            && 2 === (int) \Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'attribute_group` WHERE id_attribute_group IN (' . implode(',', $groups) . ')')
            && 3 === (int) \Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'attribute` WHERE id_attribute IN (' . implode(',', $attributes) . ')');

        $hasCiklikAttributes = $configComplete && (bool) \Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'product_attribute` pa
            INNER JOIN `' . _DB_PREFIX_ . 'product_attribute_combination` pac ON pac.id_product_attribute = pa.id_product_attribute
            INNER JOIN `' . _DB_PREFIX_ . 'attribute` a ON a.id_attribute = pac.id_attribute
            WHERE pa.id_product = ' . $idProduct . ' AND a.id_attribute_group IN (' . implode(',', $groups) . ')'
        );

        return [
            'is_pack' => (bool) $product['cache_is_pack'],
            'is_virtual' => (bool) $product['is_virtual'],
            // Gestion de stock avancée (1.7 seulement, supprimée en 8)
            'depends_on_stock' => method_exists('StockAvailable', 'dependsOnStock') && \StockAvailable::dependsOnStock($idProduct),
            'has_ciklik_attributes' => $hasCiklikAttributes,
            'config_complete' => $configComplete,
        ];
    }

    /**
     * Fréquences du mode déclinaisons : attributs du groupe « Fréquence d'abonnement » reliés à ciklik_frequencies
     *
     * @param int $idLang Langue des libellés (0 : sans libellé)
     */
    private static function availableFrequencies(int $idLang): array
    {
        return \Db::getInstance()->executeS(
            'SELECT cf.id_attribute, cf.interval, cf.interval_count' . ($idLang ? ', al.name' : '') . '
            FROM `' . _DB_PREFIX_ . 'ciklik_frequencies` cf
            INNER JOIN `' . _DB_PREFIX_ . 'attribute` a ON a.id_attribute = cf.id_attribute
                AND a.id_attribute_group = ' . (int) \Configuration::get(\Ciklik::CONFIG_FREQUENCIES_ATTRIBUTE_GROUP_ID) . '
            ' . ($idLang ? 'LEFT JOIN `' . _DB_PREFIX_ . 'attribute_lang` al ON al.id_attribute = a.id_attribute AND al.id_lang = ' . $idLang : '') . '
            ORDER BY a.position, a.id_attribute'
        ) ?: [];
    }

    /**
     * @return int[] Identifiants sans doublon (une fréquence en double créerait deux déclinaisons identiques)
     */
    private static function uniqueIds(array $ids): array
    {
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * @return int[]
     */
    private static function combinationIds(int $idProduct): array
    {
        return self::column('SELECT id_product_attribute FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE id_product = ' . $idProduct . ' ORDER BY id_product_attribute');
    }

    /**
     * @return int[] Première colonne de la requête
     */
    private static function column(string $sql): array
    {
        $values = [];
        foreach (\Db::getInstance()->executeS($sql) ?: [] as $row) {
            $values[] = (int) reset($row);
        }

        return $values;
    }
}
