<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Controllers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Api\Subscription;

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/../../../controllers/front/subscription.php';
require_once __DIR__ . '/../../../controllers/front/account.php';

/**
 * Mes abonnements quand Ciklik est injoignable (connexion refusée, délai
 * dépassé : Guzzle 7 lève ConnectException hors du client) : message traduit,
 * abonnement inchangé, cause journalisée sans le texte de l'erreur, jamais de
 * page d'erreur
 */
class SubscriptionUnreachableTest extends TestCase
{
    const UUID = '3f1c2b7e-8d4a-4c5b-9e6f-1a2b3c4d5e6f';

    const CIKLIK_CUSTOMER = '6ba7b810-9dad-41d1-80b4-00c04fd430c8';

    const ACCOUNT = 'https://shop.test/module/ciklik/account';

    /** @var array Requêtes envoyées à l'API */
    private $history = [];

    protected function setUp(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        \Tools::$requestValues = ['uuid' => self::UUID];
        \PrestaShopLogger::resetLogs();
        \Address::$customers = [];
        $this->history = [];
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REQUEST_METHOD']);
    }

    public function unreachableProvider(): array
    {
        return [
            'connexion refusée' => ['cURL error 7: Failed to connect to api.ciklik.test port 443: Connection refused'],
            'délai dépassé' => ['cURL error 28: Operation timed out after 10001 milliseconds'],
        ];
    }

    /**
     * @dataProvider unreachableProvider
     */
    public function testNewDateUnreachable(string $error)
    {
        \Tools::$requestValues = ['uuid' => self::UUID, 'next_billing' => date('Y-m-d', strtotime('+10 days'))];
        $controller = $this->runAction('newdate', [$this->unreachable($error, 'PUT')]);

        $this->assertFailure($controller, 'Your renewal date could not be changed. Please try again later.', 'next billing change', $error);
    }

    /**
     * Refus de Ciklik : même message propre à l'action
     */
    public function testNewDateRefusedShowsActionMessage()
    {
        \Tools::$requestValues = ['uuid' => self::UUID, 'next_billing' => date('Y-m-d', strtotime('+10 days'))];
        $controller = $this->runAction('newdate', [new Response(422, [], '{"errors":{"next_billing":["Date refusée"]}}')]);

        $this->assertSame([], $controller->success);
        $this->assertSame(['Your renewal date could not be changed. Please try again later.'], $controller->errors);
    }

    /**
     * @dataProvider unreachableProvider
     */
    public function testSkipUnreachable(string $error)
    {
        $controller = $this->runAction('skip', [$this->unreachable($error, 'GET')]);

        $this->assertFailure($controller, 'Your next delivery could not be postponed. Please try again later.', 'skip', $error);
    }

    /**
     * Abonnement lu, puis Ciklik injoignable à l'écriture
     */
    public function testSkipUnreachableOnWrite()
    {
        $error = 'cURL error 28: Operation timed out after 10001 milliseconds';
        $controller = $this->runAction('skip', [$this->fullSubscription(), $this->unreachable($error, 'PUT')]);

        $this->assertSame([], $controller->success);
        $this->assertSame(['Your next delivery could not be postponed. Please try again later.'], $controller->errors);
        $this->assertCount(2, $this->history);
        $this->assertSame('PUT', $this->history[1]['request']->getMethod());
        $this->assertCount(1, \PrestaShopLogger::$logs);
        $this->assertStringContainsString('exception GuzzleHttp\\Exception\\ConnectException', \PrestaShopLogger::$logs[0]['message']);
    }

    public function testAddressChangeUnreachableOnWrite()
    {
        \Tools::$requestValues = ['uuid' => self::UUID, 'changeAddressForm' => '12'];
        \Address::$customers = [12 => 7];
        $controller = $this->runAction('updateaddress', [$this->fullSubscription(), $this->unreachable('cURL error 7: Connection refused', 'PUT')]);

        $this->assertSame([], $controller->success);
        $this->assertSame(['Your new address could not be saved. Please try again later.'], $controller->errors);
        $this->assertCount(2, $this->history);
        $this->assertStringContainsString('address change', \PrestaShopLogger::$logs[0]['message']);
    }

