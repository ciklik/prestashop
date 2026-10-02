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

/**
 * Relais repris au renouvellement par Colissimo, DPD France, GLS et
 * Chronopost : celui de la dernière commande payée livrée à la même adresse,
 * et non celui de la dernière commande du client (client avec plusieurs
 * abonnements, à domicile et en relais).
 *
 * Les requêtes des drivers sont réellement exécutées sur une base SQLite en
 * mémoire, réduite aux colonnes lues ou écrites. Comme les modules
 * transporteurs, chaque commande en relais porte une adresse relais créée à
 * la validation ; son panier garde l'adresse du client.
 */
class DeliveryModuleManagerSameAddressRelayTest extends TestCase
{
    const CUSTOMER = 7;

    /** Adresses du client */
    const HOME = 11;
    const ADDRESS_A = 21;
    const ADDRESS_B = 22;
    const NEW_ADDRESS = 31;

    const STATE_PAID = 2;
    const STATE_UNPAID = 8;

    /** Transporteur relais de chaque module */
    const RELAY_CARRIERS = ['colissimo' => 30, 'dpdfrance' => 40, 'nkmgls' => 50, 'chronopost' => 60];

    /** Transporteur DPD Predict (livraison à domicile) */
    const DPD_PREDICT_CARRIER = 41;

    /** @var \PDO */
    private $pdo;

    protected function setUp(): void
    {
        \Db::resetMocks();
        \Configuration::resetMocks();
        \Carrier::resetMocks();
        \PrestaShopLogger::resetLogs();

        $this->pdo = new \PDO('sqlite::memory:', null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            // Valeurs rendues en chaînes, comme MySQL sous PrestaShop
            \PDO::ATTR_STRINGIFY_FETCHES => true,
        ]);
        foreach ($this->schema() as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec('INSERT INTO ps_order_state (id_order_state, paid) VALUES ('
            . self::STATE_PAID . ', 1), (' . self::STATE_UNPAID . ', 0)');
        \Db::useSqlite($this->pdo);

        foreach (self::RELAY_CARRIERS as $module => $idCarrier) {
            \Carrier::setMockCarrier($idCarrier, ['external_module_name' => $module]);
        }
        \Carrier::setMockCarrier(self::DPD_PREDICT_CARRIER, ['external_module_name' => 'dpdfrance']);
        \Configuration::updateValue('GLS_GLSRELAIS_ID', self::RELAY_CARRIERS['nkmgls']);
        \Configuration::updateValue('CHRONOPOST_CHRONORELAIS_ID', self::RELAY_CARRIERS['chronopost']);
    }

    protected function tearDown(): void
    {
        \Db::resetMocks();
        \Configuration::resetMocks();
        \Carrier::resetMocks();
        \PrestaShopLogger::resetLogs();
    }

    public function moduleProvider(): array
    {
        return [
            'colissimo' => ['colissimo'],
            'dpdfrance' => ['dpdfrance'],
            'nkmgls' => ['nkmgls'],
            'chronopost' => ['chronopost'],
        ];
    }

    /**
     * Abonnement en relais (adresse A) et abonnement à domicile, commandé en
     * dernier : le renouvellement en relais reprend son relais, pas l'absence
     * de relais de la commande à domicile
     *
     * @dataProvider moduleProvider
     */
    public function testRelayRenewalIgnoresLaterHomeOrder(string $module)
    {
        $this->order($module, 101, self::ADDRESS_A, '2026-07-01 10:00:00', 'R1');
        $this->order($module, 102, self::HOME, '2026-07-15 10:00:00');

        $this->assertSame('R1', $this->renew($module, 900, self::ADDRESS_A));
    }

    /**
     * Mêmes abonnements livrés à la même adresse : seule une commande portant
     * un relais sert de source
     *
     * @dataProvider moduleProvider
     */
    public function testRelayRenewalIgnoresHomeOrderAtSameAddress(string $module)
    {
        $this->order($module, 101, self::HOME, '2026-07-01 10:00:00', 'R1');
        $this->order($module, 102, self::HOME, '2026-07-15 10:00:00');

        $this->assertSame('R1', $this->renew($module, 900, self::HOME));
    }

    /**
     * Deux abonnements en relais à deux adresses : chaque renouvellement
     * reprend le relais de sa propre adresse, pas celui du dernier commandé
     *
     * @dataProvider moduleProvider
     */
    public function testTwoRelaySubscriptionsKeepTheirOwnRelay(string $module)
    {
        $this->order($module, 101, self::ADDRESS_A, '2026-07-01 10:00:00', 'R1');
        $this->order($module, 102, self::ADDRESS_B, '2026-07-10 10:00:00', 'R2');

        $this->assertSame('R1', $this->renew($module, 900, self::ADDRESS_A));
        $this->assertSame('R2', $this->renew($module, 901, self::ADDRESS_B));
    }

