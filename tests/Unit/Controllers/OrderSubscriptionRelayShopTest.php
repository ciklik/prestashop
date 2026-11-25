<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/../../../controllers/hook/DisplayOrderSubscriptionInfoHookController.php';

/**
 * Bloc abonnement de la fiche commande : les variables du bloc relais
 * (disponibilité de la recherche, contrats) sont calculées dans la boutique
 * de la commande, puis le contexte de l'employé est rendu
 */
class OrderSubscriptionRelayShopTest extends TestCase
{
    protected function setUp(): void
    {
        \Shop::resetMocks();
        \Shop::$featureActive = true;
        \Shop::$context = \Shop::CONTEXT_SHOP;
        \Shop::$contextShopId = 1;
    }

    protected function tearDown(): void
    {
        \Shop::resetMocks();
    }

    private static function order(int $idShop)
    {
        $order = new \stdClass();
        $order->id = 77;
        $order->id_shop = $idShop;
        $order->id_customer = 5;

        return $order;
    }

    public function testRelayVarsAreBuiltInOrderShop()
    {
        $controller = new RecordingOrderSubscriptionHookController();

        $vars = $controller->relayVars(null, self::order(2));

        $this->assertSame(['shop' => 2, 'customer' => 5, 'order' => 77], $vars);
        $this->assertSame(\Shop::CONTEXT_SHOP, \Shop::getContext());
        $this->assertSame(1, \Shop::getContextShopID());
    }

    public function testEmployeeContextIsRestoredOnFailure()
    {
        $controller = new RecordingOrderSubscriptionHookController();
        $controller->fail = true;

        try {
            $controller->relayVars(null, self::order(2));
            $this->fail('Exception attendue');
        } catch (\RuntimeException $e) {
            $this->assertSame('relay', $e->getMessage());
        }

        $this->assertSame(1, \Shop::getContextShopID());
    }
}

/**
 * Bloc sans contexte PrestaShop : relève la boutique du contexte au moment
 * où les variables relais sont calculées
 */
class RecordingOrderSubscriptionHookController extends \DisplayOrderSubscriptionInfoHookController
{
    /** @var bool */
    public $fail = false;

    public function __construct()
    {
    }

    public function relayVars($subscription, $order)
    {
        return $this->relayOverrideVarsInOrderShop($subscription, $order);
    }

    protected function buildRelayOverrideVars($subscription, $idCustomer, $idShop = 0, $idOrder = 0)
    {
        if ($this->fail) {
            throw new \RuntimeException('relay');
        }

        return ['shop' => \Shop::getContextShopID(), 'customer' => $idCustomer, 'order' => $idOrder];
    }
}
