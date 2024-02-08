<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Api;

use PrestaShop\Module\Ciklik\Data\OrderData;

class Order extends CiklikApiClient
{
    public function getAll(array $options = [])
    {
        $this->setRoute('orders');

        return $this->get($options);
    }

    public function getOne(int $ciklik_order_id, array $options = [])
    {
        $this->setRoute("orders/{$ciklik_order_id}");

        $response = $this->get($options);

        if ($response['status']) {
            return OrderData::create($response['body']);
        }

        return null;
    }
}
