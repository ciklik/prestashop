<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Helpers\RelaySearchQuota;

if (!defined('_PS_VERSION_')) {
    exit;
}

class RelaySearchQuotaTest extends TestCase
{
    protected function setUp(): void
    {
        \Db::resetMocks();
        \PrestaShopLogger::resetLogs();
        \Configuration::resetMocks();
    }

    /**
     * Premier appel : autorise, compteur a 1, fenetre ouverte maintenant
     */
    public function testFirstCallOpensWindow()
    {
        $result = RelaySearchQuota::decide(null, 0, 1000, 3, 60, 100);

        $this->assertTrue($result['allowed']);
        $this->assertSame(1000, $result['window_start']);
        $this->assertSame(1, $result['count']);
    }

    /**
     * Le compteur s'incremente jusqu'au plafond, puis refuse sans l'incrementer
     */
    public function testLimitIsEnforcedWithinWindow()
    {
        $row = null;
        for ($i = 1; $i <= 3; ++$i) {
            $result = RelaySearchQuota::decide($row, $i - 1, 1000 + $i, 3, 60, 100);
            $this->assertTrue($result['allowed'], 'appel ' . $i);
            $row = ['window_start' => $result['window_start'], 'count' => $result['count']];
        }
        $this->assertSame(['window_start' => 1001, 'count' => 3], $row);

        $refused = RelaySearchQuota::decide($row, 3, 1010, 3, 60, 100);
        $this->assertFalse($refused['allowed']);
        $this->assertSame(3, $refused['count']);
        $this->assertSame(1001, $refused['window_start']);
    }

    /**
     * Fenetre glissante : ecoulee, le compteur repart de zero au moment de l'appel
     */
    public function testWindowResetsAfterExpiry()
    {
        $result = RelaySearchQuota::decide(['window_start' => 1000, 'count' => 3], 3, 1060, 3, 60, 100);

        $this->assertTrue($result['allowed']);
        $this->assertSame(1060, $result['window_start']);
        $this->assertSame(1, $result['count']);

        // Une seconde avant l'expiration : toujours refuse
        $refused = RelaySearchQuota::decide(['window_start' => 1000, 'count' => 3], 3, 1059, 3, 60, 100);
        $this->assertFalse($refused['allowed']);
    }

    /**
     * Ligne corrompue ou debut de fenetre dans le futur : fenetre neuve
     */
    public function testCorruptedRowStartsFresh()
    {
        foreach ([
            ['window_start' => 'abc', 'count' => 3],
            ['count' => 3],
            ['window_start' => 1000],
            'chaine',
            12,
            ['window_start' => 2000, 'count' => 3],
        ] as $row) {
            $result = RelaySearchQuota::decide($row, 0, 1000, 3, 60, 100);
            $this->assertTrue($result['allowed']);
            $this->assertSame(1000, $result['window_start']);
            $this->assertSame(1, $result['count']);
        }
    }

    /**
     * Plafond boutique : refuse meme si le client est sous son propre plafond,
     * sans toucher a son compteur
     */
    public function testShopLimitRefusesEvenBelowCustomerLimit()
    {
        $refused = RelaySearchQuota::decide(['window_start' => 1000, 'count' => 1], 100, 1010, 3, 60, 100);

        $this->assertFalse($refused['allowed']);
        $this->assertSame(1, $refused['count']);

        $allowed = RelaySearchQuota::decide(['window_start' => 1000, 'count' => 1], 99, 1010, 3, 60, 100);
        $this->assertTrue($allowed['allowed']);
        $this->assertSame(2, $allowed['count']);

        // Total boutique inexploitable : traite comme zero
        $this->assertTrue(RelaySearchQuota::decide(null, 'n/a', 1000, 3, 60, 100)['allowed']);
    }

    /**
     * Valeurs par defaut : 20 appels par client et 1000 par boutique, par heure
     */
    public function testDefaults()
    {
        $this->assertSame(20, RelaySearchQuota::LIMIT);
        $this->assertSame(1000, RelaySearchQuota::SHOP_LIMIT);
        $this->assertSame(3600, RelaySearchQuota::WINDOW);

        $refused = RelaySearchQuota::decide(['window_start' => 1000, 'count' => 20], 20, 1000 + 3599);
        $this->assertFalse($refused['allowed']);

        $allowed = RelaySearchQuota::decide(['window_start' => 1000, 'count' => 20], 20, 1000 + 3600);
        $this->assertTrue($allowed['allowed']);

        $this->assertTrue(RelaySearchQuota::decide(['window_start' => 1000, 'count' => 5], 999, 1010)['allowed']);
        $this->assertFalse(RelaySearchQuota::decide(['window_start' => 1000, 'count' => 5], 1000, 1010)['allowed']);
    }

    /**
     * Persistance : sous le verrou de la boutique, le compteur du client est
     * lu puis reecrit en base (REPLACE sur la cle client/boutique), les
     * compteurs perimes sont purges, puis le verrou est libere
     */
    public function testConsumePersistsCounterUnderLock()
    {
        \Db::setMockGetRow(['window_start' => '5000', 'count' => '4']);
        \Db::setMockGetValueResults(['1', '12', '1']);

        $this->assertSame(RelaySearchQuota::GRANTED, RelaySearchQuota::consume(42, 1, 5100));

        $this->assertCount(2, \Db::$executeCalls);
        $this->assertStringContainsString('REPLACE INTO `ps_ciklik_relay_search_quota`', \Db::$executeCalls[0]);
        $this->assertStringContainsString('VALUES (42, 1, 5000, 5)', \Db::$executeCalls[0]);
        $this->assertStringContainsString('DELETE FROM `ps_ciklik_relay_search_quota`', \Db::$executeCalls[1]);
        $this->assertStringContainsString('window_start < ' . (5100 - 2 * RelaySearchQuota::WINDOW), \Db::$executeCalls[1]);

        // Ordre : verrou, lectures, ecritures, liberation du meme verrou
        $log = \Db::$queryLog;
        $lock = RelaySearchQuota::lockName(1);
        $this->assertCount(6, $log);
        $this->assertSame("SELECT GET_LOCK('" . $lock . "', " . RelaySearchQuota::LOCK_TIMEOUT . ')', $log[0]);
        $this->assertStringContainsString('SELECT window_start', $log[1]);
        $this->assertStringContainsString('SUM(`count`)', $log[2]);
        $this->assertStringContainsString('REPLACE INTO', $log[3]);
        $this->assertStringContainsString('DELETE FROM', $log[4]);
        $this->assertSame("SELECT RELEASE_LOCK('" . $lock . "')", $log[5]);
    }

