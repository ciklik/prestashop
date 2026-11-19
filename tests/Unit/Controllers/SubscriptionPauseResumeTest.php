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

/**
 * Mes abonnements : la pause et la reprise n'annoncent un succès que si
 * Ciklik accepte la demande
 */
class SubscriptionPauseResumeTest extends TestCase
{
    const UUID = '3f1c2b7e-8d4a-4c5b-9e6f-1a2b3c4d5e6f';

    const ACCOUNT = 'https://shop.test/module/ciklik/account';

    /** @var array Requêtes envoyées à l'API */
    private $history = [];

    protected function setUp(): void
    {
        \Tools::$requestValues = ['uuid' => self::UUID];
        \PrestaShopLogger::resetLogs();
        $this->history = [];
    }

    public function testPauseAcceptedAnnouncesSuccess()
    {
        $controller = $this->runAction('stop', [$this->accepted(false)]);

        $this->assertSame(['Your subscription has been paused.'], $controller->success);
        $this->assertSame([], $controller->errors);
        $this->assertSame(['active' => false], $this->sentBody());
        $this->assertSame([self::ACCOUNT], $controller->redirections);
        $this->assertSame([], \PrestaShopLogger::$logs);
    }

    public function testResumeAcceptedAnnouncesSuccess()
    {
        $controller = $this->runAction('resume', [$this->accepted(true)]);

        $this->assertSame(['Your subscription has been resumed.'], $controller->success);
        $this->assertSame([], $controller->errors);
        $this->assertSame(['active' => true], $this->sentBody());
    }

    public function testPauseRefusedShowsErrorAndNoSuccess()
    {
        $controller = $this->runAction('stop', [$this->refused()]);

        $this->assertSame([], $controller->success);
        $this->assertSame(['Your subscription could not be paused. Please try again later.'], $controller->errors);
        $this->assertSame([self::ACCOUNT], $controller->redirections);
    }

    public function testResumeRefusedShowsErrorAndNoSuccess()
    {
        $controller = $this->runAction('resume', [$this->refused()]);

        $this->assertSame([], $controller->success);
        $this->assertSame(['Your subscription could not be resumed. Please try again later.'], $controller->errors);
        $this->assertSame([self::ACCOUNT], $controller->redirections);
    }

    /**
     * Réponse illisible (page d'erreur d'un proxy) : pas de succès non plus
     */
    public function testUnreadableResponseIsAFailure()
    {
        $controller = $this->runAction('stop', [new Response(502, ['Content-Type' => 'text/html'], '<html>Bad Gateway</html>')]);

        $this->assertSame([], $controller->success);
        $this->assertSame(['Your subscription could not be paused. Please try again later.'], $controller->errors);
    }

    /**
     * Réponse 2xx sans contenu lisible : rien ne prouve que Ciklik a accepté
     */
    public function testEmptySuccessBodyIsAFailure()
    {
        $controller = $this->runAction('resume', [new Response(200, [], '')]);

        $this->assertSame([], $controller->success);
        $this->assertSame(['Your subscription could not be resumed. Please try again later.'], $controller->errors);
    }

    /**
     * Identifiant invalide : retour à Mes abonnements sans appel à l'API
     */
    public function testInvalidUuidMakesNoApiCall()
    {
        \Tools::$requestValues = ['uuid' => 'pas-un-uuid'];
        $controller = $this->runAction('stop', []);

        $this->assertSame([], $this->history);
        $this->assertSame([], $controller->success);
        $this->assertSame([self::ACCOUNT], $controller->redirections);
    }

    /**
     * Changement de date : une page d'erreur de proxy n'est plus un succès
     */
    public function testNewDateUnreadableResponseIsAFailure()
    {
        \Tools::$requestValues = ['uuid' => self::UUID, 'next_billing' => date('Y-m-d', strtotime('+10 days'))];
        $controller = $this->runAction('newdate', [new Response(502, ['Content-Type' => 'text/html'], '<html>Bad Gateway</html>')]);

        $this->assertSame([], $controller->success);
        $this->assertSame(['Your renewal date could not be changed. Please try again later.'], $controller->errors);
    }

    /**
     * Ciklik injoignable : message d'erreur, pas d'erreur fatale
     */
    public function testUnreachableApiIsAFailure()
    {
        $controller = $this->runAction('resume', [new ConnectException('Connection refused', new Request('PUT', 'subscriptions/' . self::UUID))]);

        $this->assertSame([], $controller->success);
        $this->assertSame(['Your subscription could not be resumed. Please try again later.'], $controller->errors);
        $this->assertCount(1, \PrestaShopLogger::$logs);
        $this->assertStringContainsString('exception GuzzleHttp\\Exception\\ConnectException', \PrestaShopLogger::$logs[0]['message']);
    }

    /**
     * Refus journalisé avec le code HTTP et les champs, sans le texte de l'API
     */
    public function testRefusalIsLoggedWithoutApiText()
    {
        $this->runAction('stop', [$this->refused()]);

        $this->assertCount(1, \PrestaShopLogger::$logs);
        $log = \PrestaShopLogger::$logs[0];
        $this->assertStringContainsString('pause', $log['message']);
        $this->assertStringContainsString(self::UUID, $log['message']);
        $this->assertStringContainsString('HTTP 422', $log['message']);
        $this->assertStringNotContainsString('engagement', $log['message']);
    }

    private function runAction(string $action, array $responses)
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));
        $api = new Subscription(new \Link(), new Client([
            'handler' => $stack,
            'base_uri' => 'https://mock.test/api/',
            'http_errors' => false,
        ]));

        $controller = new class($api) extends \CiklikSubscriptionModuleFrontController {
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
        $controller->context = (object) ['link' => new \Link()];
        $controller->module = new class() {
            public $name = 'ciklik';

            public function l($string, $specific = false)
            {
                return $string;
            }
        };

        $method = new \ReflectionMethod($controller, $action);
        if (PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }
        $method->invoke($controller);

        return $controller;
    }

    private function accepted(bool $active): Response
    {
        return new Response(200, [], json_encode(['data' => ['uuid' => self::UUID, 'active' => $active]]));
    }

    private function refused(): Response
    {
        return new Response(422, [], json_encode([
            'message' => 'The given data was invalid.',
            'errors' => ['active' => ['Abonnement sous engagement.']],
        ]));
    }

    private function sentBody(): array
    {
        $this->assertCount(1, $this->history);

        return json_decode((string) $this->history[0]['request']->getBody(), true);
    }
}
