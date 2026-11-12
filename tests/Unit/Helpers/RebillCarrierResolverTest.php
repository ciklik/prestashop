<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Helpers\RebillCarrierResolver;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Transporteur du renouvellement quand celui de l'abonnement a disparu
 */
class RebillCarrierResolverTest extends TestCase
{
    protected function setUp(): void
    {
        \Carrier::resetMocks();
        \Configuration::resetMocks();
    }

    protected function tearDown(): void
    {
        \Carrier::resetMocks();
        \Configuration::resetMocks();
    }

    public function testKeepsTheSubscriptionCarrier()
    {
        \Carrier::setMockCarrier(12, ['id_reference' => 173]);
        \Configuration::updateValue('PS_CARRIER_DEFAULT', 3);
        \Carrier::setMockCarrier(3, []);

        $this->assertSame(12, RebillCarrierResolver::resolve(173, 9, [3])->id);
    }

    public function testFallsBackToTheShopDefaultCarrier()
    {
        \Configuration::updateValue('PS_CARRIER_DEFAULT', 3);
        \Carrier::setMockCarrier(3, []);

        $this->assertSame(3, RebillCarrierResolver::resolve(173, 9, [3])->id);
    }

    /**
     * Transporteur de l'abonnement supprimé et défaut à « meilleur prix » (-1)
     */
    public function testFallsBackToACarrierOfTheZoneWhenTheDefaultIsBestPrice()
    {
        \Configuration::updateValue('PS_CARRIER_DEFAULT', -1);
        \Carrier::setMockCarrier(7, ['active' => false]);
        \Carrier::setMockCarrier(8, []);
        \Carrier::$zoneCarriers = [['id_carrier' => 7], ['id_carrier' => 8]];

        $this->assertSame(8, RebillCarrierResolver::resolve(173, 9, [3])->id);
    }

    public function testSkipsAnInactiveDefaultCarrier()
    {
        \Configuration::updateValue('PS_CARRIER_DEFAULT', 3);
        \Carrier::setMockCarrier(3, ['active' => false]);
        \Carrier::setMockCarrier(8, []);
        \Carrier::$zoneCarriers = [['id_carrier' => 8]];

        $this->assertSame(8, RebillCarrierResolver::resolve(173, 9, [3])->id);
    }

    public function testReturnsNullWhenNoCarrierDeliversTheCustomer()
    {
        \Configuration::updateValue('PS_CARRIER_DEFAULT', -1);

        $this->assertNull(RebillCarrierResolver::resolve(173, 9, [3]));
    }
}
