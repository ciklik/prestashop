<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

use PrestaShop\Module\Ciklik\Gateway\CartGateway;
use PrestaShop\Module\Ciklik\Gateway\OrderGateway;
use PrestaShop\Module\Ciklik\Gateway\Response;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CiklikGatewayModuleFrontController extends ModuleFrontController
{
    /**
     * @var PaymentModule
     */
    public $module;

    public function postProcess()
    {
        $request = Tools::getValue('request');

        switch ($request) {
            case 'carts':
                (new CartGateway($this->module))->handle();
                break;
            case 'orders':
                (new OrderGateway($this->module))->handle();
                break;
            default:
                (new Response())->setBody(['error' => "{$request} method doesn't exist"])->sendMethodNotAllowed();
                break;
        }
    }
}