    /**
     * Verrou propre a la boutique et a la base, dans la limite de 64
     * caracteres des verrous nommes
     */
    public function testLockNameIsPerShop()
    {
        $this->assertNotSame(RelaySearchQuota::lockName(1), RelaySearchQuota::lockName(2));
        $this->assertSame(RelaySearchQuota::lockName(1), RelaySearchQuota::lockName(1));
        $this->assertMatchesRegularExpression('/^ciklik_rsq_[a-f0-9]{32}$/', RelaySearchQuota::lockName(1));
        $this->assertLessThanOrEqual(64, strlen(RelaySearchQuota::lockName(1)));
    }

    /**
     * Plafond du client atteint en base : quota atteint, rien n'est ecrit,
     * verrou libere
     */
    public function testConsumeRefusesWhenCounterIsFull()
    {
        \Db::setMockGetRow(['window_start' => '5000', 'count' => (string) RelaySearchQuota::LIMIT]);
        \Db::setMockGetValueResults(['1', '20', '1']);

        $this->assertSame(RelaySearchQuota::LIMITED, RelaySearchQuota::consume(42, 1, 5100));
        $this->assertSame([], \Db::$executeCalls);
        $this->assertStringContainsString('RELEASE_LOCK', end(\Db::$queryLog));
        $this->assertSame([], \PrestaShopLogger::$logs);
    }

    /**
     * Plafond boutique atteint en base (autres clients) : quota atteint
     */
    public function testConsumeRefusesWhenShopIsFull()
    {
        \Db::setMockGetRow(false);
        \Db::setMockGetValueResults(['1', (string) RelaySearchQuota::SHOP_LIMIT, '1']);

        $this->assertSame(RelaySearchQuota::LIMITED, RelaySearchQuota::consume(42, 1, 5100));
        $this->assertSame([], \Db::$executeCalls);
    }

    /**
     * Verrou non obtenu (attente depassee, serveur sans GET_LOCK) : service
     * indisponible, journalise, aucune lecture ni ecriture du compteur
     */
    public function testConsumeIsUnavailableWithoutLock()
    {
        foreach (['0', null, false] as $lockResult) {
            \Db::resetMocks();
            \PrestaShopLogger::resetLogs();
            \Db::setMockGetValueResults([$lockResult]);

            $this->assertSame(RelaySearchQuota::UNAVAILABLE, RelaySearchQuota::consume(42, 1, 5100));
            $this->assertCount(1, \Db::$queryLog, var_export($lockResult, true));
            $this->assertStringContainsString('GET_LOCK', \Db::$queryLog[0]);
            $this->assertCount(1, \PrestaShopLogger::$logs);
            $this->assertStringContainsString('verrou non obtenu', \PrestaShopLogger::$logs[0]['message']);
            $this->assertSame(3, \PrestaShopLogger::$logs[0]['severity']);
        }
    }

    /**
     * Sans compteur fiable (ecriture impossible, erreur de base, client
     * inconnu) : service indisponible, journalise, verrou libere
     */
    public function testConsumeFailsClosedAndLogs()
    {
        \Db::setMockGetRow(false);
        \Db::setMockGetValueResults(['1', '0', '1']);
        \Db::setMockExecuteResult(false);

        $this->assertSame(RelaySearchQuota::UNAVAILABLE, RelaySearchQuota::consume(42, 1, 5100));
        $this->assertStringContainsString('RELEASE_LOCK', end(\Db::$queryLog));
        $this->assertCount(1, \PrestaShopLogger::$logs);
        $this->assertStringContainsString('customer 42', \PrestaShopLogger::$logs[0]['message']);

        \Db::resetMocks();
        \PrestaShopLogger::resetLogs();
        \Db::setMockGetValueResults(['1', new \Exception('Table absente'), '1']);

        $this->assertSame(RelaySearchQuota::UNAVAILABLE, RelaySearchQuota::consume(42, 1, 5100));
        $this->assertStringContainsString('RELEASE_LOCK', end(\Db::$queryLog));
        $this->assertStringContainsString('(Exception)', \PrestaShopLogger::$logs[0]['message']);
        $this->assertStringNotContainsString('Table absente', \PrestaShopLogger::$logs[0]['message']);

        $this->assertSame(RelaySearchQuota::UNAVAILABLE, RelaySearchQuota::consume(0, 1, 5100));

        // Erreur fatale (pas une Exception) : même refus, verrou libéré
        \Db::resetMocks();
        \PrestaShopLogger::resetLogs();
        \Db::setMockGetValueResults(['1', new \TypeError('Erreur de type'), '1']);

        $this->assertSame(RelaySearchQuota::UNAVAILABLE, RelaySearchQuota::consume(42, 1, 5100));
        $this->assertStringContainsString('RELEASE_LOCK', end(\Db::$queryLog));
        $this->assertStringContainsString('(TypeError)', \PrestaShopLogger::$logs[0]['message']);
    }
}
