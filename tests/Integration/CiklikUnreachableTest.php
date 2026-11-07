<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Tests\Integration;

use PrestaShop\Module\Ciklik\Api\Order;
use PrestaShop\Module\Ciklik\Gateway\AbstractGateway;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Ciklik injoignable, avec la version de Guzzle que charge la boutique : la lecture de la commande rend l'erreur
 * réseau sans la lever, et un appel venu de Ciklik reçoit un 503. L'adresse de l'API est remplacée en mémoire par un
 * port fermé, jamais par un fichier .env.test.
 */
class CiklikUnreachableTest extends IntegrationTestCase
{
    const CIKLIK_ORDER_ID = 999999999;

    /** @var array */
    private $env = [];

    /** @var string */
    private $apiUrl;

    /** @var int[] */
    private $carts = [];

    protected function setUp(): void
    {
        // Port libre, donc fermé
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) strrchr(stream_socket_get_name($server, false), ':'), 1);
        fclose($server);
        $this->apiUrl = 'http://127.0.0.1:' . $port . '/api/v3/';

        foreach (['CIKLIK_API_URL_LIVE', 'CIKLIK_API_URL_SANDBOX'] as $name) {
            $this->env[$name] = isset($_ENV[$name]) ? $_ENV[$name] : null;
            $_ENV[$name] = $this->apiUrl;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->env as $name => $value) {
            if (null === $value) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $value;
            }
        }
        foreach ($this->carts as $idCart) {
            (new \Cart($idCart))->delete();
        }
        \Db::getInstance()->delete('log', "message LIKE 'Ciklik %Gateway - Ciklik order " . self::CIKLIK_ORDER_ID . " %'");
        parent::tearDown();
    }

    public function testTheOrderReadReportsTheNetworkError()
    {
        list($orderData, $failure) = AbstractGateway::fetchCiklikOrder(self::CIKLIK_ORDER_ID, new Order(new \Link()));

        $this->assertNull($orderData);
        $this->assertInstanceOf(\Throwable::class, $failure);
        $this->assertStringStartsWith('GuzzleHttp\\Exception\\', get_class($failure));
        $this->assertStringContainsString('127.0.0.1', $failure->getMessage());
    }

    public function testTheRenewalPushAnswers503()
    {
        $cart = new \Cart();
        $cart->id_lang = (int) \Context::getContext()->language->id;
        $cart->id_currency = (int) \Context::getContext()->currency->id;
        $this->assertTrue((bool) $cart->add());
        $this->carts[] = (int) $cart->id;

        $result = $this->push('OrderGateway', [
            'prestashop_cart_id' => (int) $cart->id,
            'ciklik_order_id' => self::CIKLIK_ORDER_ID,
            'order_type' => 'subscription_renew',
        ]);

        $this->assertSame(503, $result['status'], $result['output']);
        $this->assertSame(['error' => AbstractGateway::CIKLIK_UNREACHABLE_ERROR], json_decode($result['body'], true));
    }

    public function testThePaymentPushAnswers503()
    {
        $result = $this->push('PaymentGateway', ['ciklik_order_id' => self::CIKLIK_ORDER_ID]);

        $this->assertSame(503, $result['status'], $result['output']);
        $this->assertSame(['error' => AbstractGateway::CIKLIK_UNREACHABLE_ERROR], json_decode($result['body'], true));
    }

    /**
     * Joue la requête dans un processus à part, la réponse terminant le script par exit. La clé du webservice n'est
     * pas lue : la passerelle est créée sans son constructeur, l'essai porte sur la réponse.
     *
     * @return array{status: int|false, body: string, output: string}
     */
    private function push($gateway, array $request)
    {
        $script = tempnam(sys_get_temp_dir(), 'ciklik-push');
        $resultFile = $script . '.json';
        $errorFile = $script . '.err';
        file_put_contents($script, '<?php
$_ENV["CIKLIK_API_URL_LIVE"] = $_ENV["CIKLIK_API_URL_SANDBOX"] = ' . var_export($this->apiUrl, true) . ';
require ' . var_export(getenv('PS_ROOT') . '/config/config.inc.php', true) . ';
$context = Context::getContext();
$context->shop = new Shop((int) Configuration::get("PS_SHOP_DEFAULT"));
Shop::setContext(Shop::CONTEXT_SHOP, (int) $context->shop->id);
$context->language = new Language((int) Configuration::get("PS_LANG_DEFAULT"));
$context->currency = new Currency((int) Configuration::get("PS_CURRENCY_DEFAULT"));
$context->link = new Link();
$_SERVER["REQUEST_METHOD"] = "POST";
$_POST = ' . var_export($request, true) . ';
register_shutdown_function(function () {
    file_put_contents(' . var_export($resultFile, true) . ', json_encode(["status" => http_response_code()]));
});
$module = Module::getInstanceByName("ciklik");
$gateway = (new ReflectionClass(' . var_export('PrestaShop\\Module\\Ciklik\\Gateway\\' . $gateway, true) . '))->newInstanceWithoutConstructor();
$gateway->module = $module;
$gateway->handle();
');

        try {
            exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr ' . escapeshellarg($script) . ' 2>' . escapeshellarg($errorFile), $lines);
            $result = json_decode((string) @file_get_contents($resultFile), true) ?: ['status' => false];
            $errors = (string) @file_get_contents($errorFile);
        } finally {
            @unlink($script);
            @unlink($resultFile);
            @unlink($errorFile);
        }

        return ['status' => $result['status'], 'body' => implode("\n", $lines), 'output' => implode("\n", $lines) . $errors];
    }
}
