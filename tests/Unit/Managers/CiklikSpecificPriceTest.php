<?php

/**
 * @author    Metrogeek SAS <support@ciklik.co>
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
    protected function setUp(): void
    {
        \Db::resetMocks();
        \PrestaShopLogger::resetLogs();
    }

    // =========================================================================
    // Tests computeNetPrice
    // =========================================================================

    /**
     * Reduction pourcentage 10% sur un prix de base de 100
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
     * Reduction montant fixe de 5 sur un prix de base de 100
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
     * Reduction montant superieure au prix de base : le prix final doit etre 0
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
     * Prix de base a 0 : doit retourner false
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
     * Prix de base negatif : doit retourner false
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
     * Aucune reduction definie : doit retourner false
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
     * Si discount_price et discount_percent sont tous les deux definis,
     * discount_price est prioritaire (teste en premier)
     */
    public function testComputeNetPriceAmountTakesPriorityOverPercent()
    {
        $result = CiklikSpecificPrice::computeNetPrice(100.0, [
            'discount_price' => 5,
            'discount_percent' => 10,
        ]);

        // discount_price est teste en premier, donc 100 - 5 = 95
        $this->assertEquals(95.0, $result);
    }

    /**
     * Precision decimale : 128.20 - 10% = 115.38
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
     * Reduction de 100% : le prix final doit etre 0
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
     * Frequence sans cles de reduction : doit retourner false
     */
    public function testComputeNetPriceWithEmptyFrequency()
    {
        $result = CiklikSpecificPrice::computeNetPrice(100.0, []);

        $this->assertFalse($result);
    }

    /**
     * Reduction pourcentage avec valeur en chaine de caracteres
     */
    public function testComputeNetPriceWithStringValues()
    {
        $result = CiklikSpecificPrice::computeNetPrice(100.0, [
            'discount_percent' => '15',
            'discount_price' => '0',
        ]);

        $this->assertEquals(85.0, $result);
    }

    // =========================================================================
    // Tests transferFromGuestToCustomer
    // =========================================================================

    /**
     * Pas de prix specifiques trouves : retourne false
     */
    public function testTransferNoSpecificPricesReturnsFalse()
    {
        \Db::setMockExecuteS([]);

        $result = CiklikSpecificPrice::transferFromGuestToCustomer(42, 7);

        $this->assertFalse($result);
        $this->assertEmpty(\Db::$updateCalls);
    }

    /**
     * executeS retourne false : retourne false
     */
    public function testTransferExecuteSReturnsFalseReturnsFalse()
    {
        \Db::setMockExecuteS(false);

        $result = CiklikSpecificPrice::transferFromGuestToCustomer(42, 7);

        $this->assertFalse($result);
        $this->assertEmpty(\Db::$updateCalls);
    }

    /**
     * Un prix specifique trouve, update reussit : retourne true
     */
    public function testTransferSingleSpecificPriceSuccess()
    {
        \Db::setMockExecuteS([
            ['id_specific_price' => 101, 'id_product' => 5, 'id_customer' => 0],
        ]);
        \Db::setMockUpdateResults([true]);

        $result = CiklikSpecificPrice::transferFromGuestToCustomer(42, 7);

        $this->assertTrue($result);
        $this->assertCount(1, \Db::$updateCalls);
        $this->assertEquals('specific_price', \Db::$updateCalls[0]['table']);
        $this->assertEquals(['id_customer' => 7], \Db::$updateCalls[0]['data']);
        $this->assertEquals('id_specific_price = 101', \Db::$updateCalls[0]['where']);
    }

    /**
     * Plusieurs prix specifiques, tous les updates reussissent
     */
    public function testTransferMultipleSpecificPricesAllSuccess()
    {
        \Db::setMockExecuteS([
            ['id_specific_price' => 101, 'id_product' => 5, 'id_customer' => 0],
            ['id_specific_price' => 102, 'id_product' => 8, 'id_customer' => 0],
        ]);
        \Db::setMockUpdateResults([true, true]);

        $result = CiklikSpecificPrice::transferFromGuestToCustomer(42, 7);

        $this->assertTrue($result);
        $this->assertCount(2, \Db::$updateCalls);
        $this->assertEquals('id_specific_price = 101', \Db::$updateCalls[0]['where']);
        $this->assertEquals('id_specific_price = 102', \Db::$updateCalls[1]['where']);
    }

    /**
     * Plusieurs prix, premier reussit, deuxieme echoue : retourne true
     */
    public function testTransferPartialSuccessReturnsTrue()
    {
        \Db::setMockExecuteS([
            ['id_specific_price' => 101, 'id_product' => 5, 'id_customer' => 0],
            ['id_specific_price' => 102, 'id_product' => 8, 'id_customer' => 0],
        ]);
        \Db::setMockUpdateResults([true, false]);

        $result = CiklikSpecificPrice::transferFromGuestToCustomer(42, 7);

        $this->assertTrue($result);
    }

    /**
     * Tous les updates echouent : retourne false
     */
    public function testTransferAllUpdatesFailReturnsFalse()
    {
        \Db::setMockExecuteS([
            ['id_specific_price' => 101, 'id_product' => 5, 'id_customer' => 0],
        ]);
        \Db::setMockUpdateResults([false]);

        $result = CiklikSpecificPrice::transferFromGuestToCustomer(42, 7);

        $this->assertFalse($result);
    }

    /**
     * Exception lors de l'update : logge l'erreur, retourne false
     */
    public function testTransferExceptionIsLoggedAndReturnsFalse()
    {
        \Db::setMockExecuteS([
            ['id_specific_price' => 101, 'id_product' => 5, 'id_customer' => 0],
        ]);
        \Db::setMockUpdateResults([new \RuntimeException('DB connection lost')]);

        $result = CiklikSpecificPrice::transferFromGuestToCustomer(42, 7);

        $this->assertFalse($result);
        $this->assertNotEmpty(\PrestaShopLogger::$logs);
        $this->assertStringContainsString('DB connection lost', \PrestaShopLogger::$logs[0]['message']);
    }

    /**
     * Exception sur le 2eme update, 1er reussit : retourne true
     */
    public function testTransferExceptionOnSecondUpdateStillReturnsTrue()
    {
        \Db::setMockExecuteS([
            ['id_specific_price' => 101, 'id_product' => 5, 'id_customer' => 0],
            ['id_specific_price' => 102, 'id_product' => 8, 'id_customer' => 0],
        ]);
        \Db::setMockUpdateResults([true, new \RuntimeException('DB error')]);

        $result = CiklikSpecificPrice::transferFromGuestToCustomer(42, 7);

        $this->assertTrue($result);
        $this->assertNotEmpty(\PrestaShopLogger::$logs);
    }

    /**
     * Verifie que le cast (int) protege les parametres
     */
    public function testTransferCastsParametersToInt()
    {
        \Db::setMockExecuteS([
            ['id_specific_price' => '999', 'id_product' => '5', 'id_customer' => 0],
        ]);
        \Db::setMockUpdateResults([true]);

        $result = CiklikSpecificPrice::transferFromGuestToCustomer(42, 7);

        $this->assertTrue($result);
        $this->assertEquals(['id_customer' => 7], \Db::$updateCalls[0]['data']);
        $this->assertEquals('id_specific_price = 999', \Db::$updateCalls[0]['where']);
    }
}
