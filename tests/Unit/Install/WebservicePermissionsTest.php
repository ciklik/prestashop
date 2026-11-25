<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Install;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Install\WebservicePermissions;

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__DIR__, 3) . '/upgrade/upgrade-1.29.0.php';

/**
 * Droit combinations de la clé webservice du module : ajouté s'il manque, sans jamais toucher aux autres droits de la
 * clé, et sans faire échouer la mise à jour du module.
 */
class WebservicePermissionsTest extends TestCase
{
    /** @var \PDO */
    private $pdo;

    protected function setUp(): void
    {
        \Db::resetMocks();
        \Configuration::resetMocks();
        \PrestaShopLogger::resetLogs();

        $this->pdo = new \PDO('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_STRINGIFY_FETCHES => true,
        ]);
        $this->pdo->exec('CREATE TABLE ps_webservice_account (id_webservice_account INTEGER PRIMARY KEY, `key` TEXT, is_module INTEGER, module_name TEXT)');
        $this->pdo->exec('CREATE TABLE ps_webservice_permission (id_webservice_permission INTEGER PRIMARY KEY AUTOINCREMENT, resource TEXT, method TEXT, id_webservice_account INTEGER, UNIQUE (resource, method, id_webservice_account))');
        $this->pdo->exec("INSERT INTO ps_webservice_account VALUES (3, 'cle', 1, 'ciklik'), (4, 'autre', 0, ''), (5, 'reprise', 0, '')");
        $this->pdo->exec("INSERT INTO ps_webservice_permission (resource, method, id_webservice_account) VALUES
            ('carts', 'GET', 3), ('carts', 'HEAD', 3), ('products', 'GET', 3),
            ('stock_availables', 'GET', 3), ('combinations', 'PUT', 4)");
        \Db::useSqlite($this->pdo);

        \Configuration::updateValue(\Ciklik::CONFIG_WEBSERVICE_ID, 3);
    }

    protected function tearDown(): void
    {
        \Db::resetMocks();
        \Configuration::resetMocks();
    }

    /**
     * @return array
     */
    private function permissions($accountId)
    {
        $rows = $this->pdo->query('SELECT resource, method FROM ps_webservice_permission WHERE id_webservice_account = ' . (int) $accountId . ' ORDER BY resource, method')->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(function ($row) {
            return $row['resource'] . ':' . $row['method'];
        }, $rows);
    }

    public function testTheRightIsAddedWhenMissingAndOtherRightsAreKept()
    {
        $this->assertFalse(WebservicePermissions::hasCombinations());

        $this->assertTrue(WebservicePermissions::ensureCombinations());

        // Droit ajouté à la main par le marchand (stock_availables) gardé, autre clé intacte
        $this->assertSame(
            ['carts:GET', 'carts:HEAD', 'combinations:GET', 'combinations:HEAD', 'products:GET', 'stock_availables:GET'],
            $this->permissions(3)
        );
        $this->assertSame(['combinations:PUT'], $this->permissions(4));
        $this->assertTrue(WebservicePermissions::hasCombinations());
    }

    public function testNothingIsAddedTwice()
    {
        WebservicePermissions::ensureCombinations();
        $this->assertTrue(WebservicePermissions::ensureCombinations());

        $this->assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM ps_webservice_permission WHERE id_webservice_account = 3 AND resource = 'combinations'")->fetchColumn());
    }

    public function testOnlyTheMissingMethodIsAdded()
    {
        $this->pdo->exec("INSERT INTO ps_webservice_permission (resource, method, id_webservice_account) VALUES ('combinations', 'GET', 3)");

        $this->assertTrue(WebservicePermissions::ensureCombinations());
        $this->assertContains('combinations:HEAD', $this->permissions(3));
    }

    public function testWithoutAKeyNothingIsWritten()
    {
        \Configuration::updateValue(\Ciklik::CONFIG_WEBSERVICE_ID, 0);
        $this->assertFalse(WebservicePermissions::ensureCombinations());

        // Clé configurée mais supprimée de la boutique : aucun droit orphelin
        \Configuration::updateValue(\Ciklik::CONFIG_WEBSERVICE_ID, 99);
        $this->assertFalse(WebservicePermissions::ensureCombinations());

        // Identifiant repris par une clé qui n'est pas celle du module
        \Configuration::updateValue(\Ciklik::CONFIG_WEBSERVICE_ID, 5);
        $this->assertFalse(WebservicePermissions::ensureCombinations());

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM ps_webservice_permission WHERE id_webservice_account IN (5, 99)')->fetchColumn());
    }

    public function testTheUpgradeAddsTheRightAndAlwaysSucceeds()
    {
        $this->assertTrue(upgrade_module_1_29_0(new \stdClass()));
        $this->assertContains('combinations:GET', $this->permissions(3));
    }

    public function testTheUpgradeSucceedsEvenWhenTheInsertFails()
    {
        $this->pdo->exec('DROP TABLE ps_webservice_permission');

        $this->assertTrue(upgrade_module_1_29_0(new \stdClass()));
        $this->assertNotEmpty(\PrestaShopLogger::$logs);
    }
}
