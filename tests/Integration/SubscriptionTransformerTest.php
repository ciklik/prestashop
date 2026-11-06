<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Tests\Integration;

use PrestaShop\Module\Ciklik\Managers\CiklikSubscriptionTransformer;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * « Transformer en abonnement » sur une vraie base : étapes, reprise sans doublon, annulation qui rend
 * le produit à l'identique, verrou. La dernière étape (envoi à Ciklik) n'est jamais atteinte : chaque
 * test annule sa transformation.
 */
class SubscriptionTransformerTest extends IntegrationTestCase
{
    /** @var int */
    private $defaultFrequency;

    /** @var int */
    private $otherFrequency;

    /** @var int */
    private $subscriptionAttribute;

    protected function setUp(): void
    {
        if (\Configuration::get(\Ciklik::CONFIG_USE_FREQUENCY_MODE)) {
            $this->markTestSkipped('Module en mode fréquences : la transformation concerne le mode déclinaisons.');
        }
        $this->defaultFrequency = (int) \Configuration::get(\Ciklik::CONFIG_DEFAULT_SUBSCRIPTION_ATTRIBUTE_ID);
        $this->subscriptionAttribute = (int) \Configuration::get(\Ciklik::CONFIG_SUBSCRIPTION_ATTRIBUTE_ID);
        $this->otherFrequency = (int) \Db::getInstance()->getValue(
            'SELECT cf.id_attribute FROM `' . _DB_PREFIX_ . 'ciklik_frequencies` cf
            INNER JOIN `' . _DB_PREFIX_ . 'attribute` a ON a.id_attribute = cf.id_attribute
                AND a.id_attribute_group = ' . (int) \Configuration::get(\Ciklik::CONFIG_FREQUENCIES_ATTRIBUTE_GROUP_ID) . '
            WHERE cf.id_attribute <> ' . $this->defaultFrequency . ' AND cf.interval_count BETWEEN 1 AND 28
            ORDER BY cf.id_attribute'
        );
        if (!$this->defaultFrequency || !$this->subscriptionAttribute || !$this->otherFrequency) {
            $this->markTestSkipped('Module non configuré : attributs Ciklik ou deux fréquences manquants.');
        }
    }

    public function testCancelRestoresAProductWithCombinations()
    {
        $idProduct = $this->productWithSizes();
        $before = $this->snapshot($idProduct);

        CiklikSubscriptionTransformer::start($idProduct, [$this->defaultFrequency, $this->otherFrequency]);
        $progress = CiklikSubscriptionTransformer::step($idProduct);
        $this->assertSame(['done' => 4, 'total' => 4, 'finished' => false], $progress);

        $during = $this->snapshot($idProduct);
        $this->assertCount(6, $during['combinations']);
        // Mêmes référence, EAN et stock que la déclinaison d'origine
        foreach ($during['combinations'] as $combination) {
            $this->assertContains($combination['reference'] . '/' . $combination['ean13'] . '/' . $combination['quantity'], ['IT-S/3700000098001/5', 'IT-M/3700000098002/8']);
        }

        $this->cancel($idProduct);
        $this->assertSame($before, $this->snapshot($idProduct));
        $this->assertNull(CiklikSubscriptionTransformer::getState($idProduct, 1)['job']);
    }

    public function testResumeCreatesOnlyWhatIsMissing()
    {
        $idProduct = $this->productWithSizes();

        CiklikSubscriptionTransformer::start($idProduct, [$this->defaultFrequency, $this->otherFrequency]);
        CiklikSubscriptionTransformer::step($idProduct);

        // Étape interrompue : une déclinaison abonnement manque
        $created = $this->subscriptionCombinations($idProduct);
        $this->assertCount(4, $created);
        (new \Combination(end($created)))->delete();
        $this->assertSame(['done' => 3, 'total' => 4, 'finished' => false], CiklikSubscriptionTransformer::getState($idProduct, 1)['job']);

        $this->assertSame(['done' => 4, 'total' => 4, 'finished' => false], CiklikSubscriptionTransformer::step($idProduct));
        $signatures = array_map(function ($combination) {
            return $combination['attributes'];
        }, $this->snapshot($idProduct)['combinations']);
        $this->assertCount(6, $signatures);
        $this->assertSame(count($signatures), count(array_unique($signatures)), 'aucun doublon');

        $this->cancel($idProduct);
    }

