<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Gateway;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Api\Order;
use PrestaShop\Module\Ciklik\Data\OrderData;
use PrestaShop\Module\Ciklik\Gateway\AbstractGateway;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Appel venu de Ciklik (renouvellement, paiement) pendant que la boutique ne peut pas joindre l'API Ciklik : jamais
 * d'erreur fatale, un journal sans secret et une réponse 503 que Ciklik réessaie.
 */
class CiklikUnreachableTest extends TestCase
{
    protected function setUp(): void
    {
        \Configuration::resetMocks();
        \PrestaShopLogger::resetLogs();
    }

    private static function api(array $queue)
    {
        return new Order(new \Link(), new Client([
            'handler' => HandlerStack::create(new MockHandler($queue)),
            'base_uri' => 'https://ciklik.example/api/v3/',
        ]));
    }

    /**
     * Erreur réseau levée par Guzzle 7 (ConnectException, qui sort du client) ou rendue par Guzzle 5 et 6
     * (RequestException sans réponse)
     *
     * @dataProvider networkErrors
     */
    public function testAnUnreachableCiklikIsReported(\Exception $error)
    {
        list($orderData, $failure) = AbstractGateway::fetchCiklikOrder(1357603, self::api([$error]));

        $this->assertNull($orderData);
        $this->assertSame($error, $failure);
    }

    public function networkErrors()
    {
        $request = new Request('GET', 'orders/1357603');

        return [
            'Guzzle 7' => [new ConnectException('cURL error 7: Failed to connect to 127.0.0.1 port 8479', $request)],
            'Guzzle 5 et 6' => [new RequestException('cURL error 28: Operation timed out', $request)],
        ];
    }

    /**
     * Dernier recours : toute erreur levée pendant la lecture est rapportée, jamais propagée
     */
    public function testAnyErrorOfTheCallIsReported()
    {
        $api = new class(new \Link(), new Client()) extends Order {
            public function getOne(int $ciklik_order_id, array $options = [])
            {
                throw new \TypeError('unexpected');
            }
        };

        list($orderData, $failure) = AbstractGateway::fetchCiklikOrder(1357603, $api);

        $this->assertNull($orderData);
        $this->assertInstanceOf(\TypeError::class, $failure);
    }

    public function testAnAnswerOfCiklikIsNotAFailure()
    {
        list($orderData, $failure) = AbstractGateway::fetchCiklikOrder(1357603, self::api([new Response(404, [], '{}')]));

        $this->assertNull($orderData);
        $this->assertNull($failure);

        $body = ['data' => ['order_id' => 1357603, 'user_uuid' => 'a1b2c3d4-e5f6-4a7b-8c9d-e0f1a2b3c4d5', 'status' => 'completed', 'created_at' => '2026-11-07 04:00:00']];
        list($orderData, $failure) = AbstractGateway::fetchCiklikOrder(1357603, self::api([new Response(200, [], json_encode($body))]));

        $this->assertInstanceOf(OrderData::class, $orderData);
        $this->assertNull($failure);
    }

    /**
     * Le journal dit quoi et pourquoi, sans le jeton d'API ni les caractères que PrestaShopLogger refuse
     */
    public function testTheFailureIsLoggedWithoutSecret()
    {
        \Configuration::updateValue(\Ciklik::CONFIG_API_TOKEN, 'tok_live_secret');

        AbstractGateway::logCiklikUnreachable('OrderGateway', 1357603, new \RuntimeException('<refused> {Authorization: Bearer tok_live_secret}'));

        $this->assertCount(1, \PrestaShopLogger::$logs);
        $log = \PrestaShopLogger::$logs[0];
        $this->assertSame(3, $log['severity']);
        $this->assertStringContainsString('Ciklik OrderGateway', $log['message']);
        $this->assertStringContainsString('1357603', $log['message']);
        $this->assertStringContainsString('RuntimeException', $log['message']);
        $this->assertStringContainsString('refused', $log['message']);
        $this->assertStringNotContainsString('tok_live_secret', $log['message']);
        $this->assertSame(0, preg_match('/[<>{}]/', $log['message']));
    }

