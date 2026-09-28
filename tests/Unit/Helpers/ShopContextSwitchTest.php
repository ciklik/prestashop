<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Helpers\ShopContextSwitch;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Bascule du contexte boutique le temps des actions relais, puis
 * restauration du contexte de la requête
 */
class ShopContextSwitchTest extends TestCase
{
    protected function setUp(): void
    {
        \Shop::resetMocks();
    }

    protected function tearDown(): void
    {
        \Shop::resetMocks();
    }

    /**
     * Boutique de la requête 1, abonnement de la boutique 3 : bascule puis
     * retour à la boutique 1
     */
    public function testSwitchesThenRestoresShopContext()
    {
        \Shop::$featureActive = true;
        \Shop::$context = \Shop::CONTEXT_SHOP;
        \Shop::$contextShopId = 1;

        $switch = new ShopContextSwitch();
        $this->assertTrue($switch->switchTo(3));
        $this->assertSame(3, \Shop::getContextShopID());

        // Seconde bascule : le contexte d'origine reste celui de la requête
        $this->assertTrue($switch->switchTo(4));

        $switch->restore();
        $this->assertSame(\Shop::CONTEXT_SHOP, \Shop::getContext());
        $this->assertSame(1, \Shop::getContextShopID());
        $this->assertSame([[1, 3], [1, 4], [1, 1]], \Shop::$setContextCalls);

        // Idempotent
        $switch->restore();
        $this->assertCount(3, \Shop::$setContextCalls);
    }

    /**
     * Contexte d'origine « groupe » ou « toutes boutiques » restauré tel quel
     */
    public function testRestoresGroupOrAllContext()
    {
        \Shop::$featureActive = true;

        \Shop::$context = \Shop::CONTEXT_GROUP;
        \Shop::$contextGroupId = 2;
        $switch = new ShopContextSwitch();
        $switch->switchTo(3);
        $switch->restore();
        $this->assertSame([\Shop::CONTEXT_GROUP, 2], end(\Shop::$setContextCalls));

        \Shop::$context = \Shop::CONTEXT_ALL;
        $switch = new ShopContextSwitch();
        $switch->switchTo(3);
        $switch->restore();
        $this->assertSame([\Shop::CONTEXT_ALL, null], end(\Shop::$setContextCalls));
    }

    /**
     * Sans multiboutique, ou sans boutique connue : aucune bascule, rien à restaurer
     */
    public function testNoSwitchWithoutMultishopOrShop()
    {
        $switch = new ShopContextSwitch();
        $this->assertFalse($switch->switchTo(3));

        \Shop::$featureActive = true;
        $this->assertFalse($switch->switchTo(0));

        $switch->restore();
        $this->assertSame([], \Shop::$setContextCalls);
    }
}