    /**
     * Seules les commandes Ciklik payées servent de source, à la même
     * adresse comme ailleurs
     *
     * @dataProvider moduleProvider
     */
    public function testUnpaidOrderAtSameAddressIsIgnored(string $module)
    {
        $this->order($module, 101, self::ADDRESS_A, '2026-07-01 10:00:00', 'R1');
        $this->order($module, 102, self::ADDRESS_B, '2026-07-10 10:00:00', 'R2');
        $this->order($module, 103, self::ADDRESS_A, '2026-07-20 10:00:00', 'R3', self::STATE_UNPAID);

        $this->assertSame('R1', $this->renew($module, 900, self::ADDRESS_A));
    }

    /**
     * Le point relais des prochains prélèvements, réglé par client et par
     * transporteur, reste prioritaire sur la commande à la même adresse
     *
     * @dataProvider moduleProvider
     */
    public function testOverrideTakesPriorityOverSameAddressOrder(string $module)
    {
        $this->order($module, 101, self::ADDRESS_A, '2026-07-01 10:00:00', 'R1');
        if ('colissimo' === $module) {
            $this->colissimoPickupPoint('RX');
        }
        $this->pdo->exec(sprintf(
            "INSERT INTO ps_ciklik_delivery_override (id_customer, carrier_module, relay_id, payload) VALUES (%d, '%s', 'RX', '%s')",
            self::CUSTOMER,
            $module,
            json_encode(['name' => 'Relais RX', 'address1' => '1 rue du Relais', 'zipcode' => '75001', 'city' => 'Paris'])
        ));

        $this->assertSame('RX', $this->renew($module, 900, self::ADDRESS_A));
    }

    /**
     * Aucune commande payée à l'adresse du renouvellement (adresse changée) :
     * comportement d'avant, le relais de la dernière commande du client
     *
     * @dataProvider moduleProvider
     */
    public function testWithoutOrderAtSameAddressFallsBackToLastOrder(string $module)
    {
        $this->order($module, 101, self::ADDRESS_A, '2026-07-01 10:00:00', 'R1');
        $this->order($module, 102, self::ADDRESS_B, '2026-07-10 10:00:00', 'R2');

        $this->assertSame('R2', $this->renew($module, 900, self::NEW_ADDRESS));
    }

    /**
     * Encart « Point relais des prochains prélèvements » en mode automatique :
     * il affiche le relais que le renouvellement reprendra
     *
     * @dataProvider moduleProvider
     */
    public function testBackOfficeShowsRelayOfSameAddress(string $module)
    {
        $this->order($module, 101, self::ADDRESS_A, '2026-07-01 10:00:00', 'R1');
        $this->order($module, 102, self::ADDRESS_B, '2026-07-10 10:00:00', 'R2');
        $this->order($module, 103, self::HOME, '2026-07-15 10:00:00');

        $this->assertSame('R1', $this->peek($module, self::ADDRESS_A));
        $this->assertSame('R2', $this->peek($module, self::ADDRESS_B));
        $this->assertSame($this->renew($module, 900, self::ADDRESS_A), $this->peek($module, self::ADDRESS_A));
    }

    /**
     * Sans adresse connue, ou sans commande à cette adresse : relais de la
     * dernière commande du client, comme avant
     *
     * @dataProvider moduleProvider
     */
    public function testBackOfficeFallsBackToLastOrder(string $module)
    {
        $this->order($module, 101, self::ADDRESS_A, '2026-07-01 10:00:00', 'R1');
        $this->order($module, 102, self::ADDRESS_B, '2026-07-10 10:00:00', 'R2');

        $this->assertSame('R2', $this->peek($module, 0));
        $this->assertSame('R2', $this->peek($module, self::NEW_ADDRESS));
    }

