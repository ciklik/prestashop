<?php

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Helpers;
use \CustomerThread;
use \CustomerMessage;
use \Tools;

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
