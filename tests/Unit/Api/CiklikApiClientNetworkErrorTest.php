<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Api;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Api\Order;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Erreur réseau levée par Guzzle 5 et 6 (RequestException sans réponse) : rendue comme avant en réponse d'erreur,
 * et lisible par getNetworkError() pour les appels venus de Ciklik
 */
class CiklikApiClientNetworkErrorTest extends TestCase
{
    protected function setUp(): void
    {
        \Configuration::resetMocks();
    }

    private static function api(array $queue)
    {
        return new Order(new \Link(), new Client([
            'handler' => HandlerStack::create(new MockHandler($queue)),
            'base_uri' => 'https://ciklik.example/api/v3/',
        ]));
    }

    private static function timeout()
    {
        return new RequestException('cURL error 28: Operation timed out', new Request('GET', 'orders/1357603'));
    }

    public function testANetworkErrorIsKeptWithTheErrorResponse()
    {
        $error = self::timeout();
        $api = self::api([$error]);

        $response = $api->getAll();

        $this->assertSame($error, $api->getNetworkError());
        $this->assertFalse($response['status']);
        $this->assertSame(500, $response['httpCode']);
        $this->assertSame(['message' => 'cURL error 28: Operation timed out'], $response['errors']);
    }

    /**
     * Une erreur HTTP de l'API est une réponse de Ciklik, pas une erreur réseau
     */
    public function testAnHttpErrorOfTheApiIsNotANetworkError()
    {
        $api = self::api([new Response(404, [], '{"errors":{"order":["Not found"]}}'), new Response(502, [], 'Bad Gateway')]);

        $this->assertNull($api->getOne(1357603));
        $this->assertNull($api->getNetworkError());
        $this->assertNull($api->getOne(1357603));
        $this->assertNull($api->getNetworkError());
    }

    public function testTheNetworkErrorIsClearedByTheNextCall()
    {
        $api = self::api([self::timeout(), new Response(200, [], '{"data":[]}')]);

        $api->getAll();
        $this->assertNotNull($api->getNetworkError());

        $this->assertTrue($api->getAll()['status']);
        $this->assertNull($api->getNetworkError());
    }
}
