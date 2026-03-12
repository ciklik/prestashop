<?php

/**
 * @author    Ciklik SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Gateway;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Data\CartFingerprintData;
use PrestaShop\Module\Ciklik\Gateway\CartGateway;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CartGatewayHookTest extends TestCase
{
    protected function setUp(): void
    {
        \Hook::resetMocks();
        \PrestaShopLogger::resetLogs();
    }

    /**
     * Crée un CartFingerprintData de test avec des valeurs par défaut
     */
    private function createFingerprint(array $overrides = [])
    {
        return CartFingerprintData::create(array_merge([
            'id_customer' => 1,
            'id_address_delivery' => 10,
            'id_address_invoice' => 11,
            'id_lang' => 1,
            'id_currency' => 1,
            'id_carrier_reference' => 2,
        ], $overrides));
    }

    // =========================================================================
    // Tests executePreRebillHook - appel normal
    // =========================================================================

    /**
     * Le hook est appelé exactement une fois
     */
    public function testHookIsCalledOnce()
    {
        $cart = new \Cart(42);
        $fingerprint = $this->createFingerprint();

        CartGateway::executePreRebillHook($cart, $fingerprint);

        $this->assertCount(1, \Hook::$calls);
    }

    /**
     * Le hook est appelé avec le bon nom
     */
    public function testHookIsCalledWithCorrectName()
    {
        $cart = new \Cart(42);
        $fingerprint = $this->createFingerprint();

        CartGateway::executePreRebillHook($cart, $fingerprint);

        $this->assertEquals('actionCiklikCartBeforeRebill', \Hook::$calls[0]['hookName']);
    }

    /**
     * Le hook reçoit le panier en paramètre
     */
    public function testHookReceivesCart()
    {
        $cart = new \Cart(42);
        $fingerprint = $this->createFingerprint();

        CartGateway::executePreRebillHook($cart, $fingerprint);

        $this->assertSame($cart, \Hook::$calls[0]['params']['cart']);
    }

    /**
     * Le hook reçoit le fingerprint en paramètre
     */
    public function testHookReceivesFingerprint()
    {
        $cart = new \Cart(42);
        $fingerprint = $this->createFingerprint();

        CartGateway::executePreRebillHook($cart, $fingerprint);

        $this->assertSame($fingerprint, \Hook::$calls[0]['params']['cartFingerprintData']);
    }

    /**
     * Le hook transmet les bonnes clés dans les paramètres
     */
    public function testHookParamsContainExpectedKeys()
    {
        $cart = new \Cart(1);
        $fingerprint = $this->createFingerprint();

        CartGateway::executePreRebillHook($cart, $fingerprint);

        $params = \Hook::$calls[0]['params'];
        $this->assertArrayHasKey('cart', $params);
        $this->assertArrayHasKey('cartFingerprintData', $params);
        $this->assertCount(2, $params);
    }

    // =========================================================================
    // Tests executePreRebillHook - aucune erreur loguée en cas de succès
    // =========================================================================

    /**
     * Aucun log en cas de succès
     */
    public function testNoLogOnSuccess()
    {
        $cart = new \Cart(42);
        $fingerprint = $this->createFingerprint();

        CartGateway::executePreRebillHook($cart, $fingerprint);

        $this->assertEmpty(\PrestaShopLogger::$logs);
    }

    // =========================================================================
    // Tests executePreRebillHook - gestion des exceptions
    // =========================================================================

    /**
     * Une exception est attrapée sans propager
     */
    public function testExceptionIsCaughtAndNotPropagated()
    {
        $cart = new \Cart(42);
        $fingerprint = $this->createFingerprint();
        \Hook::setThrowException(new \RuntimeException('Module tiers en erreur'));

        // Ne doit pas lever d'exception
        CartGateway::executePreRebillHook($cart, $fingerprint);

        $this->assertTrue(true);
    }

    /**
     * Une exception est loguée avec le bon message
     */
    public function testExceptionIsLogged()
    {
        $cart = new \Cart(42);
        $fingerprint = $this->createFingerprint();
        \Hook::setThrowException(new \RuntimeException('Erreur promo code'));

        CartGateway::executePreRebillHook($cart, $fingerprint);

        $this->assertCount(1, \PrestaShopLogger::$logs);
        $this->assertStringContainsString('Erreur promo code', \PrestaShopLogger::$logs[0]['message']);
    }

    /**
     * Le message de log contient le préfixe du hook
     */
    public function testLogMessageContainsHookPrefix()
    {
        $cart = new \Cart(42);
        $fingerprint = $this->createFingerprint();
        \Hook::setThrowException(new \RuntimeException('test'));

        CartGateway::executePreRebillHook($cart, $fingerprint);

        $this->assertStringContainsString('actionCiklikCartBeforeRebill', \PrestaShopLogger::$logs[0]['message']);
    }

    /**
     * Le log a la bonne sévérité (3 = erreur)
     */
    public function testLogSeverityIsError()
    {
        $cart = new \Cart(42);
        $fingerprint = $this->createFingerprint();
        \Hook::setThrowException(new \RuntimeException('test'));

        CartGateway::executePreRebillHook($cart, $fingerprint);

        $this->assertEquals(3, \PrestaShopLogger::$logs[0]['severity']);
    }

    /**
     * Le log contient le bon objectType
     */
    public function testLogObjectTypeIsCartGateway()
    {
        $cart = new \Cart(42);
        $fingerprint = $this->createFingerprint();
        \Hook::setThrowException(new \RuntimeException('test'));

        CartGateway::executePreRebillHook($cart, $fingerprint);

        $this->assertEquals('CartGateway', \PrestaShopLogger::$logs[0]['objectType']);
    }

    /**
     * Le log contient l'ID du panier
     */
    public function testLogContainsCartId()
    {
        $cart = new \Cart(99);
        $fingerprint = $this->createFingerprint();
        \Hook::setThrowException(new \RuntimeException('test'));

        CartGateway::executePreRebillHook($cart, $fingerprint);

        $this->assertEquals(99, \PrestaShopLogger::$logs[0]['objectId']);
    }

    /**
     * Différents types d'exceptions sont attrapés (Exception de base)
     */
    public function testCatchesBaseException()
    {
        $cart = new \Cart(42);
        $fingerprint = $this->createFingerprint();
        \Hook::setThrowException(new \Exception('Exception de base'));

        CartGateway::executePreRebillHook($cart, $fingerprint);

        $this->assertCount(1, \PrestaShopLogger::$logs);
        $this->assertStringContainsString('Exception de base', \PrestaShopLogger::$logs[0]['message']);
    }

    /**
     * Différents types d'exceptions sont attrapés (InvalidArgumentException)
     */
    public function testCatchesInvalidArgumentException()
    {
        $cart = new \Cart(42);
        $fingerprint = $this->createFingerprint();
        \Hook::setThrowException(new \InvalidArgumentException('Argument invalide'));

        CartGateway::executePreRebillHook($cart, $fingerprint);

        $this->assertCount(1, \PrestaShopLogger::$logs);
        $this->assertStringContainsString('Argument invalide', \PrestaShopLogger::$logs[0]['message']);
    }
}
