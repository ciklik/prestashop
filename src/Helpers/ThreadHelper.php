<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Helpers;
use \CustomerMessage;
use \CustomerThread;
use \Tools;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Class ThreadHelper
 *
 * @package \PrestaShop\Module\Ciklik\Helpers
 */
trait ThreadHelper
{
    public function addDataToOrder(int $order_id, array $data)
    {
        $order = new \Order($order_id);
        $customer = new \Customer($order->id_customer);

        $customer_thread = new CustomerThread();
        $customer_thread->id_contact = 0;
        $customer_thread->id_customer = (int) $order->id_customer;
        $customer_thread->id_shop = (int) $order->id_shop;
        $customer_thread->id_order = (int) $order->id;
        $customer_thread->id_lang = (int) $order->id_lang;
        $customer_thread->email = $customer->email;
        $customer_thread->status = 'open';
        $customer_thread->token = Tools::passwdGen(12);
        $customer_thread->add();

        foreach ($data as $key => $value) {
            $customer_message = new CustomerMessage();
            $customer_message->id_customer_thread = $customer_thread->id;
            $customer_message->id_employee = 0;
            $customer_message->message = '[CIKLIK] '.$key.':'.$value;
            $customer_message->private = true;
            $customer_message->add();
        }
    }
}
