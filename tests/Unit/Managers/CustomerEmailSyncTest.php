<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Managers;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Managers\CustomerEmailSync;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Email d'un client changé dans la boutique : Ciklik est prévenu une fois, seulement si l'adresse a changé et que le client
 * est connu de Ciklik, sans jamais gêner l'enregistrement du client.
 */
class CustomerEmailSyncTest extends TestCase
{
    /** @var \PDO */
    private $pdo;

    /** @var array Appels reçus par l'envoi simulé [id_customer] : l'adresse n'est jamais envoyée */
    private $calls = [];

    /** @var array|\Throwable File des réponses de l'envoi simulé */
    private $responses = [];

    protected function setUp(): void
    {
        \Db::resetMocks();
        \Configuration::resetMocks();
        \Shop::resetMocks();
        \PrestaShopLogger::resetLogs();

        $this->pdo = new \PDO('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_STRINGIFY_FETCHES => true,
        ]);
        $this->pdo->exec('CREATE TABLE ps_customer (id_customer INTEGER PRIMARY KEY, email TEXT, id_shop INTEGER)');
        $this->pdo->exec('CREATE TABLE ps_ciklik_customers (id_ciklik_customer INTEGER PRIMARY KEY, id_customer INTEGER, ciklik_uuid TEXT)');
        $this->pdo->exec('CREATE TABLE ps_orders (id_order INTEGER PRIMARY KEY, id_customer INTEGER, id_shop INTEGER, module TEXT)');
        $this->pdo->exec("INSERT INTO ps_customer VALUES (7, 'alice@example.com', 1), (8, 'bob@example.com', 1), (9, 'carol@example.com', 2), (10, 'dan@example.com', 1)");
        $this->pdo->exec("INSERT INTO ps_ciklik_customers VALUES (1, 7, 'uuid-7'), (2, 9, 'uuid-9'), (3, 10, 'uuid-10')");
        // Bob a commandé sans Ciklik : inconnu de Ciklik
        $this->pdo->exec("INSERT INTO ps_orders VALUES (1, 8, 1, 'ps_wirepayment')");
        \Db::useSqlite($this->pdo);

