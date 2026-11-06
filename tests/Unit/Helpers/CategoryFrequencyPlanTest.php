<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Helpers\CategoryFrequencyPlan;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Aperçu de l'activation de fréquences par catégorie : exclusions, ajouts seulement, empreinte
 */
class CategoryFrequencyPlanTest extends TestCase
{
    private function product(int $id, array $overrides = []): array
    {
        return $overrides + ['id_product' => $id, 'name' => 'Produit ' . $id, 'is_pack' => '0', 'is_virtual' => '0', 'active' => '1'];
    }

    public function testAddsMissingFrequenciesToActiveProducts()
    {
        $plan = CategoryFrequencyPlan::build([$this->product(1), $this->product(2)], [3, 5], [3, 5, 7], []);

        $this->assertSame(2, $plan['found']);
        $this->assertSame(2, $plan['updated']);
        $this->assertSame(0, $plan['unchanged']);
        $this->assertSame([[1, 3], [1, 5], [2, 3], [2, 5]], $plan['pairs']);
        $this->assertSame(4, $plan['rows']);
        $this->assertSame(['Produit 1', 'Produit 2'], $plan['names']);
        $this->assertSame(0, $plan['more']);
    }

    public function testPacksVirtualAndInactiveProductsAreExcludedOnceEach()
    {
        $products = [
            $this->product(1, ['is_pack' => '1', 'active' => '0']),
            $this->product(2, ['is_virtual' => '1']),
            $this->product(3, ['active' => '0']),
            $this->product(4),
        ];

        $plan = CategoryFrequencyPlan::build($products, [3], [3], []);

        $this->assertSame(4, $plan['found']);
        $this->assertSame(['pack' => 1, 'virtual' => 1, 'inactive' => 1], $plan['excluded']);
        $this->assertSame([[4, 3]], $plan['pairs']);
    }

    public function testExistingPairsAreNotCountedAgainAndNothingIsRemoved()
    {
        $existing = [1 => [3, 9], 2 => [3]];

        $plan = CategoryFrequencyPlan::build([$this->product(1), $this->product(2)], [3, 5], [3, 5, 9], $existing);

        $this->assertSame([[1, 5], [2, 5]], $plan['pairs']);
        $this->assertSame(2, $plan['updated']);

        $plan = CategoryFrequencyPlan::build([$this->product(1), $this->product(2)], [3], [3, 5, 9], $existing);

        $this->assertSame([], $plan['pairs']);
        $this->assertSame(2, $plan['unchanged']);
        $this->assertSame(0, $plan['updated']);
    }

    public function testEmptyCategory()
    {
        $plan = CategoryFrequencyPlan::build([], [3], [3], []);

        $this->assertSame(0, $plan['found']);
        $this->assertSame(0, $plan['rows']);
    }

    public function testOnlyTwentyNamesAreListed()
    {
        $products = [];
        for ($i = 1; $i <= 25; ++$i) {
            $products[] = $this->product($i);
        }

        $plan = CategoryFrequencyPlan::build($products, [3], [3], []);

        $this->assertCount(20, $plan['names']);
        $this->assertSame(5, $plan['more']);
    }

    public function testUnknownFrequencyIsRefused()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown_frequency');

        CategoryFrequencyPlan::build([$this->product(1)], [3, 42], [3], []);
    }

    public function testEmptySelectionIsRefused()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no_frequency');

        CategoryFrequencyPlan::build([$this->product(1)], [], [3], []);
    }

    public function testFingerprintChangesWithTheCatalog()
    {
        $before = CategoryFrequencyPlan::build([$this->product(1)], [3], [3], []);
        $same = CategoryFrequencyPlan::build([$this->product(1)], [3], [3], []);
        $after = CategoryFrequencyPlan::build([$this->product(1), $this->product(2)], [3], [3], []);

        $this->assertSame($before['fingerprint'], $same['fingerprint']);
        $this->assertNotSame($before['fingerprint'], $after['fingerprint']);
    }
}
