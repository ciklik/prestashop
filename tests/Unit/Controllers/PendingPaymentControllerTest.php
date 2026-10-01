<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Data\PendingPaymentData;

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/../../../controllers/front/pendingpayment.php';

/**
 * Contrôleur pendingpayment : redirection vers le lien de reprise, jamais
 * écrit dans la page
 */
class PendingPaymentControllerTest extends TestCase
{
    const OWNER = '6ba7b810-9dad-41d1-80b4-00c04fd430c8';

    const PENDING = '0f8fad5b-d9cb-469f-a165-70867728950e';

    const SETTLED = '7c9e6679-7425-40de-944b-e07fc1f90ae7';

    const OTHER_CUSTOMER = '9b2f1c3e-1d2a-4b6c-8e7f-0a1b2c3d4e5f';

    const LINK = 'https://abo.boutique.test/commande/42/compte/7?token=pi_123_secret_456';

    const ACCOUNT = 'https://shop.test/module/ciklik/account';

    protected function setUp(): void
    {
        \Tools::$requestValues = [];
        \Tools::$redirects = [];
    }

    /**
     * Client non connecté : PrestaShop le renvoie vers la connexion avant postProcess
     */
    public function testIsReservedToLoggedCustomers()
    {
        $this->assertTrue((new \CiklikPendingpaymentModuleFrontController())->auth);
    }

    /**
     * Paiement en attente du client : redirection vers le lien, sans cache ni referer
     */
    public function testRedirectsToRetryLinkWithHeaders()
    {
        $this->assertSame(
            ['url' => self::LINK, 'headers' => ['Cache-Control: no-store', 'Referrer-Policy: no-referrer']],
            $this->redirectFor(self::PENDING)
        );
    }

    /**
     * Abonnement d'un autre client, même avec un paiement en attente : retour à Mes abonnements
     */
    public function testRefusesSubscriptionOfAnotherCustomer()
    {
        $this->assertSame(['url' => self::ACCOUNT, 'headers' => null], $this->redirectFor(self::OTHER_CUSTOMER));
    }

    /**
     * Abonnement sans paiement en attente, ou uuid invalide : retour à Mes abonnements
     */
    public function testRedirectsToAccountWithoutPendingPayment()
    {
        $this->assertSame(['url' => self::ACCOUNT, 'headers' => null], $this->redirectFor(self::SETTLED));
        $this->assertSame(['url' => self::ACCOUNT, 'headers' => null], $this->redirectFor('pas-un-uuid'));
    }

    /**
     * @return array Redirection demandée : url et en-têtes
     */
    private function redirectFor(string $uuid): array
    {
        \Tools::$requestValues = ['uuid' => $uuid];

        $controller = new \CiklikPendingpaymentModuleFrontController();
        $controller->context = (object) ['link' => new \Link()];
        // Module : abonnements bruts de l'API, filtrés comme dans Ciklik::getPendingPayments()
        $controller->module = new class() {
            public $name = 'ciklik';

            public function getPendingPayments()
            {
                $subscription = function ($uuid, $owner, $pendingPayment) {
                    return ['uuid' => $uuid, 'user_uuid' => $owner, 'active' => true, 'display_content' => 'Croquettes', 'pending_payment' => $pendingPayment];
                };
                $pending = ['amount' => 29.9, 'currency' => 'EUR', 'retry_link' => PendingPaymentControllerTest::LINK];

                return PendingPaymentData::collection([
                    $subscription(PendingPaymentControllerTest::PENDING, PendingPaymentControllerTest::OWNER, $pending),
                    $subscription(PendingPaymentControllerTest::SETTLED, PendingPaymentControllerTest::OWNER, null),
                    $subscription(PendingPaymentControllerTest::OTHER_CUSTOMER, '1b4e28ba-2fa1-41d2-883f-0016d3cca427', $pending),
                ], PendingPaymentControllerTest::OWNER);
            }
        };

        try {
            $controller->postProcess();
            $this->fail('Aucune redirection');
        } catch (\RuntimeException $e) {
            $this->assertSame('redirect', $e->getMessage());
        }

        $this->assertCount(1, \Tools::$redirects);

        return array_pop(\Tools::$redirects);
    }
}
