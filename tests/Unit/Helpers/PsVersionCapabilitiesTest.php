<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Helpers\PsVersionCapabilities;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Vérifie les bornes de version des capacités PrestaShop.
 *
 * Les versions d'introduction sont vérifiées sur les tags officiels :
 * displayProductActions = 1.7.6.0, displayAdminOrderMainBottom = 1.7.7.0,
 * actionAfterUpdateProductFormHandler = 1.7.8.0, actionPresentPaymentOptions = 8.0.0.
 */
class PsVersionCapabilitiesTest extends TestCase
{
    public function testMigratedOrderPageRequires177()
    {
        $this->assertFalse(PsVersionCapabilities::hasMigratedOrderPage('1.7.6.9'));
        $this->assertTrue(PsVersionCapabilities::hasMigratedOrderPage('1.7.7.0'));
        $this->assertTrue(PsVersionCapabilities::hasMigratedOrderPage('8.1.0'));
    }

    public function testProductActionsHookRequires176()
    {
        $this->assertFalse(PsVersionCapabilities::hasProductActionsHook('1.7.5.2'));
        $this->assertTrue(PsVersionCapabilities::hasProductActionsHook('1.7.6.0'));
        $this->assertTrue(PsVersionCapabilities::hasProductActionsHook('1.7.7.0'));
    }

    public function testProductFormHandlerHookRequires178()
    {
        $this->assertFalse(PsVersionCapabilities::hasProductFormHandlerHook('1.7.7.8'));
        $this->assertTrue(PsVersionCapabilities::hasProductFormHandlerHook('1.7.8.0'));
        $this->assertTrue(PsVersionCapabilities::hasProductFormHandlerHook('8.0.0'));
    }

    public function testPresentPaymentOptionsRequiresPs8()
    {
        $this->assertFalse(PsVersionCapabilities::hasPresentPaymentOptions('1.7.8.11'));
        $this->assertTrue(PsVersionCapabilities::hasPresentPaymentOptions('8.0.0'));
        $this->assertTrue(PsVersionCapabilities::hasPresentPaymentOptions('9.0.0'));
    }

    public function testRawSummaryDetailsRequires177()
    {
        $this->assertFalse(PsVersionCapabilities::hasRawSummaryDetails('1.7.6.9'));
        $this->assertTrue(PsVersionCapabilities::hasRawSummaryDetails('1.7.7.0'));
        $this->assertTrue(PsVersionCapabilities::hasRawSummaryDetails('8.0.0'));
    }

    public function testOldestSupportedTargetIsLegacyEverywhere()
    {
        // PrestaShop 1.7.0 : aucune des capacités modernes
        $this->assertFalse(PsVersionCapabilities::hasMigratedOrderPage('1.7.0.0'));
        $this->assertFalse(PsVersionCapabilities::hasProductActionsHook('1.7.0.0'));
        $this->assertFalse(PsVersionCapabilities::hasProductFormHandlerHook('1.7.0.0'));
        $this->assertFalse(PsVersionCapabilities::hasPresentPaymentOptions('1.7.0.0'));
    }
}