    /**
     * DPD Predict (domicile) : ses paniers ont aussi une ligne DPD, sans
     * relais. Un renouvellement Predict garde la source d'avant, au lieu de
     * recevoir le relais de l'abonnement en relais livré à la même adresse.
     */
    public function testDpdPredictRenewalKeepsHistoricalSource()
    {
        \Configuration::updateValue('DPDFRANCE_PREDICT_CARRIER_LOG', '|' . self::DPD_PREDICT_CARRIER);

        $this->order('dpdfrance', 101, self::HOME, '2026-07-01 10:00:00', 'R1');
        $this->order('dpdfrance', 102, self::HOME, '2026-07-15 10:00:00');
        $this->pdo->exec(sprintf(
            "INSERT INTO ps_dpdfrance_shipping (id_customer, id_cart, id_carrier, service, relay_id, company, address1, address2, postcode, city, id_country, gsm_dest)
             VALUES (%d, 102, %d, 'PRE', '', '', '', '', '', '', 8, '+33600000000')",
            self::CUSTOMER,
            self::DPD_PREDICT_CARRIER
        ));

        $this->renew('dpdfrance', 900, self::HOME, self::DPD_PREDICT_CARRIER);

        $row = $this->pdo->query('SELECT service, relay_id, gsm_dest FROM ps_dpdfrance_shipping WHERE id_cart = 900')->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(['service' => 'PRE', 'relay_id' => '', 'gsm_dest' => '+33600000000'], $row);
    }

