<?php

/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Helpers\StockForecastAggregator;

if (!defined('_PS_VERSION_')) {
    exit;
}

class StockForecastAggregatorTest extends TestCase
{
    protected function setUp(): void
    {
        \Db::resetMocks();
        \Configuration::resetMocks();
        \StockAvailable::resetMocks();
        \Product::resetMocks();
        \Combination::resetMocks();
    }

    // =========================================================================
    // Tests filterByDateRange
    // =========================================================================

    /**
     * Tableau vide retourne tableau vide
     */
    public function testFilterByDateRangeEmptyArray()
    {
        $result = StockForecastAggregator::filterByDateRange([], '2026-04-01', '2026-04-30');

        $this->assertEmpty($result);
    }

    /**
     * Abonnement actif dans la plage de dates : inclus
     */
    public function testFilterByDateRangeActiveInRange()
    {
        $subscriptions = [
            ['active' => true, 'next_billing' => '2026-04-15T00:00:00.000000Z'],
        ];

        $result = StockForecastAggregator::filterByDateRange($subscriptions, '2026-04-01', '2026-04-30');

        $this->assertCount(1, $result);
    }

    /**
     * Abonnement inactif : exclu
     */
    public function testFilterByDateRangeInactiveExcluded()
    {
        $subscriptions = [
            ['active' => false, 'next_billing' => '2026-04-15T00:00:00.000000Z'],
        ];

        $result = StockForecastAggregator::filterByDateRange($subscriptions, '2026-04-01', '2026-04-30');

        $this->assertEmpty($result);
    }

    /**
     * Abonnement hors plage : exclu
     */
    public function testFilterByDateRangeOutOfRange()
    {
        $subscriptions = [
            ['active' => true, 'next_billing' => '2026-05-15T00:00:00.000000Z'],
        ];

        $result = StockForecastAggregator::filterByDateRange($subscriptions, '2026-04-01', '2026-04-30');

        $this->assertEmpty($result);
    }

    /**
     * Abonnement sans next_billing : exclu
     */
    public function testFilterByDateRangeNoNextBilling()
    {
        $subscriptions = [
            ['active' => true],
        ];

        $result = StockForecastAggregator::filterByDateRange($subscriptions, '2026-04-01', '2026-04-30');

        $this->assertEmpty($result);
    }

    /**
     * Dates limites (borne incluse)
     */
    public function testFilterByDateRangeBoundaryDatesIncluded()
    {
        $subscriptions = [
            ['active' => true, 'next_billing' => '2026-04-01T00:00:00.000000Z'],
            ['active' => true, 'next_billing' => '2026-04-30T23:59:59.000000Z'],
        ];

        $result = StockForecastAggregator::filterByDateRange($subscriptions, '2026-04-01', '2026-04-30');

        $this->assertCount(2, $result);
    }

    /**
     * Mix d'abonnements : seuls les actifs dans la plage sont inclus
     */
    public function testFilterByDateRangeMixed()
    {
        $subscriptions = [
            ['active' => true, 'next_billing' => '2026-04-10T00:00:00.000000Z'],
            ['active' => false, 'next_billing' => '2026-04-15T00:00:00.000000Z'],
            ['active' => true, 'next_billing' => '2026-05-01T00:00:00.000000Z'],
            ['active' => true, 'next_billing' => '2026-04-20T00:00:00.000000Z'],
        ];

        $result = StockForecastAggregator::filterByDateRange($subscriptions, '2026-04-01', '2026-04-30');

        $this->assertCount(2, $result);
    }

    // =========================================================================
    // Tests aggregateFromSubscriptions - mode fréquence
    // =========================================================================

    /**
     * Tableau vide retourne tableau vide
     */
    public function testAggregateEmptyArray()
    {
        $result = StockForecastAggregator::aggregateFromSubscriptions([], true);

        $this->assertEmpty($result);
    }

    /**
     * Un abonnement, un produit, mode fréquence
     */
    public function testAggregateSingleProductFrequencyMode()
    {
        $subscriptions = [
            [
                'content' => [
                    ['external_id' => '42:108', 'quantity' => 1],
                ],
            ],
        ];

        $result = StockForecastAggregator::aggregateFromSubscriptions($subscriptions, true);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('42:108', $result);
        $this->assertEquals(42, $result['42:108']['id_product']);
        $this->assertEquals(108, $result['42:108']['id_product_attribute']);
        $this->assertEquals(1, $result['42:108']['quantity']);
    }

