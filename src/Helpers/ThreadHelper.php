<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Helpers;

use Configuration;
use Customer;
use CustomerMessage;
use CustomerThread;
use Order;
use Tools;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Class ThreadHelper
 */
trait ThreadHelper
{
    public function addDataToOrder(int $order_id, array $data)
    {
        // Vérifier si la création de thread est activée dans la configuration
        if (!Configuration::get(\Ciklik::CONFIG_ENABLE_ORDER_THREAD)) {
            return;
        }

        $order = new Order($order_id);
        $customer = new Customer($order->id_customer);

        // Récupérer le statut configuré, par défaut 'open' si non défini
        $thread_status = Configuration::get(\Ciklik::CONFIG_ORDER_THREAD_STATUS);
        if (empty($thread_status) || !in_array($thread_status, ['open', 'closed'])) {
            $thread_status = 'open';
        }

        $customer_thread = new CustomerThread();
        $customer_thread->id_contact = 0;
        $customer_thread->id_customer = (int) $order->id_customer;
        $customer_thread->id_shop = (int) $order->id_shop;
        $customer_thread->id_order = (int) $order->id;
        $customer_thread->id_lang = (int) $order->id_lang;
        $customer_thread->email = $customer->email;
        $customer_thread->status = $thread_status;
        $customer_thread->token = Tools::passwdGen(12);
        $customer_thread->add();

        foreach ($data as $key => $value) {
            $customer_message = new CustomerMessage();
            $customer_message->id_customer_thread = $customer_thread->id;
            $customer_message->id_employee = 0;
            $customer_message->message = '[CIKLIK] ' . $key . ':' . $value;
            $customer_message->private = true;
            $customer_message->add();
        }
    }
}