        \Configuration::updateValue(\Ciklik::CONFIG_API_TOKEN, 'jeton');
        $this->calls = [];
        $this->responses = [];
    }

    protected function tearDown(): void
    {
        \Db::resetMocks();
        \Configuration::resetMocks();
        \Shop::resetMocks();
    }

    public function testAChangedEmailOfAKnownCustomerIsSentOnce()
    {
        $sync = $this->sync();

        $this->assertTrue($this->save($sync, 7, 'alice.new@example.com'));
        $this->assertSame([[7]], $this->calls);
        $this->assertSame([], \PrestaShopLogger::$logs);
    }

    public function testAnUnchangedEmailIsNotSent()
    {
        $sync = $this->sync();

        $this->assertFalse($this->save($sync, 7, 'alice@example.com'));
        // Casse et espaces : même adresse, comme pour Ciklik
        $this->assertFalse($this->save($sync, 7, ' ALICE@example.com '));
        $this->assertSame([], $this->calls);
    }

    public function testACustomerUnknownToCiklikIsNeverSent()
    {
        $sync = $this->sync();

        $this->assertFalse($this->save($sync, 8, 'bob.new@example.com'));
        $this->assertSame([], $this->calls);
    }

    public function testANewCustomerWithoutIdIsIgnored()
    {
        $sync = $this->sync();
        $customer = $this->customer(0, 'new@example.com');

        $sync->rememberPreviousEmail($customer);

        $this->assertFalse($sync->notifyIfChanged($customer));
        $this->assertSame([], $this->calls);
    }

    public function testAnAfterHookWithoutBeforeHookSendsNothing()
    {
        $sync = $this->sync();
        $customer = $this->customer(7, 'alice.new@example.com');

        // Hook « before » non enregistré (mise à jour 1.27.0 incomplète) : aucune ancienne adresse connue
        $this->assertFalse($sync->notifyIfChanged($customer));
        $this->assertSame([], $this->calls);
    }

    public function testARefusalIsLoggedWithoutTheAddressAndStopsFurtherCalls()
    {
        $this->responses = [['status' => false, 'httpCode' => 404]];
        $sync = $this->sync();

        $this->assertFalse($this->save($sync, 7, 'alice.new@example.com'));
        $this->assertFalse($this->save($sync, 10, 'dan.new@example.com'));

        $this->assertSame([[7]], $this->calls);
        // Un seul journal : le client écarté après l'échec n'en ajoute pas
        $this->assertCount(1, \PrestaShopLogger::$logs);
        $log = \PrestaShopLogger::$logs[0];
        $this->assertSame(2, $log['severity']);
        $this->assertSame(['Customer', 7], [$log['objectType'], $log['objectId']]);
        $this->assertStringContainsString('HTTP 404', $log['message']);
        $this->assertStringNotContainsString('@', $log['message']);
    }

    public function testCallsStopOnceTheRequestBudgetIsSpent()
    {
        $sync = new CustomerEmailSync(function ($idCustomer) {
            $this->calls[] = [$idCustomer];
            usleep(2000);

            return ['status' => true, 'httpCode' => 204];
        }, 1e-3);

        $this->assertTrue($this->save($sync, 7, 'alice.new@example.com'));
        $this->assertFalse($this->save($sync, 10, 'dan.new@example.com'));
        $this->assertFalse($this->save($sync, 9, 'carol.new@example.com'));

        $this->assertSame([[7]], $this->calls);
        $this->assertCount(1, \PrestaShopLogger::$logs);
    }

    public function testAShopWithoutKeyNeverCalls()
    {
        \Configuration::resetMocks();
        $sync = $this->sync();

        $this->assertFalse($this->save($sync, 7, 'alice.new@example.com'));
        $this->assertSame([], $this->calls);
        $this->assertSame([], \PrestaShopLogger::$logs);
    }

    public function testAFailureOnOneShopDoesNotStopAnotherShop()
    {
        $this->responses = [['status' => false, 'httpCode' => 401]];
        $sync = $this->sync();

        $this->assertFalse($this->save($sync, 7, 'alice.new@example.com'));
        $this->assertTrue($this->save($sync, 9, 'carol.new@example.com'));
        $this->assertSame([[7], [9]], $this->calls);
    }

    public function testACustomerWithACiklikOrderButNoCiklikRowIsKnown()
    {
        $this->pdo->exec('DELETE FROM ps_ciklik_customers WHERE id_customer = 7');
        $this->pdo->exec("INSERT INTO ps_orders VALUES (2, 7, 1, 'ciklik')");
        $sync = $this->sync();

        $this->assertTrue($this->save($sync, 7, 'alice.new@example.com'));
    }

    public function testTheKeyIsTheOneOfTheShopOfTheLastCiklikOrder()
    {
        // Clients partagés : Alice, créée sur la boutique 1, s'est abonnée sur la boutique 3
        \Shop::$featureActive = true;
        $this->pdo->exec("INSERT INTO ps_orders VALUES (2, 7, 1, 'ciklik'), (3, 7, 3, 'ciklik')");
        $seen = null;
        $sync = new CustomerEmailSync(function ($idCustomer) use (&$seen) {
            $seen = \Shop::getContextShopID();

            return ['status' => true, 'httpCode' => 204];
        });

        $this->assertTrue($this->save($sync, 7, 'alice.new@example.com'));
        $this->assertSame(3, $seen);
    }

    public function testANestedSaveFromAnotherModuleDoesNotLoseTheChange()
    {
        $sync = $this->sync();
        $customer = $this->customer(7, 'alice.new@example.com');

        // Before 1, écriture, puis un autre module réenregistre le client dans son propre hook « after »
        $sync->rememberPreviousEmail($customer);
        $this->pdo->exec("UPDATE ps_customer SET email = 'alice.new@example.com' WHERE id_customer = 7");
        $sync->rememberPreviousEmail($customer);
        $this->assertTrue($sync->notifyIfChanged($customer));
        $this->assertFalse($sync->notifyIfChanged($customer));

        $this->assertSame([[7]], $this->calls);
    }

    public function testAConnectionErrorNeverReachesTheShop()
    {
        $this->responses = [new \RuntimeException('cURL error 28 for alice.new@example.com')];
        $sync = $this->sync();

        $this->assertFalse($this->save($sync, 7, 'alice.new@example.com'));

        $this->assertCount(1, \PrestaShopLogger::$logs);
        $this->assertStringContainsString('RuntimeException', \PrestaShopLogger::$logs[0]['message']);
        $this->assertStringNotContainsString('@', \PrestaShopLogger::$logs[0]['message']);
    }

    public function testAnUnreadableDatabaseSendsNothingAndIsLogged()
    {
        $this->pdo->exec('DROP TABLE ps_ciklik_customers');
        $sync = $this->sync();

        $this->assertFalse($this->save($sync, 7, 'alice.new@example.com'));
        $this->assertSame([], $this->calls);
        $this->assertCount(1, \PrestaShopLogger::$logs);
    }

    public function testTheCallIsMadeInTheCustomerShopThenTheContextIsRestored()
    {
        \Shop::$featureActive = true;
        \Shop::$context = \Shop::CONTEXT_ALL;
        \Shop::$contextShopId = null;
        $seen = null;
        $sync = new CustomerEmailSync(function ($idCustomer) use (&$seen) {
            $seen = [\Shop::getContext(), \Shop::getContextShopID()];

            return ['status' => true, 'httpCode' => 204];
        });

        $this->assertTrue($this->save($sync, 9, 'carol.new@example.com'));

        $this->assertSame([\Shop::CONTEXT_SHOP, 2], $seen);
        $this->assertSame(\Shop::CONTEXT_ALL, \Shop::getContext());
    }

    /**
     * Enregistrement d'un client comme ObjectModel::update() : hook avant, écriture en base, hook après.
     */
    private function save(CustomerEmailSync $sync, int $idCustomer, string $email)
    {
        $customer = $this->customer($idCustomer, $email);

        $sync->rememberPreviousEmail($customer);
        $statement = $this->pdo->prepare('UPDATE ps_customer SET email = ? WHERE id_customer = ?');
        $statement->execute([$email, $idCustomer]);

        return $sync->notifyIfChanged($customer);
    }

    private function customer(int $idCustomer, string $email)
    {
        $customer = new \stdClass();
        $customer->id = $idCustomer;
        $customer->email = $email;
        $customer->id_shop = $idCustomer === 9 ? 2 : 1;

        return $customer;
    }

    private function sync()
    {
        return new CustomerEmailSync(function ($idCustomer) {
            $this->calls[] = [$idCustomer];
            $response = $this->responses ? array_shift($this->responses) : ['status' => true, 'httpCode' => 204];

            if ($response instanceof \Throwable) {
                throw $response;
            }

            return $response;
        });
    }
}