    public function testSecondStartAndConcurrentStepAreRefused()
    {
        $idProduct = $this->productWithSizes();
        CiklikSubscriptionTransformer::start($idProduct, [$this->defaultFrequency]);

        $this->assertRefusal('job_running', function () use ($idProduct) {
            CiklikSubscriptionTransformer::start($idProduct, [$this->defaultFrequency]);
        });
        $this->assertRefusal('job_running', function () use ($idProduct) {
            CiklikSubscriptionTransformer::realignStock($idProduct);
        });

        // Verrou tenu par une autre connexion : l'étape est refusée sans rien écrire
        $other = new \mysqli(_DB_SERVER_, _DB_USER_, _DB_PASSWD_, _DB_NAME_);
        try {
            $other->query("SELECT GET_LOCK('" . CiklikSubscriptionTransformer::lockName($idProduct) . "', 0)");
            $this->assertRefusal('locked', function () use ($idProduct) {
                CiklikSubscriptionTransformer::step($idProduct);
            });
        } finally {
            // Libéré explicitement : à la fermeture seule, le serveur peut le garder un instant
            $other->query("SELECT RELEASE_LOCK('" . CiklikSubscriptionTransformer::lockName($idProduct) . "')");
            $other->close();
        }

        $this->assertCount(0, $this->subscriptionCombinations($idProduct));
        $this->cancel($idProduct);
    }

    public function testCancelRestoresASimpleProductAndItsStock()
    {
        $idCategory = $this->createCategory('IT transformation simple');
        $idProduct = $this->createProduct('IT produit simple', $idCategory, 7);

        $preview = CiklikSubscriptionTransformer::preview($idProduct, [$this->defaultFrequency]);
        $this->assertTrue($preview['simple']);
        $this->assertSame(2, $preview['total']);

        CiklikSubscriptionTransformer::start($idProduct, [$this->defaultFrequency]);
        CiklikSubscriptionTransformer::step($idProduct);
        $this->assertCount(2, $this->snapshot($idProduct)['combinations']);

        $this->cancel($idProduct);
        $after = $this->snapshot($idProduct);
        $this->assertSame([], $after['combinations']);
        $this->assertSame(7, $after['quantity']);
    }

    public function testPacksAndVirtualProductsAreRefused()
    {
        $idCategory = $this->createCategory('IT transformation refus');
        $pack = $this->createProduct('IT pack', $idCategory, 1, ['cache_is_pack' => 1]);
        $virtual = $this->createProduct('IT virtuel', $idCategory, 1, ['is_virtual' => 1]);

        $this->assertRefusal('pack', function () use ($pack) {
            CiklikSubscriptionTransformer::preview($pack, [$this->defaultFrequency]);
        });
        $this->assertRefusal('virtual', function () use ($virtual) {
            CiklikSubscriptionTransformer::start($virtual, [$this->defaultFrequency]);
        });
    }

    public function testPanelOffersResendOnlyAfterAFailedSync()
    {
        $idCategory = $this->createCategory('IT renvoi');
        $idProduct = $this->createProduct('IT produit abonnement', $idCategory);
        $this->addCombination($idProduct, (int) \Configuration::get(\Ciklik::CONFIG_ONEOFF_ATTRIBUTE_ID), 'IT-U', '3700000098003', 3);
        $this->addCombination($idProduct, [$this->subscriptionAttribute, $this->defaultFrequency], 'IT-U', '3700000098003', 3);
        $module = \Module::getInstanceByName('ciklik');

        $this->assertStringNotContainsString('data-ciklik-action="sync"', $module->hookDisplayAdminProductsExtra(['id_product' => $idProduct]));

        \Configuration::updateGlobalValue('CIKLIK_SYNC_FAILED_' . (int) \Context::getContext()->shop->id . '_' . $idProduct, 1);
        $state = CiklikSubscriptionTransformer::getState($idProduct, 1);
        $this->assertTrue($state['has_subscription']);
        $this->assertTrue($state['sync_failed']);
        $this->assertStringContainsString('data-ciklik-action="sync"', $module->hookDisplayAdminProductsExtra(['id_product' => $idProduct]));
    }

