<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Gateway;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Gateway\OrderGateway;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Teste les méthodes de logging de OrderGateway
 *
 * Vérifie le comportement du try/catch autour de validateOrder :
 * logging des échecs et détection des appels lents.
 */
class OrderGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        \PrestaShopLogger::resetLogs();
    }

    // =========================================================================
    // Tests logValidateOrderFailure
    // =========================================================================

    /**
     * Vérifie qu'un échec de validateOrder est logué
     */
    public function testLogValidateOrderFailureCreatesLog()
    {
        $exception = new \RuntimeException('Hook tiers en erreur');

        OrderGateway::logValidateOrderFailure($exception, 42);

        $this->assertCount(1, \PrestaShopLogger::$logs);
    }

    /**
     * Vérifie que le message de log contient le message d'exception
     */
    public function testLogValidateOrderFailureContainsExceptionMessage()
    {
        $exception = new \RuntimeException('Module promo crash');

        OrderGateway::logValidateOrderFailure($exception, 42);

        $this->assertStringContainsString('Module promo crash', \PrestaShopLogger::$logs[0]['message']);
    }

    /**
     * Vérifie que le message de log contient le préfixe OrderGateway
     */
    public function testLogValidateOrderFailureContainsPrefix()
    {
        $exception = new \RuntimeException('test');

        OrderGateway::logValidateOrderFailure($exception, 42);

        $this->assertStringContainsString('Ciklik OrderGateway', \PrestaShopLogger::$logs[0]['message']);
    }

    /**
     * Vérifie la sévérité erreur (3)
     */
    public function testLogValidateOrderFailureSeverityIsError()
    {
        $exception = new \RuntimeException('test');

        OrderGateway::logValidateOrderFailure($exception, 42);

        $this->assertEquals(3, \PrestaShopLogger::$logs[0]['severity']);
    }

    /**
     * Vérifie que l'ID du panier est logué
     */
    public function testLogValidateOrderFailureContainsCartId()
    {
        $exception = new \RuntimeException('test');

        OrderGateway::logValidateOrderFailure($exception, 99);

        $this->assertEquals(99, \PrestaShopLogger::$logs[0]['objectId']);
    }

    /**
     * Vérifie que le type d'objet est Cart
     */
    public function testLogValidateOrderFailureObjectTypeIsCart()
    {
        $exception = new \RuntimeException('test');

        OrderGateway::logValidateOrderFailure($exception, 42);

        $this->assertEquals('Cart', \PrestaShopLogger::$logs[0]['objectType']);
    }

    /**
     * Vérifie que les Error PHP (TypeError, etc.) sont aussi logués
     */
    public function testLogValidateOrderFailureCatchesPhpError()
    {
        $error = new \TypeError('Argument must be of type int');

        OrderGateway::logValidateOrderFailure($error, 42);

        $this->assertCount(1, \PrestaShopLogger::$logs);
        $this->assertStringContainsString('Argument must be of type int', \PrestaShopLogger::$logs[0]['message']);
    }

    // =========================================================================
    // Tests logSlowValidateOrder
    // =========================================================================

    /**
     * Vérifie qu'aucun log n'est créé si validateOrder est rapide
     */
    public function testLogSlowValidateOrderNoLogWhenFast()
    {
        OrderGateway::logSlowValidateOrder(2.5, 42);

        $this->assertEmpty(\PrestaShopLogger::$logs);
    }

    /**
     * Vérifie qu'aucun log n'est créé exactement au seuil
     */
    public function testLogSlowValidateOrderNoLogAtThreshold()
    {
        OrderGateway::logSlowValidateOrder(10.0, 42);

        $this->assertEmpty(\PrestaShopLogger::$logs);
    }

    /**
     * Vérifie qu'un log est créé au-dessus du seuil
     */
    public function testLogSlowValidateOrderLogsWhenSlow()
    {
        OrderGateway::logSlowValidateOrder(15.3, 42);

        $this->assertCount(1, \PrestaShopLogger::$logs);
    }

    /**
     * Vérifie que le message contient le temps écoulé
     */
    public function testLogSlowValidateOrderContainsElapsedTime()
    {
        OrderGateway::logSlowValidateOrder(15.3, 42);

        $this->assertStringContainsString('15.3', \PrestaShopLogger::$logs[0]['message']);
    }

    /**
     * Vérifie que le message contient l'ID du panier
     */
    public function testLogSlowValidateOrderContainsCartId()
    {
        OrderGateway::logSlowValidateOrder(15.0, 77);

        $this->assertEquals(77, \PrestaShopLogger::$logs[0]['objectId']);
    }

    /**
     * Vérifie la sévérité warning (2)
     */
    public function testLogSlowValidateOrderSeverityIsWarning()
    {
        OrderGateway::logSlowValidateOrder(15.0, 42);

        $this->assertEquals(2, \PrestaShopLogger::$logs[0]['severity']);
    }

    /**
     * Vérifie que le seuil custom fonctionne
     */
    public function testLogSlowValidateOrderCustomThreshold()
    {
        // Pas de log à 3s avec seuil de 5s
        OrderGateway::logSlowValidateOrder(3.0, 42, 5.0);
        $this->assertEmpty(\PrestaShopLogger::$logs);

        // Log à 6s avec seuil de 5s
        OrderGateway::logSlowValidateOrder(6.0, 42, 5.0);
        $this->assertCount(1, \PrestaShopLogger::$logs);
    }
}
