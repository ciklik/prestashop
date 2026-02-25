<?php

/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Api;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Customer extends CiklikApiClient
{
    public function getAll(array $options = [])
    {
        $this->setRoute('customers');

        return $this->get($options);
    }

    public function getOne(string $ciklik_customer_uuid)
    {
        $error = $this->setRouteWithValidation(
            'customers/%s',
            $ciklik_customer_uuid,
            'uuid',
            'Invalid customer UUID format',
        );

        if (null !== $error) {
            return $error;
        }

        return $this->get();
    }
}