    /**
     * Un abonnement avec plusieurs produits
     */
    public function testAggregateMultipleProductsInOneSubscription()
    {
        $subscriptions = [
            [
                'content' => [
                    ['external_id' => '42:108', 'quantity' => 2],
                    ['external_id' => '55:200', 'quantity' => 1],
                ],
            ],
        ];

        $result = StockForecastAggregator::aggregateFromSubscriptions($subscriptions, true);

        $this->assertCount(2, $result);
        $this->assertEquals(2, $result['42:108']['quantity']);
        $this->assertEquals(1, $result['55:200']['quantity']);
    }

    /**
     * Même produit dans plusieurs abonnements : quantités cumulées
     */
    public function testAggregateSameProductAcrossSubscriptions()
    {
        $subscriptions = [
            [
                'content' => [
                    ['external_id' => '42:108', 'quantity' => 2],
                ],
            ],
            [
                'content' => [
                    ['external_id' => '42:108', 'quantity' => 3],
                ],
            ],
        ];

        $result = StockForecastAggregator::aggregateFromSubscriptions($subscriptions, true);

        $this->assertCount(1, $result);
        $this->assertEquals(5, $result['42:108']['quantity']);
    }

    /**
     * Contenu vide : ignoré
     */
    public function testAggregateEmptyContentSkipped()
    {
        $subscriptions = [
            ['content' => []],
            [],
        ];

        $result = StockForecastAggregator::aggregateFromSubscriptions($subscriptions, true);

        $this->assertEmpty($result);
    }

    /**
     * external_id invalide : ignoré
     */
    public function testAggregateInvalidExternalIdSkipped()
    {
        $subscriptions = [
            [
                'content' => [
                    ['external_id' => 'abc:def', 'quantity' => 1],
                    ['external_id' => '', 'quantity' => 1],
                ],
            ],
        ];

        $result = StockForecastAggregator::aggregateFromSubscriptions($subscriptions, true);

        $this->assertEmpty($result);
    }

    /**
     * Hash de customization ignoré dans l'external_id
     */
    public function testAggregateCustomizationHashStripped()
    {
        $subscriptions = [
            [
                'content' => [
                    ['external_id' => '42:108_abc123', 'quantity' => 1],
                ],
            ],
        ];

        $result = StockForecastAggregator::aggregateFromSubscriptions($subscriptions, true);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('42:108', $result);
        $this->assertEquals(42, $result['42:108']['id_product']);
        $this->assertEquals(108, $result['42:108']['id_product_attribute']);
    }

    /**
     * Quantité absente → défaut à 1
     */
    public function testAggregateDefaultQuantityIsOne()
    {
        $subscriptions = [
            [
                'content' => [
                    ['external_id' => '42:108'],
                ],
            ],
        ];

        $result = StockForecastAggregator::aggregateFromSubscriptions($subscriptions, true);

        $this->assertEquals(1, $result['42:108']['quantity']);
    }

    /**
     * Quantité supérieure à 1
     */
    public function testAggregateQuantityGreaterThanOne()
    {
        $subscriptions = [
            [
                'content' => [
                    ['external_id' => '42:108', 'quantity' => 5],
                ],
            ],
        ];

        $result = StockForecastAggregator::aggregateFromSubscriptions($subscriptions, true);

        $this->assertEquals(5, $result['42:108']['quantity']);
    }

    /**
     * Produit simple (id_product_attribute = 0)
     */
    public function testAggregateSimpleProduct()
    {
        $subscriptions = [
            [
                'content' => [
                    ['external_id' => '42:0', 'quantity' => 1],
                ],
            ],
        ];

        $result = StockForecastAggregator::aggregateFromSubscriptions($subscriptions, true);

        $this->assertArrayHasKey('42:0', $result);
        $this->assertEquals(42, $result['42:0']['id_product']);
        $this->assertEquals(0, $result['42:0']['id_product_attribute']);
    }

    // =========================================================================
    // Tests aggregateFromSubscriptions - mode attributs
    // =========================================================================

