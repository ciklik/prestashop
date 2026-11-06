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
 *
 * La transformation avance par étapes (une requête chacune, STEP_SIZE déclinaisons au plus) pour
 * tenir dans le délai du serveur quand les hooks d'autres modules (ps_eventbus) ralentissent
 * chaque création. Chaque étape est une transaction ; l'état (fréquences, sources) est gardé en
 * configuration et ce qui reste à créer se déduit des attributs présents : une étape échouée ou
 * une page fermée se reprend sans doublon, ou s'annule.
 */
class CiklikSubscriptionTransformer
{
    /** Déclinaisons créées ou retirées par étape */
    const STEP_SIZE = 50;

    /** Durée au-delà de laquelle une étape s'arrête avant STEP_SIZE (secondes) */
    const STEP_SECONDS = 20;

    /** Préfixe de la clé de configuration de l'état, suivi du produit (les déclinaisons sont communes aux boutiques) */
    const JOB_KEY = 'CIKLIK_TRANSFORM_';

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
     * @return array single_shop (contexte d'une seule boutique), sync_failed (dernier envoi à Ciklik
     *               en échec), refusal, has_subscription (déclinaisons abonnement présentes), frequencies,
     *               default_frequency, job (avancement d'une transformation en cours, null sinon)
     */
    public static function getState(int $idProduct, int $idLang): array
    {
        $facts = self::productFacts($idProduct);
        $job = self::readJob($idProduct);

        return [
            'single_shop' => self::isSingleShopContext(),
            'sync_failed' => CiklikSubscribable::hasSyncFailed($idProduct),
            'job' => $job ? self::progress($idProduct, $job) : null,
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
     * Première étape : « Achat en une fois » posé sur les déclinaisons existantes (ou déclinaison
     * « Une fois » créée pour un produit simple) et état enregistré, dans une transaction
     *
     * @param int[] $frequencyIds Attributs de fréquence choisis
     *
     * @return array Avancement : done, total, finished
     *
     * @throws \DomainException Motif de refus
     */
    public static function start(int $idProduct, array $frequencyIds): array
    {
        $frequencyIds = self::uniqueIds($frequencyIds);

        return self::withLock($idProduct, function () use ($idProduct, $frequencyIds) {
            if (self::readJob($idProduct)) {
                throw new \DomainException(SubscriptionTransformPlan::JOB_RUNNING);
            }
            $summary = self::check($idProduct, $frequencyIds);
            $oneoffAttribute = (int) \Configuration::get(\Ciklik::CONFIG_ONEOFF_ATTRIBUTE_ID);
            $job = [
                'frequencies' => SubscriptionTransformPlan::creationOrder($frequencyIds, (int) \Configuration::get(\Ciklik::CONFIG_DEFAULT_SUBSCRIPTION_ATTRIBUTE_ID)),
                'sources' => [],
                'created_oneoff' => 0,
                'simple_quantity' => null,
                // Déclinaisons et stock créés dans cette boutique : reprise et annulation depuis elle seule
                'id_shop' => (int) \Context::getContext()->shop->id,
            ];
            $created = [];
            $oneoffAdded = [];

            self::inTransaction(function () use ($idProduct, $summary, $oneoffAttribute, &$job, &$created, &$oneoffAdded) {
                if ($summary['simple']) {
                    $job['simple_quantity'] = (int) \StockAvailable::getQuantityAvailableByProduct($idProduct, 0);
                    $job['created_oneoff'] = self::createOneoffFromProduct($idProduct, $oneoffAttribute, $job['simple_quantity'], $created);
                    $job['sources'] = [$job['created_oneoff']];
                } else {
                    $job['sources'] = self::combinationIds($idProduct);
                    $values = [];
                    foreach ($job['sources'] as $idSource) {
                        $values[] = '(' . $oneoffAttribute . ', ' . $idSource . ')';
                    }
                    // Insertion directe : D garde son id et ses autres attributs
                    self::ensure(\Db::getInstance()->execute('INSERT INTO `' . _DB_PREFIX_ . 'product_attribute_combination` (id_attribute, id_product_attribute) VALUES ' . implode(',', $values)));
                    $oneoffAdded = $job['sources'];
                }
                self::ensure(self::saveJob($idProduct, $job));
            }, function () use ($idProduct, &$created, &$oneoffAdded, &$job) {
                self::deleteCombinations($created);
                self::removeOneoff($idProduct, $oneoffAdded);
                self::deleteJob($idProduct);
                if (null !== $job['simple_quantity']) {
                    self::restoreSimpleProduct($idProduct, $job['simple_quantity']);
                }
            });

            return self::progress($idProduct, $job);
        });
    }

    /**
     * Étape suivante : crée au plus STEP_SIZE déclinaisons abonnement manquantes. Quand il n'en
     * manque plus, termine : état effacé, réindexation, envoi unique du catalogue à Ciklik.
     *
     * @return array done, total, finished ; à la fin created et sync (null si l'envoi a échoué)
     *
     * @throws \DomainException no_job
     */
    public static function step(int $idProduct): array
    {
        self::extendTimeLimit();

        return self::withLock($idProduct, function () use ($idProduct) {
            $job = self::requireJob($idProduct);
            $pending = self::pendingPairs($idProduct, $job);

            if (!$pending) {
                return self::finish($idProduct, $job);
            }

            $created = [];
            self::inTransaction(function () use ($idProduct, $job, $pending, &$created) {
                $startedAt = microtime(true);
                $subscriptionAttribute = (int) \Configuration::get(\Ciklik::CONFIG_SUBSCRIPTION_ATTRIBUTE_ID);
                foreach (array_slice($pending, 0, self::STEP_SIZE) as $pair) {
                    self::createSubscription($idProduct, $pair[0], $pair[1], $subscriptionAttribute, $created);
                    if (microtime(true) - $startedAt > self::STEP_SECONDS) {
                        break;
                    }
                }
                // Un interblocage MySQL annule la transaction en silence (PrestaShop rend seulement
                // false) et les écritures suivantes partent hors transaction : le compte le révèle
                if (count(self::pendingPairs($idProduct, $job)) !== count($pending) - count($created)) {
                    throw new \RuntimeException('incomplete_step');
                }
            }, function () use (&$created) {
                self::deleteCombinations($created);
            });

            return self::progress($idProduct, $job);
        });
    }

    /**
     * « Annuler la transformation », par étapes : retire les déclinaisons abonnement (et la
     * « Une fois » d'un produit simple) créées par la transformation, puis l'attribut « Une fois »
     * ajouté aux déclinaisons d'origine, qui ne sont jamais supprimées
     *
     * @return array remaining, finished
     *
     * @throws \DomainException no_job
     */
    public static function cancel(int $idProduct): array
    {
        self::extendTimeLimit();

        return self::withLock($idProduct, function () use ($idProduct) {
            $job = self::requireJob($idProduct);
            $targets = SubscriptionTransformPlan::cancelTargets(
                self::combinationAttributes($idProduct),
                (int) \Configuration::get(\Ciklik::CONFIG_SUBSCRIPTION_ATTRIBUTE_ID),
                (int) $job['created_oneoff']
            );

            if ($targets) {
                $startedAt = microtime(true);
                $deleted = 0;
                foreach (array_slice($targets, 0, self::STEP_SIZE) as $id) {
                    if ($id === (int) $job['created_oneoff']) {
                        // Stock réel de la « Une fois » (ventes comprises), rendu au produit simple
                        $job['simple_quantity'] = (int) \StockAvailable::getQuantityAvailableByProduct($idProduct, $id);
                        self::ensure(self::saveJob($idProduct, $job));
                    }
                    $deleted += self::deleteCombinations([$id]);
                    if (microtime(true) - $startedAt > self::STEP_SECONDS) {
                        break;
                    }
                }
                // Une suppression refusée (module tiers) arrêterait sinon l'annulation en boucle
                if (!$deleted) {
                    throw new \RuntimeException('cancel_stalled');
                }

                return ['remaining' => count($targets) - $deleted, 'finished' => false];
            }

            if (!$job['created_oneoff']) {
                self::removeOneoff($idProduct, $job['sources']);
            }
            if (null !== $job['simple_quantity']) {
                self::restoreSimpleProduct($idProduct, (int) $job['simple_quantity']);
            }
            self::deleteJob($idProduct);
            self::reindex($idProduct);

            return ['remaining' => 0, 'finished' => true];
        });
    }

    /**
     * Aperçu de « Réaligner le stock », sans écriture
     *
     * @throws \DomainException unknown_product, advanced_stock
     */
    public static function realignPreview(int $idProduct, int $idLang): array
    {
        self::requireRealignable($idProduct);

        return CiklikStockRealigner::preview($idProduct, $idLang);
    }

    /**
     * « Réaligner le stock » sous le même verrou que la transformation, jamais pendant une transformation
     *
     * @return int Nombre de déclinaisons dont la quantité a été posée
     */
    public static function realignStock(int $idProduct): int
    {
        self::extendTimeLimit();

        return self::withLock($idProduct, function () use ($idProduct) {
            if (self::readJob($idProduct)) {
                throw new \DomainException(SubscriptionTransformPlan::JOB_RUNNING);
            }
            self::requireRealignable($idProduct);

            return CiklikStockRealigner::apply($idProduct);
        });
    }

    /**
     * « Renvoyer à Ciklik » après un envoi en échec, jamais pendant une transformation
     *
     * @return array|null Résultat de CiklikSubscribable::handle()
     *
     * @throws \DomainException job_running, locked, unknown_product
     */
    public static function resync(int $idProduct)
    {
        self::extendTimeLimit();

        return self::withLock($idProduct, function () use ($idProduct) {
            if (self::readJob($idProduct)) {
                throw new \DomainException(SubscriptionTransformPlan::JOB_RUNNING);
            }
            // Produit de la boutique du contexte seulement (unknown_product sinon)
            self::productFacts($idProduct);

            return CiklikSubscribable::handle($idProduct);
        });
    }

    /**
     * Multiboutique : le stock est posé dans la boutique du contexte, les actions demandent donc
     * le contexte d'une seule boutique (jamais « toutes les boutiques » ni un groupe)
     */
    public static function isSingleShopContext(): bool
    {
        return !\Shop::isFeatureActive() || \Shop::CONTEXT_SHOP === \Shop::getContext();
    }

    /**
     * @throws \DomainException shop_context
     */
    public static function requireSingleShop()
    {
        if (!self::isSingleShopContext()) {
            throw new \DomainException(SubscriptionTransformPlan::SHOP_CONTEXT);
        }
    }

    /**
     * Produit de la boutique du contexte, hors gestion de stock avancée (1.7) : les quantités ne se
     * posent pas à la main
     *
     * @throws \DomainException unknown_product, advanced_stock
     */
    private static function requireRealignable(int $idProduct)
    {
        if (self::productFacts($idProduct)['depends_on_stock']) {
            throw new \DomainException(SubscriptionTransformPlan::ADVANCED_STOCK);
        }
    }

    /**
     * Gestion de stock avancée, en 1.7 seulement (supprimée en 8)
     */
    private static function dependsOnAdvancedStock(int $idProduct): bool
    {
        return method_exists('StockAvailable', 'dependsOnStock') && \StockAvailable::dependsOnStock($idProduct);
    }

    /**
     * Vérifie le produit et les fréquences, au moment de l'aperçu comme du démarrage
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
     * Dernière étape : état effacé, réindexation, puis un seul envoi du catalogue à Ciklik.
     * Un échec de l'API ne défait rien (la boutique est saine) : relance par enregistrement de la fiche.
     */
    private static function finish(int $idProduct, array $job): array
    {
        self::reindex($idProduct);
        self::deleteJob($idProduct);

        try {
            $sync = CiklikSubscribable::handle($idProduct);
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('Ciklik - envoi du produit ' . $idProduct . ' après transformation échoué : ' . $e->getMessage(), 3);
            $sync = null;
        }

        $total = count($job['sources']) * count($job['frequencies']);

        return ['done' => $total, 'total' => $total, 'finished' => true, 'created' => $total, 'sync' => $sync];
    }

    /**
     * Réindexation après transformation ou annulation : un échec est journalisé, rien n'est défait
     */
    private static function reindex(int $idProduct)
    {
        try {
            \Search::indexation(false, $idProduct);
            if (method_exists('Product', 'flushPriceCache')) {
                \Product::flushPriceCache();
            }
        } catch (\Throwable $e) {
            \PrestaShopLogger::addLog('Ciklik - réindexation du produit ' . $idProduct . ' échouée : ' . $e->getMessage(), 2);
        }
    }

    /**
     * Déclinaison abonnement à l'image de sa source (mêmes champs, références, impact prix,
     * images, fournisseurs), avec une copie ponctuelle du stock de la source
     */
    private static function createSubscription(int $idProduct, int $idSource, int $idFrequency, int $subscriptionAttribute, array &$created)
    {
        $source = new \Combination($idSource);
        $combination = new \Combination();
        foreach (\Combination::$definition['fields'] as $field => $unused) {
            if (property_exists($source, $field)) {
                $combination->$field = $source->$field;
            }
        }
        $combination->id_product = $idProduct;
        $combination->default_on = null;
        self::addCombination($combination, $created);

        $attributes = array_diff(
            self::column('SELECT id_attribute FROM `' . _DB_PREFIX_ . 'product_attribute_combination` WHERE id_product_attribute = ' . $idSource, false),
            [(int) \Configuration::get(\Ciklik::CONFIG_ONEOFF_ATTRIBUTE_ID)]
        );
        self::ensure($combination->setAttributes(array_merge($attributes, [$subscriptionAttribute, $idFrequency])));
        self::ensure($combination->setImages(self::column('SELECT id_image FROM `' . _DB_PREFIX_ . 'product_attribute_image` WHERE id_product_attribute = ' . $idSource)));
        self::copySuppliers($idProduct, $idSource, (int) $combination->id);
        \StockAvailable::setQuantity($idProduct, (int) $combination->id, (int) \StockAvailable::getQuantityAvailableByProduct($idProduct, $idSource));
    }

    /**
     * Produit simple : déclinaison « Une fois » sans impact, par défaut, qui reprend les codes,
     * les images, les fournisseurs et le stock du produit (le produit garde les siens)
     */
    private static function createOneoffFromProduct(int $idProduct, int $oneoffAttribute, int $quantity, array &$created): int
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
        self::addCombination($combination, $created);

        $id = (int) $combination->id;
        self::ensure($combination->setAttributes([$oneoffAttribute]));
        self::ensure($combination->setImages(self::column('SELECT id_image FROM `' . _DB_PREFIX_ . 'image` WHERE id_product = ' . $idProduct)));
        self::copySuppliers($idProduct, 0, $id);
        \StockAvailable::setQuantity($idProduct, $id, $quantity);

        return $id;
    }

    private static function addCombination(\Combination $combination, array &$created)
    {
        if (!$combination->add()) {
            throw new \RuntimeException('combination_add_failed');
        }
        $created[] = (int) $combination->id;
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
     * Une écriture refusée (blocage, interblocage) interrompt l'étape
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
     * Exécute $writes dans une transaction. En cas d'échec : annulation, puis $compensate pour
     * défaire ce que l'annulation n'aurait pas défait (table MyISAM, validation implicite par un
     * module tiers) ; les déclinaisons d'origine ne sont jamais supprimées.
     */
    private static function inTransaction(callable $writes, callable $compensate)
    {
        $db = \Db::getInstance();
        $db->execute('START TRANSACTION');
        try {
            $writes();
            if (!$db->execute('COMMIT')) {
                throw new \RuntimeException('commit_failed');
            }
        } catch (\Throwable $e) {
            $db->execute('ROLLBACK');
            try {
                $compensate();
            } catch (\Throwable $ignored) {
                \PrestaShopLogger::addLog('Ciklik - compensation incomplète : ' . $ignored->getMessage(), 3);
            }
            throw $e;
        }
    }

    /**
     * Supprime les déclinaisons encore présentes parmi $ids
     *
     * @param int[] $ids
     *
     * @return int Nombre de déclinaisons supprimées
     */
    private static function deleteCombinations(array $ids): int
    {
        $deleted = 0;
        foreach (array_reverse($ids) as $id) {
            // Multiboutique : delete() rend true en retirant seulement la ligne de la boutique du
            // contexte ; la déclinaison ne compte comme supprimée que si sa ligne principale a disparu
            if (self::combinationExists((int) $id)) {
                (new \Combination((int) $id))->delete();
                if (!self::combinationExists((int) $id)) {
                    ++$deleted;
                }
            }
        }

        return $deleted;
    }

    private static function combinationExists(int $id): bool
    {
        return (bool) \Db::getInstance()->getValue('SELECT id_product_attribute FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE id_product_attribute = ' . $id, false);
    }

    /**
     * Retire « Achat en une fois » des déclinaisons d'origine du produit
     *
     * @param int[] $ids
     */
    private static function removeOneoff(int $idProduct, array $ids)
    {
        if ($ids) {
            \Db::getInstance()->execute(
                'DELETE pac FROM `' . _DB_PREFIX_ . 'product_attribute_combination` pac
                INNER JOIN `' . _DB_PREFIX_ . 'product_attribute` pa ON pa.id_product_attribute = pac.id_product_attribute AND pa.id_product = ' . $idProduct . '
                WHERE pac.id_attribute = ' . (int) \Configuration::get(\Ciklik::CONFIG_ONEOFF_ATTRIBUTE_ID) . '
                AND pac.id_product_attribute IN (' . implode(',', array_map('intval', $ids)) . ')'
            );
        }
    }

    /**
     * Produit simple redevenu sans déclinaison : stock et déclinaison par défaut d'avant
     */
    private static function restoreSimpleProduct(int $idProduct, int $quantity)
    {
        \StockAvailable::setQuantity($idProduct, 0, $quantity, null, false);
        \Product::updateDefaultAttribute($idProduct);
    }

    /**
     * Couples (source, fréquence) restant à créer, déduits des attributs présents. Une source
     * supprimée entre deux étapes est ignorée.
     */
    private static function pendingPairs(int $idProduct, array $job): array
    {
        $combinations = self::combinationAttributes($idProduct);
        $oneoffAttribute = (int) \Configuration::get(\Ciklik::CONFIG_ONEOFF_ATTRIBUTE_ID);
        $sources = [];
        foreach ($job['sources'] as $idSource) {
            if (isset($combinations[(int) $idSource])) {
                $sources[(int) $idSource] = array_values(array_diff($combinations[(int) $idSource], [$oneoffAttribute]));
            }
        }

        return SubscriptionTransformPlan::pendingPairs(
            $sources,
            array_map('intval', $job['frequencies']),
            (int) \Configuration::get(\Ciklik::CONFIG_SUBSCRIPTION_ATTRIBUTE_ID),
            array_map([SubscriptionTransformPlan::class, 'signature'], $combinations)
        );
    }

    /**
     * Avancement : déclinaisons abonnement créées sur le total à créer
     */
    private static function progress(int $idProduct, array $job): array
    {
        $total = count($job['sources']) * count($job['frequencies']);

        return ['done' => $total - count(self::pendingPairs($idProduct, $job)), 'total' => $total, 'finished' => false];
    }

    /**
     * @return array id_product_attribute => attributs, pour toutes les déclinaisons du produit
     */
    private static function combinationAttributes(int $idProduct): array
    {
        $combinations = [];
        $rows = \Db::getInstance()->executeS(
            'SELECT pa.id_product_attribute, pac.id_attribute
            FROM `' . _DB_PREFIX_ . 'product_attribute` pa
            LEFT JOIN `' . _DB_PREFIX_ . 'product_attribute_combination` pac ON pac.id_product_attribute = pa.id_product_attribute
            WHERE pa.id_product = ' . $idProduct,
            true,
            false
        ) ?: [];
        foreach ($rows as $row) {
            $id = (int) $row['id_product_attribute'];
            if (!isset($combinations[$id])) {
                $combinations[$id] = [];
            }
            if ($row['id_attribute']) {
                $combinations[$id][] = (int) $row['id_attribute'];
            }
        }

        return $combinations;
    }

    /**
     * État d'une transformation en cours, null s'il n'y en a pas
     *
     * @return array|null frequencies, sources, created_oneoff, simple_quantity
     */
    private static function readJob(int $idProduct)
    {
        $value = \Db::getInstance()->getValue(
            'SELECT value FROM `' . _DB_PREFIX_ . 'configuration` WHERE name = \'' . pSQL(self::jobKey($idProduct)) . '\'',
            false
        );
        $job = $value ? json_decode((string) $value, true) : null;

        return is_array($job) && isset($job['frequencies'], $job['sources']) && is_array($job['frequencies']) && is_array($job['sources']) ? $job : null;
    }

    /**
     * @throws \DomainException no_job, other_shop
     */
    private static function requireJob(int $idProduct): array
    {
        $job = self::readJob($idProduct);
        if (!$job) {
            throw new \DomainException(SubscriptionTransformPlan::NO_JOB);
        }
        if (isset($job['id_shop']) && (int) $job['id_shop'] !== (int) \Context::getContext()->shop->id) {
            throw new \DomainException(SubscriptionTransformPlan::OTHER_SHOP);
        }

        return $job;
    }

    /**
     * Écrit l'état puis le relit en base : Configuration décide entre ajout et mise à jour d'après
     * son cache, chargé avant le verrou, qu'un autre onglet a pu rendre périmé
     */
    private static function saveJob(int $idProduct, array $job): bool
    {
        return \Configuration::updateGlobalValue(self::jobKey($idProduct), json_encode($job))
            && self::readJob($idProduct) == $job;
    }

    private static function deleteJob(int $idProduct)
    {
        \Configuration::deleteByName(self::jobKey($idProduct));
    }

    private static function jobKey(int $idProduct): string
    {
        return self::JOB_KEY . $idProduct;
    }

    /**
     * Chaque création déclenche les hooks des autres modules : pas de limite PHP sur une étape
     */
    private static function extendTimeLimit()
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
    }

    /**
     * Nom du verrou MySQL d'un produit. Les verrous nommés valent pour tout le serveur : base et
     * préfixe y entrent pour qu'une autre boutique hébergée sur le même serveur ne le partage pas.
     */
    public static function lockName(int $idProduct): string
    {
        $database = defined('_DB_NAME_') ? (string) _DB_NAME_ : '';

        return 'ciklik_transform_' . md5($database . '|' . _DB_PREFIX_ . '|' . $idProduct);
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
        $lock = "'" . self::lockName($idProduct) . "'";
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
            'depends_on_stock' => self::dependsOnAdvancedStock($idProduct),
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
    private static function column(string $sql, bool $useCache = true): array
    {
        $values = [];
        foreach (\Db::getInstance()->executeS($sql, true, $useCache) ?: [] as $row) {
            $values[] = (int) reset($row);
        }

        return $values;
    }
}