    /**
     * Lecture refusée (502 d'un proxy) : message, jamais d'erreur fatale sur un corps absent
     */
    public function testSkipUnreadableSubscription()
    {
        $controller = $this->runAction('skip', [new Response(502, ['Content-Type' => 'text/html'], '<html>Bad Gateway</html>')]);

        $this->assertSame([], $controller->success);
        $this->assertSame(['Your next delivery could not be postponed. Please try again later.'], $controller->errors);
        $this->assertCount(1, $this->history);
    }

    /**
     * @dataProvider unreachableProvider
     */
    public function testAddressChangeUnreachable(string $error)
    {
        \Tools::$requestValues = ['uuid' => self::UUID, 'changeAddressForm' => '12'];
        \Address::$customers = [12 => 7];
        $controller = $this->runAction('updateaddress', [$this->unreachable($error, 'GET')]);

        $this->assertFailure($controller, 'Your new address could not be saved. Please try again later.', 'address change', $error);
    }

    public function testAddressChangeUnreadableSubscription()
    {
        \Tools::$requestValues = ['uuid' => self::UUID, 'changeAddressForm' => '12'];
        \Address::$customers = [12 => 7];
        $controller = $this->runAction('updateaddress', [new Response(502, [], 'Bad Gateway')]);

        $this->assertSame([], $controller->success);
        $this->assertSame(['Your new address could not be saved. Please try again later.'], $controller->errors);
        $this->assertCount(1, $this->history);
    }

    /**
     * @dataProvider unreachableProvider
     */
    public function testFrequencyChangeUnreachable(string $error)
    {
        \Tools::$requestValues = ['uuid' => self::UUID, 'product_combination' => '3'];
        $controller = $this->runAction('updateContent', [$this->unreachable($error, 'GET')]);

        $this->assertFailure($controller, 'Your new frequency could not be saved. Please try again later.', 'frequency change', $error);
    }

    /**
     * Actions AJAX des produits : réponse JSON d'échec, jamais une page d'erreur
     *
     * @dataProvider ajaxProvider
     */
    public function testAjaxActionsUnreachable(string $action, array $request, string $message, string $operation)
    {
        \Tools::$requestValues = ['uuid' => self::UUID] + $request;
        $error = 'cURL error 7: Failed to connect to api.ciklik.test port 443: Connection refused';
        $controller = $this->runAction($action, [$this->unreachable($error, 'PUT')]);

        $this->assertSame(['success' => false, 'message' => $message], $controller->json);
        $this->assertCount(1, \PrestaShopLogger::$logs);
        $this->assertStringContainsString($operation, \PrestaShopLogger::$logs[0]['message']);
        $this->assertStringContainsString('exception GuzzleHttp\\Exception\\ConnectException', \PrestaShopLogger::$logs[0]['message']);
        $this->assertStringNotContainsString('api.ciklik.test', \PrestaShopLogger::$logs[0]['message']);
    }

    public function ajaxProvider(): array
    {
        return [
            'quantité' => ['updateProductQuantity', ['external_id' => '11:42', 'quantity' => '2'], 'Error while updating the product quantity.', 'product quantity change'],
            'retrait' => ['removeProduct', ['external_id' => '11:42'], 'Error while removing the product from the subscription.', 'product removal'],
            'retrait d\'un upsell' => ['addUpsell', ['id_product' => '11', 'id_product_attribute' => '42', 'quantity' => '0'], 'Error while adding the product to the subscription.', 'upsell change'],
        ];
    }

    /**
     * Contrôle de propriété impossible : échec de l'action, pas « vous n'avez pas la permission » ; page relais (GET) :
     * lecture impossible
     */
    public function testOwnershipCheckUnreachableIsNotAPermissionRefusal()
    {
        $controller = $this->controller([$this->unreachable('cURL error 7: Connection refused', 'GET')]);

        $this->assertSame('Unable to update the subscription. Please try again later.', $this->ownershipRefusal($controller));
        $this->assertCount(1, \PrestaShopLogger::$logs);
        $this->assertStringContainsString('exception GuzzleHttp\\Exception\\ConnectException', \PrestaShopLogger::$logs[0]['message']);

        \PrestaShopLogger::resetLogs();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $controller = $this->controller([$this->unreachable('cURL error 7: Connection refused', 'GET')]);
        $this->assertSame('Unable to read subscription data. Please try again later.', $this->ownershipRefusal($controller));
        $this->assertCount(1, \PrestaShopLogger::$logs);
        $this->assertStringContainsString('exception GuzzleHttp\\Exception\\ConnectException', \PrestaShopLogger::$logs[0]['message']);
    }

