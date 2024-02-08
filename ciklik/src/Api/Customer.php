<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Api;

class Customer extends CiklikApiClient
{
    public function getAll(array $options = [])
    {
        $this->setRoute('customers');

        return $this->get($options);
    }

    public function getOne(string $ciklik_customer_uuid)
    {
        $this->setRoute("customers/{$ciklik_customer_uuid}");

        return $this->get();
    }
}