    /**
     * Renouvellement poussé par Ciklik, API Ciklik sur un port fermé (cas d'un .env.test oublié) : 503 et JSON court
     */
    public function testTheRenewalPushAnswers503()
    {
        $result = self::push('OrderGateway', ['prestashop_cart_id' => 12, 'ciklik_order_id' => 1357603, 'order_type' => 'subscription_renew']);

        $this->assertSame(503, $result['status'], $result['output']);
        $this->assertSame(['error' => AbstractGateway::CIKLIK_UNREACHABLE_ERROR], json_decode($result['body'], true));
        $this->assertStringContainsString('Ciklik OrderGateway', $result['logs'][0]['message']);
        $this->assertStringContainsString('ConnectException', $result['logs'][0]['message']);
    }

    public function testThePaymentPushAnswers503()
    {
        $result = self::push('PaymentGateway', ['ciklik_order_id' => 1357603]);

        $this->assertSame(503, $result['status'], $result['output']);
        $this->assertSame(['error' => AbstractGateway::CIKLIK_UNREACHABLE_ERROR], json_decode($result['body'], true));
        $this->assertStringContainsString('Ciklik PaymentGateway', $result['logs'][0]['message']);
    }

    /**
     * Joue la requête dans un processus à part (la réponse termine le script par exit), avec le vrai Guzzle et une
     * vraie connexion refusée
     *
     * @return array{status: int|false, body: string, output: string, logs: array} output : corps et erreurs PHP
     */
    private static function push($gateway, array $request)
    {
        // Port libre, donc fermé
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) strrchr(stream_socket_get_name($server, false), ':'), 1);
        fclose($server);

        $script = tempnam(sys_get_temp_dir(), 'ciklik-push');
        $resultFile = $script . '.json';
        $errorFile = $script . '.err';
        file_put_contents($script, '<?php
// Aucun module installé ici : ni .env ni .env.test, l\'adresse de l\'API vient de $_ENV
define("_PS_MODULE_DIR_", ' . var_export(__DIR__ . '/no-module/', true) . ');
require ' . var_export(dirname(__DIR__, 2) . '/bootstrap.php', true) . ';
$_ENV["CIKLIK_API_URL_LIVE"] = $_ENV["CIKLIK_API_URL_SANDBOX"] = ' . var_export('http://127.0.0.1:' . $port . '/api/v3/', true) . ';

class Context
{
    public $link;
    public $cart;
    private static $instance;

    public static function getContext()
    {
        return self::$instance ?: self::$instance = new self();
    }

    public function __construct()
    {
        $this->link = new Link();
    }
}

class WebserviceKey
{
    public $key = "webservice-key";
}

$_SERVER["REQUEST_METHOD"] = "POST";
$_SERVER["HTTP_AUTHORIZATION"] = "Basic " . base64_encode("webservice-key:");
Tools::$requestValues = ' . var_export($request, true) . ';
register_shutdown_function(function () {
    file_put_contents(' . var_export($resultFile, true) . ', json_encode(["status" => http_response_code(), "logs" => PrestaShopLogger::$logs]));
});
$class = ' . var_export('PrestaShop\\Module\\Ciklik\\Gateway\\' . $gateway, true) . ';
(new $class(new Ciklik()))->handle();
');

        try {
            exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr ' . escapeshellarg($script) . ' 2>' . escapeshellarg($errorFile), $lines);
            $result = json_decode((string) @file_get_contents($resultFile), true) ?: ['status' => false, 'logs' => []];
            $errors = (string) @file_get_contents($errorFile);
        } finally {
            @unlink($script);
            @unlink($resultFile);
            @unlink($errorFile);
        }

        return ['status' => $result['status'], 'output' => implode("\n", $lines) . $errors, 'body' => implode("\n", $lines), 'logs' => $result['logs']];
    }
}