    /**
     * Abonnement introuvable : refus de permission, comme avant
     */
    public function testOwnershipCheckNotFoundIsAPermissionRefusal()
    {
        $controller = $this->controller([new Response(404, [], '{"message":"Not found"}')]);

        $this->assertSame('You do not have permission to access this subscription.', $this->ownershipRefusal($controller));
    }

    /**
     * Contrôle de propriété : seul un corps accepté, portant l'empreinte du
     * client connecté, laisse passer l'action ; Ciklik en panne s'annonce
     * comme une indisponibilité
     *
     * @dataProvider ownershipProvider
     */
    public function testOwnershipCheck(Response $response, $expected, bool $logged)
    {
        $controller = $this->controller([$response]);

        $this->assertSame($expected, $this->ownershipRefusal($controller));
        $this->assertCount($logged ? 1 : 0, \PrestaShopLogger::$logs);

        $body = (new \ReflectionProperty(\CiklikSubscriptionModuleFrontController::class, 'subscriptionBody'));
        if (PHP_VERSION_ID < 80100) {
            $body->setAccessible(true);
        }
        $this->assertSame(null === $expected, is_array($body->getValue($controller)));
    }

    public function ownershipProvider(): array
    {
        $denied = 'You do not have permission to access this subscription.';
        $unavailable = 'Unable to update the subscription. Please try again later.';

        return [
            'abonnement du client' => [$this->subscription(7), null, false],
            'abonnement d\'un autre client' => [$this->subscription(8), $denied, false],
            'sans empreinte' => [new Response(200, [], json_encode(['data' => ['uuid' => self::UUID]])), $denied, false],
            'empreinte illisible' => [new Response(200, [], json_encode(['data' => ['external_fingerprint' => 'a:1:{}']])), $denied, false],
            'empreinte non texte' => [new Response(200, [], json_encode(['data' => ['external_fingerprint' => ['id_customer' => 7]]])), $denied, false],
            'refus 422 avec l\'empreinte du client' => [$this->subscription(7, 422), $denied, false],
            '5xx avec l\'empreinte du client' => [$this->subscription(7, 503), $unavailable, true],
            'jeton d\'API refusé' => [new Response(401, [], '{"message":"Unauthenticated."}'), $unavailable, true],
            'débit limité' => [new Response(429, [], 'Too Many Requests'), $unavailable, true],
        ];
    }

    /**
     * Abonnement complet, lisible par SubscriptionData::create(), en mode déclinaisons sans contenu
     */
    private function fullSubscription(): Response
    {
        return new Response(200, [], json_encode(['data' => [
            'uuid' => self::UUID,
            'active' => true,
            'display_content' => 'Café x1',
            'display_interval' => 'Mensuel',
            'next_billing' => '2026-12-19 00:00:00',
            'created_at' => '2026-11-01 00:00:00',
            'end_date' => '2027-11-01 00:00:00',
            'interval' => 'month',
            'interval_count' => 1,
            'content' => [],
            'external_fingerprint' => json_encode([
                'id_customer' => 7,
                'id_address_delivery' => 12,
                'id_address_invoice' => 12,
                'id_lang' => 1,
                'id_currency' => 1,
                'id_carrier_reference' => 1,
            ]),
        ]]));
    }

    private function subscription(int $idCustomer, int $status = 200): Response
    {
        return new Response($status, [], json_encode(['data' => [
            'uuid' => self::UUID,
            'external_fingerprint' => json_encode([
                'id_customer' => $idCustomer,
                'id_address_delivery' => 12,
                'id_address_invoice' => 12,
                'id_lang' => 1,
                'id_currency' => 1,
                'id_carrier_reference' => 1,
            ]),
        ]]));
    }

    /**
     * Mes abonnements : la liste illisible s'annonce, au lieu d'une page d'erreur ou de « aucun abonnement »
     *
     * @dataProvider unreachableProvider
     */
    public function testAccountListUnreachable(string $error)
    {
        $controller = $this->accountController([$this->unreachable($error, 'GET')]);

        $this->assertNull($this->fetchSubscriptions($controller));
        $this->assertSame(['Your subscriptions are temporarily unavailable. Please try again later.'], $controller->errors);
        $this->assertCount(1, \PrestaShopLogger::$logs);
        $this->assertStringContainsString('exception GuzzleHttp\\Exception\\ConnectException', \PrestaShopLogger::$logs[0]['message']);
        $this->assertStringNotContainsString('api.ciklik.test', \PrestaShopLogger::$logs[0]['message']);
    }

