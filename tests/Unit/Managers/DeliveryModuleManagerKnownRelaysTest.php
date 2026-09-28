<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Managers;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Managers\DeliveryModuleManager;

if (!defined('_PS_VERSION_')) {
    exit;
}

class DeliveryModuleManagerKnownRelaysTest extends TestCase
{
    protected function setUp(): void
    {
        \Db::resetMocks();
        // tableExists() lit information_schema via getValue : la table existe
        \Db::setMockGetValue('1');
    }

    /**
     * Lignes Mondial Relay comme les renvoie la base, plus recentes d'abord
     */
    private function mondialrelayRows(int $count, int $distinct): array
    {
        $rows = [];
        for ($i = 0; $i < $count; ++$i) {
            $num = str_pad((string) ($i % $distinct), 6, '0', STR_PAD_LEFT);
            $rows[] = [
                'selected_relay_num' => $num,
                'selected_relay_adr1' => 'RELAIS ' . $num,
                'selected_relay_adr2' => '',
                'selected_relay_adr3' => $i . ' RUE TEST',
                'selected_relay_adr4' => '',
                'selected_relay_postcode' => '75002',
                'selected_relay_city' => 'PARIS',
                'selected_relay_country_iso' => 'FR',
            ];
        }

        return $rows;
    }

    /**
     * Au plus dix relais distincts, les plus recents d'abord ; la lecture en
     * base est bornee par un LIMIT
     */
    public function testKnownRelaysAreLimitedToTenMostRecent()
    {
        \Db::setMockExecuteS($this->mondialrelayRows(40, 40));

        $known = DeliveryModuleManager::getKnownRelays(42, 'mondialrelay');

        $this->assertSame(10, DeliveryModuleManager::KNOWN_RELAYS_LIMIT);
        $this->assertCount(10, $known);
        $this->assertSame('000000', $known[0]['relay_id']);
        $this->assertSame('000009', $known[9]['relay_id']);
        $this->assertSame('0 RUE TEST', $known[0]['address1']);
    }

    /**
     * Le dedoublonnage precede la coupe : un relais utilise plusieurs fois ne
     * consomme qu'une place et conserve sa ligne la plus recente
     */
    public function testDuplicatesAreCollapsedBeforeSlicing()
    {
        \Db::setMockExecuteS($this->mondialrelayRows(30, 12));

        $known = DeliveryModuleManager::getKnownRelays(42, 'mondialrelay');

        $this->assertCount(10, $known);
        $this->assertSame('000000', $known[0]['relay_id']);
        $this->assertSame('0 RUE TEST', $known[0]['address1']);
        $this->assertSame(10, count(array_unique(array_column($known, 'relay_id'))));
    }

    /**
     * Relais connus tirés des seuls paniers commandés : jointure sur orders
     * (id_order renseigné chez Mondial Relay), jamais d'un panier abandonné
     */
    public function testKnownRelaysComeFromOrderedCartsOnly()
    {
        foreach (['colissimo', 'dpdfrance', 'nkmgls', 'chronopost'] as $module) {
            \Db::resetMocks();
            \Db::setMockGetValue('1');
            \Db::setMockExecuteS([]);

            DeliveryModuleManager::getKnownRelays(42, $module);

            $sql = preg_replace('/\s+/', ' ', (string) end(\Db::$queryLog));
            $this->assertStringContainsString('INNER JOIN ps_orders o ON o.id_cart = ', $sql, $module);
            $this->assertStringContainsString('o.id_customer = 42', $sql, $module);
            $this->assertStringNotContainsString('ps_cart c', $sql, $module);
        }

        \Db::resetMocks();
        \Db::setMockGetValue('1');
        DeliveryModuleManager::getKnownRelays(42, 'mondialrelay');
        $this->assertStringContainsString('id_order IS NOT NULL', (string) end(\Db::$queryLog));
    }

    /**
     * Relais triés par dernier usage : une ligne par relais, triée sur le
     * dernier panier qui l'a porté ; l'ordre de la base est conservé
     */
    public function testKnownRelaysAreSortedByLastUse()
    {
        $groupBy = [
            'mondialrelay' => 'GROUP BY selected_relay_num',
            'colissimo' => 'GROUP BY pp.id_colissimo_pickup_point',
            'dpdfrance' => 'GROUP BY ds.relay_id',
            'nkmgls' => 'GROUP BY g.parcel_shop_id',
            'chronopost' => 'GROUP BY ccr.id_pr',
        ];

        foreach ($groupBy as $module => $clause) {
            \Db::resetMocks();
            \Db::setMockGetValue('1');

            DeliveryModuleManager::getKnownRelays(42, $module);

            $sql = preg_replace('/\s+/', ' ', (string) end(\Db::$queryLog));
            $this->assertStringContainsString('MAX(', $sql, $module);
            $this->assertStringContainsString('id_cart) AS last_cart', $sql, $module);
            $this->assertStringContainsString($clause . ' ORDER BY last_cart DESC LIMIT ' . DeliveryModuleManager::KNOWN_RELAYS_SCAN, $sql, $module);
        }

        // Relais rendus dans l'ordre de la base (dernier usage d'abord)
        \Db::resetMocks();
        \Db::setMockGetValue('1');
        \Db::setMockExecuteS([['id_pr' => 'B2', 'last_cart' => '90'], ['id_pr' => 'A1', 'last_cart' => '12']]);
        $this->assertSame(['B2', 'A1'], array_column(DeliveryModuleManager::getKnownRelays(42, 'chronopost'), 'relay_id'));
    }

    /**
     * Moins de dix relais : tous rendus ; table absente : rien
     */
    public function testFewerRelaysAndMissingTable()
    {
        \Db::setMockExecuteS($this->mondialrelayRows(3, 3));
        $this->assertCount(3, DeliveryModuleManager::getKnownRelays(42, 'mondialrelay'));

        \Db::setMockGetValue('0');
        $this->assertSame([], DeliveryModuleManager::getKnownRelays(42, 'mondialrelay'));
    }
}
