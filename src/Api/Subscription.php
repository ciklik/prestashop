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

    /**
     * Récupère la liste des commandes avec métadonnées de pagination et données transformées
     * Cette méthode préserve la structure complète de la réponse API (status, body, meta, etc.)
     * tout en transformant les données du body en objets OrderData
     *
     * @param array $options Options API (filtres, pagination, etc.)
     * @return array Structure de réponse complète avec données transformées
     */
    public function index(array $options = [])
    {
        $this->setRoute('subscriptions');

        $response = $this->get($options);
        
        // Si la réponse est réussie et contient des données, les transformer
        if (isset($response['status']) && $response['status'] && isset($response['body'])) {
            $response['body'] = SubscriptionData::collection($response['body']);
        }
        
        return $response;
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
