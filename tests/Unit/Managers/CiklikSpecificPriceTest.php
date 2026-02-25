<?php

/**
 * @author    Ciklik SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Managers;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Managers\CiklikSpecificPrice;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CiklikSpecificPriceTest extends TestCase
{
    /**
     * Réduction pourcentage 10% sur un prix de base de 100
     */
    public function testComputeNetPriceWithPercentDiscount()
    {
        $result = CiklikSpecificPrice::computeNetPrice(100.0, [
            'discount_percent' => 10,
            'discount_price' => 0,
        ]);

        $this->assertEquals(90.0, $result);
    }

    /**
     * Réduction montant fixe de 5 sur un prix de base de 100
     */
    public function testComputeNetPriceWithAmountDiscount()
    {
        $result = CiklikSpecificPrice::computeNetPrice(100.0, [
            'discount_price' => 5,
            'discount_percent' => 0,
        ]);

        $this->assertEquals(95.0, $result);
    }

    /**
     * Réduction montant supérieure au prix de base : le prix final doit être 0
     */
    public function testComputeNetPriceWithDiscountGreaterThanBase()
    {
        $result = CiklikSpecificPrice::computeNetPrice(3.0, [
            'discount_price' => 5,
            'discount_percent' => 0,
        ]);

        $this->assertEquals(0.0, $result);
    }

    /**
     * Prix de base à 0 : doit retourner false
     */
    public function testComputeNetPriceWithZeroBasePrice()
    {
        $result = CiklikSpecificPrice::computeNetPrice(0.0, [
            'discount_percent' => 10,
            'discount_price' => 0,
        ]);

        $this->assertFalse($result);
    }

    /**
     * Prix de base négatif : doit retourner false
     */
    public function testComputeNetPriceWithNegativeBasePrice()
    {
        $result = CiklikSpecificPrice::computeNetPrice(-5.0, [
            'discount_percent' => 10,
            'discount_price' => 0,
        ]);

        $this->assertFalse($result);
    }

    /**
     * Aucune réduction définie : doit retourner false
     */
    public function testComputeNetPriceWithNoDiscount()
    {
        $result = CiklikSpecificPrice::computeNetPrice(100.0, [
            'discount_percent' => 0,
            'discount_price' => 0,
        ]);

        $this->assertFalse($result);
    }

    /**
     * Si discount_price et discount_percent sont tous les deux définis,
     * discount_price est prioritaire (testé en premier)
     */
    public function testComputeNetPriceAmountTakesPriorityOverPercent()
    {
        $result = CiklikSpecificPrice::computeNetPrice(100.0, [
            'discount_price' => 5,
            'discount_percent' => 10,
        ]);

        // discount_price est testé en premier, donc 100 - 5 = 95
        $this->assertEquals(95.0, $result);
    }

    /**
     * Précision décimale : 128.20 - 10% = 115.38
     */
    public function testComputeNetPriceDecimalPrecision()
    {
        $result = CiklikSpecificPrice::computeNetPrice(128.20, [
            'discount_percent' => 10,
            'discount_price' => 0,
        ]);

        $this->assertEquals(115.38, $result);
    }

    /**
     * Réduction de 100% : le prix final doit être 0
     */
    public function testComputeNetPriceWith100PercentDiscount()
    {
        $result = CiklikSpecificPrice::computeNetPrice(100.0, [
            'discount_percent' => 100,
            'discount_price' => 0,
        ]);

        $this->assertEquals(0.0, $result);
    }

    /**
     * Fréquence sans clés de réduction : doit retourner false
     */
    public function testComputeNetPriceWithEmptyFrequency()
    {
        $result = CiklikSpecificPrice::computeNetPrice(100.0, []);

        $this->assertFalse($result);
    }

    /**
     * Réduction pourcentage avec valeur en chaîne de caractères
     */
    public function testComputeNetPriceWithStringValues()
    {
        $result = CiklikSpecificPrice::computeNetPrice(100.0, [
            'discount_percent' => '15',
            'discount_price' => '0',
        ]);

        $this->assertEquals(85.0, $result);
    }
}
