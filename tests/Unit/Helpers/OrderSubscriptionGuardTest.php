<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Helpers\OrderSubscriptionGuard;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Abonnement visé par une action de la fiche commande du back-office : celui
 * de la commande consultée, appartenant à son client, lisible chez Ciklik.
 */
class OrderSubscriptionGuardTest extends TestCase
{
    const UUID = '550e8400-e29b-41d4-a716-446655440000';

    const OTHER_UUID = '6ba7b810-9dad-41d1-80b4-00c04fd430c8';

    /** @var array UUID demandés au chargement */
    private $loaded = [];

    private function resolve($orderUuid, $response, $idCustomer = 42, $build = null)
    {
        $this->loaded = [];
        $loaded = &$this->loaded;

        return OrderSubscriptionGuard::resolve(
            $orderUuid,
            self::UUID,
            $idCustomer,
            function ($uuid) use ($response, &$loaded) {
                $loaded[] = $uuid;
                if ($response instanceof \Throwable) {
                    throw $response;
                }

                return $response;
            },
            $build ?: function (array $body) {
                return (object) ['external_fingerprint' => (object) ['id_customer' => $body['customer']]];
            }
        );
    }

    private function okResponse($customer = 42)
    {
        return ['status' => true, 'httpCode' => 200, 'body' => ['external_fingerprint' => '{}', 'customer' => $customer], 'errors' => []];
    }

    public function testSubscriptionOfTheOrderAndItsCustomerIsAccepted()
    {
        $result = $this->resolve(self::UUID, $this->okResponse());

        $this->assertSame(OrderSubscriptionGuard::OK, $result['status']);
        $this->assertSame(42, $result['subscription']->external_fingerprint->id_customer);
        $this->assertNull($result['cause']);
        $this->assertSame([self::UUID], $this->loaded);
    }

    /**
     * UUID comparé sans tenir compte de la casse
     */
    public function testUuidComparisonIgnoresCase()
    {
        $this->assertSame(OrderSubscriptionGuard::OK, $this->resolve(strtoupper(self::UUID), $this->okResponse())['status']);
    }

    /**
     * UUID posté différent de celui de la commande : refus sans lire l'abonnement
     */
    public function testSubscriptionOfAnotherOrderIsRefusedWithoutLoadingIt()
    {
        $result = $this->resolve(self::OTHER_UUID, $this->okResponse());

        $this->assertSame(OrderSubscriptionGuard::NOT_ORDER_SUBSCRIPTION, $result['status']);
        $this->assertSame([], $this->loaded);
    }

    /**
     * Commande inconnue de Ciklik (ou Ciklik injoignable) : refus sans lecture
     */
    public function testOrderWithoutSubscriptionIsRefused()
    {
        foreach ([null, ''] as $orderUuid) {
            $result = $this->resolve($orderUuid, $this->okResponse());

            $this->assertSame(OrderSubscriptionGuard::ORDER_UNKNOWN, $result['status']);
            $this->assertNotEmpty($result['cause']);
            $this->assertSame([], $this->loaded);
        }
    }

    public function testSubscriptionOfAnotherCustomerIsRefused()
    {
        $this->assertSame(OrderSubscriptionGuard::OTHER_CUSTOMER, $this->resolve(self::UUID, $this->okResponse(7))['status']);
    }

    /**
     * Lecture en échec : cause courte pour le journal, sans texte de l'API
     */
    public function testLoadFailuresGiveACause()
    {
        $cases = [
            'refus' => [['status' => false, 'httpCode' => 404, 'body' => [], 'errors' => ['Abonnement de Jean Dupont introuvable']], 'HTTP 404'],
            'json invalide' => [['status' => false, 'httpCode' => 200, 'body' => [], 'errors' => []], 'HTTP 200, réponse illisible (JSON invalide)'],
            'sans empreinte' => [['status' => true, 'httpCode' => 200, 'body' => ['uuid' => self::UUID], 'errors' => []], 'abonnement sans empreinte'],
            'exception' => [new \RuntimeException('cURL error https://api.test/?token=secret'), 'exception RuntimeException'],
        ];

        foreach ($cases as $name => list($response, $cause)) {
            $result = $this->resolve(self::UUID, $response);

            $this->assertSame(OrderSubscriptionGuard::LOAD_FAILED, $result['status'], $name);
            $this->assertSame($cause, $result['cause'], $name);
            $this->assertNull($result['subscription'], $name);
        }
    }

    /**
     * Empreinte illisible (ancien format) : échec de lecture, classe de l'exception seulement
     */
    public function testUnreadableFingerprintIsALoadFailure()
    {
        $result = $this->resolve(self::UUID, $this->okResponse(), 42, function () {
            throw new \InvalidArgumentException('Empreinte de Jean Dupont');
        });

        $this->assertSame(OrderSubscriptionGuard::LOAD_FAILED, $result['status']);
        $this->assertSame('empreinte illisible (InvalidArgumentException)', $result['cause']);
    }
}
