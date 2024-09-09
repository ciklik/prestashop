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

class Subscribable extends CiklikApiClient
{
    public function push(array $variant)
    {
        $this->setRoute('products');

        return $this->post([
            'json' => $variant,
        ]);
    }
}
