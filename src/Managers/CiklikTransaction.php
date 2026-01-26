<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Managers;

use Db;
use Order;
use OrderPayment;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CiklikTransaction
{
    public static function getIdByOrder(Order $order)
    {
        if ('ciklik' === $order->module && $order->valid) {
            $orderPayments = OrderPayment::getByOrderReference($order->reference);

            if ($orderPayments && isset($orderPayments[0])) {
                return $orderPayments[0]->transaction_id;
            }
        }

        return false;
    }

    public static function update(string $ciklik_transaction_id, float $amount): void
    {
        Db::getInstance()->update('order_payment', ['amount' => $amount], '`transaction_id` = \'' . pSQL($ciklik_transaction_id) . '\'');
    }
}