    /**
     * Mode attributs : id_product = 0, résolution via BDD
     */
    public function testAggregateAttributeModeResolvesProductId()
    {
        // Simuler la réponse BDD pour résolution id_product
        \Db::setMockExecuteS([
            ['id_product_attribute' => '108', 'id_product' => '42'],
        ]);

        $subscriptions = [
            [
                'content' => [
                    ['external_id' => '108', 'quantity' => 3],
                ],
            ],
        ];

        $result = StockForecastAggregator::aggregateFromSubscriptions($subscriptions, false);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('42:108', $result);
        $this->assertEquals(42, $result['42:108']['id_product']);
        $this->assertEquals(108, $result['42:108']['id_product_attribute']);
        $this->assertEquals(3, $result['42:108']['quantity']);
    }

    /**
     * Mode attributs : plusieurs produits, résolution batch
     */
    public function testAggregateAttributeModeBatchResolution()
    {
        \Db::setMockExecuteS([
            ['id_product_attribute' => '108', 'id_product' => '42'],
            ['id_product_attribute' => '200', 'id_product' => '55'],
        ]);

        $subscriptions = [
            [
                'content' => [
                    ['external_id' => '108', 'quantity' => 1],
                    ['external_id' => '200', 'quantity' => 2],
                ],
            ],
        ];

        $result = StockForecastAggregator::aggregateFromSubscriptions($subscriptions, false);

        $this->assertCount(2, $result);
        $this->assertEquals(42, $result['42:108']['id_product']);
        $this->assertEquals(55, $result['55:200']['id_product']);
    }

    // =========================================================================
    // Tests enrichWithStockData
    // =========================================================================

    /**
     * Enrichissement avec données de stock
     */
    public function testEnrichWithStockDataAddsStockInfo()
    {
        \Configuration::updateValue('PS_LANG_DEFAULT', 1);
        \StockAvailable::setMockStock(42, 108, 50);
        \Product::setMockName(42, 'Café Bio');
        \Combination::setMockAttributes(108, [['name' => '250g']]);

        $needs = [
            '42:108' => [
                'id_product' => 42,
                'id_product_attribute' => 108,
                'quantity' => 10,
            ],
        ];

        $result = StockForecastAggregator::enrichWithStockData($needs);

        $this->assertEquals(50, $result['42:108']['current_stock']);
        $this->assertEquals(40, $result['42:108']['stock_after']);
        $this->assertFalse($result['42:108']['alert']);
        $this->assertEquals('Café Bio', $result['42:108']['product_name']);
        $this->assertEquals('250g', $result['42:108']['combination_name']);
    }

    /**
     * Alerte si stock insuffisant
     */
    public function testEnrichWithStockDataAlertWhenInsufficient()
    {
        \Configuration::updateValue('PS_LANG_DEFAULT', 1);
        \StockAvailable::setMockStock(42, 108, 5);
        \Product::setMockName(42, 'Café Bio');

        $needs = [
            '42:108' => [
                'id_product' => 42,
                'id_product_attribute' => 108,
                'quantity' => 10,
            ],
        ];

        $result = StockForecastAggregator::enrichWithStockData($needs);

        $this->assertEquals(5, $result['42:108']['current_stock']);
        $this->assertEquals(-5, $result['42:108']['stock_after']);
        $this->assertTrue($result['42:108']['alert']);
    }

    /**
     * Produit simple sans combinaison
     */
    public function testEnrichWithStockDataSimpleProduct()
    {
        \Configuration::updateValue('PS_LANG_DEFAULT', 1);
        \StockAvailable::setMockStock(42, 0, 100);
        \Product::setMockName(42, 'Thé Vert');

        $needs = [
            '42:0' => [
                'id_product' => 42,
                'id_product_attribute' => 0,
                'quantity' => 20,
            ],
        ];

        $result = StockForecastAggregator::enrichWithStockData($needs);

        $this->assertEquals('Thé Vert', $result['42:0']['product_name']);
        $this->assertEquals('', $result['42:0']['combination_name']);
        $this->assertFalse($result['42:0']['alert']);
    }

    /**
     * Produit inconnu : nom par défaut
     */
    public function testEnrichWithStockDataUnknownProductDefaultName()
    {
        \Configuration::updateValue('PS_LANG_DEFAULT', 1);

        $needs = [
            '99:0' => [
                'id_product' => 99,
                'id_product_attribute' => 0,
                'quantity' => 1,
            ],
        ];

        $result = StockForecastAggregator::enrichWithStockData($needs);

        $this->assertEquals('Product #99', $result['99:0']['product_name']);
    }
}