    public function testResendIsRefusedDuringATransformation()
    {
        $idProduct = $this->productWithSizes();
        CiklikSubscriptionTransformer::start($idProduct, [$this->defaultFrequency]);

        $this->assertRefusal('job_running', function () use ($idProduct) {
            CiklikSubscriptionTransformer::resync($idProduct);
        });

        $this->cancel($idProduct);
    }

    private function productWithSizes(): int
    {
        $idCategory = $this->createCategory('IT transformation');
        $idProduct = $this->createProduct('IT produit à tailles', $idCategory);
        list($small, $medium) = $this->createAttributes('IT taille', ['S', 'M']);
        $this->addCombination($idProduct, $small, 'IT-S', '3700000098001', 5);
        $this->addCombination($idProduct, $medium, 'IT-M', '3700000098002', 8);

        return $idProduct;
    }

    private function cancel(int $idProduct)
    {
        for ($i = 0; $i < 10; ++$i) {
            if (CiklikSubscriptionTransformer::cancel($idProduct)['finished']) {
                return;
            }
        }
        $this->fail('annulation inachevée');
    }

    private function assertRefusal(string $code, callable $action)
    {
        try {
            $action();
        } catch (\DomainException $e) {
            $this->assertSame($code, $e->getMessage());

            return;
        }
        $this->fail('refus ' . $code . ' attendu');
    }

    /**
     * @return int[] Déclinaisons qui portent « Abonnement »
     */
    private function subscriptionCombinations(int $idProduct): array
    {
        return array_map('intval', array_column(\Db::getInstance()->executeS(
            'SELECT pa.id_product_attribute FROM `' . _DB_PREFIX_ . 'product_attribute` pa
            INNER JOIN `' . _DB_PREFIX_ . 'product_attribute_combination` pac ON pac.id_product_attribute = pa.id_product_attribute
            WHERE pa.id_product = ' . $idProduct . ' AND pac.id_attribute = ' . $this->subscriptionAttribute . '
            ORDER BY pa.id_product_attribute',
            true,
            false
        ) ?: [], 'id_product_attribute'));
    }

    /**
     * Déclinaisons (attributs triés, références, stock) et stock du produit
     */
    private function snapshot(int $idProduct): array
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT pa.id_product_attribute, pa.reference, pa.ean13,
                (SELECT GROUP_CONCAT(pac.id_attribute ORDER BY pac.id_attribute) FROM `' . _DB_PREFIX_ . 'product_attribute_combination` pac
                 WHERE pac.id_product_attribute = pa.id_product_attribute) AS attributes
            FROM `' . _DB_PREFIX_ . 'product_attribute` pa
            WHERE pa.id_product = ' . $idProduct . '
            ORDER BY pa.id_product_attribute',
            true,
            false
        ) ?: [];
        $combinations = [];
        foreach ($rows as $row) {
            $combinations[] = [
                'id' => (int) $row['id_product_attribute'],
                'attributes' => (string) $row['attributes'],
                'reference' => (string) $row['reference'],
                'ean13' => (string) $row['ean13'],
                'quantity' => (int) \StockAvailable::getQuantityAvailableByProduct($idProduct, (int) $row['id_product_attribute']),
            ];
        }

        return ['combinations' => $combinations, 'quantity' => (int) \StockAvailable::getQuantityAvailableByProduct($idProduct, 0)];
    }
}
