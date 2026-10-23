<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Api;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Api\Shop;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Déclaration de la boutique (PUT whoiam) : une adresse verrouillée chez Ciklik est refusée en 409
 */
class ShopMetadataTest extends TestCase
{
    public function testConflictMeansEndpointLocked()
    {
        $response = $this->metadataWith(new Response(409, [], json_encode(['message' => "L'adresse de la boutique est verrouillée"])));

        $this->assertFalse($response['status']);
        $this->assertTrue(Shop::isEndpointLocked($response));
    }

    public function testOtherResponsesAreNotEndpointLocked()
    {
        $this->assertFalse(Shop::isEndpointLocked($this->metadataWith(new Response(200, [], json_encode(['data' => []])))));
        $this->assertFalse(Shop::isEndpointLocked($this->metadataWith(new Response(500))));
        $this->assertFalse(Shop::isEndpointLocked([]));
    }

    private function metadataWith(Response $response)
    {
        \Configuration::resetMocks();
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([$response])), 'base_uri' => 'https://ciklik.example/api/v3/']);

        return (new Shop(new \Link(), $client))->metadata(['prestashop_endpoint' => 'https://boutique.example']);
    }
}
