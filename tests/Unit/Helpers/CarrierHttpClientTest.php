<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Helpers\CarrierHttpClient;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Client HTTP curl des recherches Colissimo et DPD : https seulement, aucune
 * redirection suivie, délai borné, indépendant de Guzzle
 */
class CarrierHttpClientTest extends TestCase
{
    /**
     * GET : paramètres encodés dans l'URL (RFC 3986, valeurs vides gardées),
     * sans corps
     */
    public function testGetRequestOptions()
    {
        $options = CarrierHttpClient::curlOptions('get', 'https://mypudo.pickup-services.com/mypudo/mypudo.asmx/GetPudoList', [
            'query' => ['zipCode' => '75011', 'city' => 'PARIS ELYSEE', 'address' => ''],
            'timeout' => 8,
        ]);

        $this->assertSame(
            'https://mypudo.pickup-services.com/mypudo/mypudo.asmx/GetPudoList?zipCode=75011&city=PARIS%20ELYSEE&address=',
            $options[CURLOPT_URL]
        );
        $this->assertTrue($options[CURLOPT_HTTPGET]);
        $this->assertArrayNotHasKey(CURLOPT_POSTFIELDS, $options);
        $this->assertSame(8, $options[CURLOPT_TIMEOUT]);
        $this->assertSame(8, $options[CURLOPT_CONNECTTIMEOUT]);
    }

    /**
     * POST : corps JSON et en-tête correspondant
     */
    public function testPostJsonRequestOptions()
    {
        $options = CarrierHttpClient::curlOptions('POST', 'https://ws.colissimo.fr/rest', [
            'json' => ['accountNumber' => '123456', 'city' => 'Saint-Étienne'],
        ]);

        $this->assertTrue($options[CURLOPT_POST]);
        $this->assertSame(['accountNumber' => '123456', 'city' => 'Saint-Étienne'], json_decode($options[CURLOPT_POSTFIELDS], true));
        $this->assertContains('Content-Type: application/json', $options[CURLOPT_HTTPHEADER]);
        $this->assertSame(CarrierHttpClient::DEFAULT_TIMEOUT, $options[CURLOPT_TIMEOUT]);
    }

    /**
     * Toujours : aucune redirection suivie, https imposé à curl (y compris
     * pour une redirection), certificat vérifié
     */
    public function testRequestsNeverFollowRedirectsAndStayOnHttps()
    {
        foreach (['GET', 'POST'] as $method) {
            $options = CarrierHttpClient::curlOptions($method, 'https://ws.colissimo.fr/rest');

            $this->assertFalse($options[CURLOPT_FOLLOWLOCATION], $method);
            $this->assertSame(0, $options[CURLOPT_MAXREDIRS], $method);
            $this->assertSame(CURLPROTO_HTTPS, $options[CURLOPT_PROTOCOLS], $method);
            $this->assertSame(CURLPROTO_HTTPS, $options[CURLOPT_REDIR_PROTOCOLS], $method);
            $this->assertTrue($options[CURLOPT_SSL_VERIFYPEER], $method);
            $this->assertSame(2, $options[CURLOPT_SSL_VERIFYHOST], $method);
            $this->assertTrue($options[CURLOPT_RETURNTRANSFER], $method);
        }
    }

    /**
     * Adresse non https ou méthode inattendue : refus avant tout appel
     */
    public function testRejectsPlainHttpAndUnsupportedMethods()
    {
        foreach ([
            ['GET', 'http://mypudo.pickup-services.com/mypudo/mypudo.asmx/GetPudoList'],
            ['GET', 'ftp://ws.colissimo.fr/rest'],
            ['GET', '//ws.colissimo.fr/rest'],
            ['PUT', 'https://ws.colissimo.fr/rest'],
            ['DELETE', 'https://ws.colissimo.fr/rest'],
        ] as $case) {
            $refused = false;
            try {
                CarrierHttpClient::curlOptions($case[0], $case[1]);
            } catch (\InvalidArgumentException $e) {
                $refused = true;
            }
            $this->assertTrue($refused, implode(' ', $case));
        }
    }

    /**
     * Échec réel de curl (port fermé en local, sans réseau extérieur) :
     * exception générique portant le seul numéro d'erreur curl
     */
    public function testNetworkFailureRaisesGenericException()
    {
        if (!function_exists('curl_init')) {
            $this->markTestSkipped('ext-curl absente');
        }

        $message = null;
        try {
            (new CarrierHttpClient())->send('GET', 'https://127.0.0.1:1/', ['query' => ['key' => 'secret'], 'timeout' => 2]);
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
        }

        $this->assertNotNull($message);
        $this->assertMatchesRegularExpression('/^HTTP request failed \(curl error [1-9][0-9]*\)$/', $message);
        $this->assertStringNotContainsString('secret', $message);
    }
}
