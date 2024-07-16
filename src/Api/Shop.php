<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Api;

use PrestaShop\Module\Ciklik\Data\ShopData;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Shop extends CiklikApiClient
{
    public function whoIAm(array $options = [])
    {
        $this->setRoute('whoiam');

        $response = $this->get($options);

        if ($response['status']) {
            return ShopData::create($response['body']);
        }

        return null;
    }

    public function metadata(array $metadata, array $options = [])
    {
        $this->setRoute('whoiam');

        return $this->put(
            array_merge(
                $options,
                [
                    'json' => ['metadata' => $metadata]
                ]
            )
        );
    }
}