    /**
     * Schéma réduit des tables lues ou écrites par les drivers
     */
    private function schema(): array
    {
        return [
            'CREATE TABLE ps_order_state (id_order_state INTEGER PRIMARY KEY, paid INTEGER NOT NULL DEFAULT 0)',
            'CREATE TABLE ps_cart (id_cart INTEGER PRIMARY KEY, id_customer INTEGER, id_address_delivery INTEGER)',
            'CREATE TABLE ps_orders (id_order INTEGER PRIMARY KEY, id_cart INTEGER, id_customer INTEGER,
                id_address_delivery INTEGER, module TEXT, current_state INTEGER, date_add TEXT)',
            'CREATE TABLE ps_ciklik_delivery_override (id_customer INTEGER, carrier_module TEXT, relay_id TEXT, payload TEXT)',
            'CREATE TABLE ps_colissimo_pickup_point (id_colissimo_pickup_point INTEGER PRIMARY KEY, colissimo_id TEXT,
                company_name TEXT, city TEXT)',
            'CREATE TABLE ps_colissimo_cart_pickup_point (id_cart INTEGER PRIMARY KEY, id_colissimo_pickup_point INTEGER,
                mobile_phone TEXT)',
            'CREATE TABLE ps_dpdfrance_shipping (id_customer INTEGER, id_cart INTEGER PRIMARY KEY, id_carrier INTEGER,
                service TEXT, relay_id TEXT, company TEXT, address1 TEXT, address2 TEXT, postcode TEXT, city TEXT,
                id_country INTEGER, gsm_dest TEXT)',
            'CREATE TABLE ps_gls_cart_carrier (id_cart INTEGER, id_customer INTEGER, id_carrier INTEGER,
                original_id_address_delivery INTEGER, gls_product TEXT, parcel_shop_id TEXT, name TEXT, address1 TEXT,
                address2 TEXT, postcode TEXT, city TEXT, phone TEXT, phone_mobile TEXT, customer_phone_mobile TEXT,
                id_country INTEGER, parcel_shop_working_day TEXT, PRIMARY KEY (id_cart, id_customer))',
            'CREATE TABLE ps_chrono_cart_relais (id_cart INTEGER PRIMARY KEY, id_pr TEXT)',
        ];
    }

    /**
     * Commande Ciklik du client : panier livré à $idAddress, relais $relayId
     * associé au panier s'il est donné. L'adresse de la commande est alors
     * une adresse relais propre à la commande, comme après la validation.
     *
     * @param string|null $relayId
     */
    private function order(string $module, int $idCart, int $idAddress, string $date, $relayId = null, int $state = self::STATE_PAID)
    {
        $this->pdo->exec(sprintf(
            'INSERT INTO ps_cart (id_cart, id_customer, id_address_delivery) VALUES (%d, %d, %d)',
            $idCart,
            self::CUSTOMER,
            $idAddress
        ));
        $this->pdo->exec(sprintf(
            "INSERT INTO ps_orders (id_cart, id_customer, id_address_delivery, module, current_state, date_add)
             VALUES (%d, %d, %d, 'ciklik', %d, '%s')",
            $idCart,
            self::CUSTOMER,
            null !== $relayId ? 5000 + $idCart : $idAddress,
            $state,
            $date
        ));

        if (null !== $relayId) {
            $this->associate($module, $idCart, $relayId);
        }
    }

    /**
     * Association panier/relais telle que l'écrit le module transporteur
     */
    private function associate(string $module, int $idCart, string $relayId)
    {
        $idCarrier = self::RELAY_CARRIERS[$module];

        switch ($module) {
            case 'colissimo':
                $sql = sprintf(
                    "INSERT INTO ps_colissimo_cart_pickup_point (id_cart, id_colissimo_pickup_point, mobile_phone) VALUES (%d, %d, '0600000000')",
                    $idCart,
                    $this->colissimoPickupPoint($relayId)
                );
                break;
            case 'dpdfrance':
                $sql = sprintf(
                    "INSERT INTO ps_dpdfrance_shipping (id_customer, id_cart, id_carrier, service, relay_id, company, address1, address2, postcode, city, id_country, gsm_dest)
                     VALUES (%d, %d, %d, 'REL', '%s', 'Relais %s', '1 rue du Relais', '', '75001', 'Paris', 8, '')",
                    self::CUSTOMER,
                    $idCart,
                    $idCarrier,
                    $relayId,
                    $relayId
                );
                break;
            case 'nkmgls':
                $sql = sprintf(
                    "INSERT INTO ps_gls_cart_carrier (id_cart, id_customer, id_carrier, original_id_address_delivery, gls_product, parcel_shop_id, name, address1, address2, postcode, city, phone, phone_mobile, customer_phone_mobile, id_country, parcel_shop_working_day)
                     VALUES (%d, %d, %d, 0, 'SHD', '%s', 'Relais %s', '1 rue du Relais', '', '75001', 'Paris', '', '', '0600000000', 8, '{}')",
                    $idCart,
                    self::CUSTOMER,
                    $idCarrier,
                    $relayId,
                    $relayId
                );
                break;
            default:
                $sql = sprintf("INSERT INTO ps_chrono_cart_relais (id_cart, id_pr) VALUES (%d, '%s')", $idCart, $relayId);
        }

        $this->pdo->exec($sql);
    }

    /**
     * Ligne de détail Colissimo du relais, créée au besoin
     */
    private function colissimoPickupPoint(string $relayId): int
    {
        $id = $this->pdo->query("SELECT id_colissimo_pickup_point FROM ps_colissimo_pickup_point WHERE colissimo_id = '" . $relayId . "'")->fetchColumn();
        if ($id) {
            return (int) $id;
        }

        $this->pdo->exec("INSERT INTO ps_colissimo_pickup_point (colissimo_id, company_name, city) VALUES ('" . $relayId . "', 'Relais " . $relayId . "', 'Paris')");

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Renouvellement du client livré à $idAddress : panier créé puis passé
     * aux drivers comme le fait CartGateway::post
     *
     * @return string|null Relais associé au panier du renouvellement
     */
    private function renew(string $module, int $idCart, int $idAddress, int $idCarrier = 0)
    {
        $this->pdo->exec(sprintf(
            'INSERT INTO ps_cart (id_cart, id_customer, id_address_delivery) VALUES (%d, %d, %d)',
            $idCart,
            self::CUSTOMER,
            $idAddress
        ));

        $cart = new \Cart($idCart);
        $cart->id_customer = self::CUSTOMER;
        $cart->id_address_delivery = $idAddress;
        $cart->id_carrier = $idCarrier ?: self::RELAY_CARRIERS[$module];

        DeliveryModuleManager::handleDeliveryModule($cart);

        $errors = array_filter(\PrestaShopLogger::$logs, function ($log) {
            return $log['severity'] >= 3;
        });
        $this->assertSame([], array_column($errors, 'message'), 'Erreur journalisée par le driver ' . $module);

        return $this->relayOfCart($module, $idCart);
    }

    /**
     * @return string|null Relais affiché par l'encart du back-office
     */
    private function peek(string $module, int $idAddress)
    {
        $peek = DeliveryModuleManager::peekLegacyRelay(self::CUSTOMER, $module, $idAddress);

        return $peek ? $peek['relay_id'] : null;
    }

    /**
     * @return string|null Relais associé au panier, null sans association
     */
    private function relayOfCart(string $module, int $idCart)
    {
        $queries = [
            'colissimo' => 'SELECT pp.colissimo_id FROM ps_colissimo_cart_pickup_point ccp
                INNER JOIN ps_colissimo_pickup_point pp ON pp.id_colissimo_pickup_point = ccp.id_colissimo_pickup_point
                WHERE ccp.id_cart = %d',
            'dpdfrance' => 'SELECT relay_id FROM ps_dpdfrance_shipping WHERE id_cart = %d',
            'nkmgls' => 'SELECT parcel_shop_id FROM ps_gls_cart_carrier WHERE id_cart = %d',
            'chronopost' => 'SELECT id_pr FROM ps_chrono_cart_relais WHERE id_cart = %d',
        ];

        $relayId = $this->pdo->query(sprintf($queries[$module], $idCart))->fetchColumn();

        return false === $relayId ? null : (string) $relayId;
    }
}
