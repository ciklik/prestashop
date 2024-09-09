<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Api;

use PrestaShop\Module\Ciklik\Data\SubscriptionData;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Subscription extends CiklikApiClient
{
    public function getAll(array $options = [])
    {
        $this->setRoute('subscriptions');

        $response = $this->get($options);

        if ($response['status']) {
            return SubscriptionData::collection($response['body']);
        }

        return null;
    }

    public function getOne(string $ciklik_subscription_uuid)
    {
        $this->setRoute("subscriptions/{$ciklik_subscription_uuid}");

        return $this->get();
    }

    public function update(string $ciklik_subscription_uuid, array $data)
    {
        $this->setRoute("subscriptions/{$ciklik_subscription_uuid}");

        return $this->put([
            'json' => $data,
        ]);
    }
}
