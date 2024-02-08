<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Gateway;

use Cart;
use Context;
use PrestaShop\Module\Ciklik\Data\OrderData;
use PrestaShop\Module\Ciklik\Data\OrderValidationData;
use Tools;

class OrderGateway extends AbstractGateway implements EntityGateway
{
    public function post()
    {
        $cart = new Cart((int) Tools::getValue('prestashop_cart_id'));

        if (! $cart->id) {
            (new Response)->setBody(['error' => 'Cart not found'])->sendNotFound();
        }

        if ($cart->orderExists()) {
            (new Response)->setBody(['error' => 'This cart has already been processed'])->sendConflict();
        }

        $context = Context::getContext();

        $orderData = (new \PrestaShop\Module\Ciklik\Api\Order($context->link))->getOne((int) Tools::getValue('ciklik_order_id'));

        if (! $orderData instanceof OrderData) {
            (new Response)->setBody(['error' => 'Order has not been retrieved'])->sendBadRequest();
        }

        $orderValidationData = OrderValidationData::create($cart, $orderData);

        $this->module->validateOrder(
            $orderValidationData->id_cart,
            $orderValidationData->id_order_state,
            $orderValidationData->amount_paid,
            $orderValidationData->payment_method,
            $orderValidationData->message,
            $orderValidationData->extra_vars,
            $orderValidationData->currency_special,
            $orderValidationData->dont_touch_amount,
            $orderValidationData->secure_key
        );

        (new Response)->sendCreated();
    }
}
