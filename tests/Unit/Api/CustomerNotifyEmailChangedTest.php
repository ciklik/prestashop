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
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Api\Customer;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Signal « email changé » : seul l'identifiant du client part vers Ciklik, jamais son adresse.
 */
class CustomerNotifyEmailChangedTest extends TestCase
{
    public function testOnlyTheCustomerIdIsSent()
    {
        \Configuration::resetMocks();
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(204)]));
        $stack->push(Middleware::history($history));

        $response = (new Customer(new \Link(), new Client(['handler' => $stack, 'base_uri' => 'https://ciklik.example/api/v3/'])))
            ->notifyEmailChanged(7, ['timeout' => 2]);

        $this->assertTrue($response['status']);
        $request = $history[0]['request'];
        $this->assertSame('PUT', $request->getMethod());
        $this->assertSame('/api/v3/customers/email', $request->getUri()->getPath());
        $this->assertSame(['external_id' => 7], json_decode((string) $request->getBody(), true));
        $this->assertSame(2, $history[0]['options']['timeout']);
    }
}