    public function testAccountListRefused()
    {
        $controller = $this->accountController([new Response(503, [], 'Service Unavailable')]);

        $this->assertNull($this->fetchSubscriptions($controller));
        $this->assertSame(['Your subscriptions are temporarily unavailable. Please try again later.'], $controller->errors);
        $this->assertStringContainsString('HTTP 503', \PrestaShopLogger::$logs[0]['message']);
    }

    public function testAccountListRead()
    {
        $controller = $this->accountController([new Response(200, [], '{"data":[]}')]);

        $this->assertSame([], $this->fetchSubscriptions($controller));
        $this->assertSame([], $controller->errors);
        $this->assertSame([], \PrestaShopLogger::$logs);
    }

    private function assertFailure($controller, string $message, string $operation, string $error)
    {
        $this->assertSame([], $controller->success);
        $this->assertSame([$message], $controller->errors);
        $this->assertSame([self::ACCOUNT], $controller->redirections);
        // Aucune modification envoyée après l'échec
        $this->assertCount(1, $this->history);
        $this->assertCount(1, \PrestaShopLogger::$logs);
        $log = \PrestaShopLogger::$logs[0]['message'];
        $this->assertStringContainsString($operation, $log);
        $this->assertStringContainsString(self::UUID, $log);
        $this->assertStringContainsString('exception GuzzleHttp\\Exception\\ConnectException', $log);
        $this->assertStringNotContainsString($error, $log);
    }

    private function unreachable(string $message, string $method): ConnectException
    {
        return new ConnectException($message, new Request($method, 'subscriptions/' . self::UUID));
    }

    private function api(array $responses): Subscription
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new Subscription(new \Link(), new Client([
            'handler' => $stack,
            'base_uri' => 'https://mock.test/api/',
            'http_errors' => false,
        ]));
    }

    private function controller(array $responses)
    {
        $controller = new class($this->api($responses)) extends \CiklikSubscriptionModuleFrontController {
            /** @var array|null Réponse JSON des actions AJAX */
            public $json;

            private $api;

            public function __construct($api)
            {
                $this->api = $api;
            }

            protected function subscriptionApi()
            {
                return $this->api;
            }

            protected function ajaxRenderAndExit($value = null, $responseCode = null, $controller = null, $method = null)
            {
                $this->json = json_decode((string) $value, true);

                throw new \RuntimeException('exit');
            }
        };
        $controller->context = (object) ['link' => new \Link(), 'customer' => (object) ['id' => 7]];
        $controller->module = $this->module();

        return $controller;
    }

    private function accountController(array $responses)
    {
        $controller = new class($this->api($responses)) extends \CiklikAccountModuleFrontController {
            private $api;

            public function __construct($api)
            {
                $this->api = $api;
            }

            protected function subscriptionApi()
            {
                return $this->api;
            }
        };
        $controller->context = (object) ['link' => new \Link(), 'customer' => (object) ['id' => 7]];
        $controller->module = $this->module();

        return $controller;
    }

    private function module()
    {
        return new class() {
            public $name = 'ciklik';

            public function l($string, $specific = false)
            {
                return $string;
            }
        };
    }

    private function runAction(string $action, array $responses)
    {
        $controller = $this->controller($responses);

        try {
            $this->invoke($controller, $action, []);
        } catch (\RuntimeException $e) {
            if ('exit' !== $e->getMessage()) {
                throw $e;
            }
        }

        return $controller;
    }

    private function ownershipRefusal($controller)
    {
        return $this->invoke($controller, 'ownershipRefusal', [self::UUID]);
    }

    private function fetchSubscriptions($controller)
    {
        return $this->invoke($controller, 'fetchSubscriptions', [self::CIKLIK_CUSTOMER]);
    }

    private function invoke($controller, string $method, array $args)
    {
        $reflection = new \ReflectionMethod($controller, $method);
        if (PHP_VERSION_ID < 80100) {
            $reflection->setAccessible(true);
        }

        return $reflection->invokeArgs($controller, $args);
    }
}
