<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Api;

use PrestaShop\Module\Ciklik\Data\OrderData;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Order extends CiklikApiClient
{
    /**
     * Récupère toutes les commandes avec options de filtrage
     * 
     * @param array $options Options API (filtres, pagination, etc.)
     * @return array Structure de réponse complète de l'API
     */
    public function getAll(array $options = [])
    {
        $this->setRoute('orders');

        return $this->get($options);
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
        $this->setRoute('orders');

        $response = $this->get($options);
        
        // Si la réponse est réussie et contient des données, les transformer
        if (isset($response['status']) && $response['status'] && isset($response['body'])) {
            $response['body'] = OrderData::collection($response['body']);
        }
        
        return $response;
    }

    /**
     * Récupère une commande par son ID Ciklik
     * 
     * @param int $ciklik_order_id ID de la commande Ciklik
     * @param array $options Options API supplémentaires
     * @return OrderData|null Instance OrderData ou null si non trouvée
     */
    public function getOne(int $ciklik_order_id, array $options = [])
    {
        $this->setRoute("orders/{$ciklik_order_id}");

        $response = $this->get($options);

        if ($response['status']) {
            return OrderData::create($response['body']);
        }

        return null;
    }

    /**
     * Récupère une commande par son ID PrestaShop
     *
     * @param int $ps_order_id ID de la commande PrestaShop
     * @param array $options Options API supplémentaires
     * @return OrderData|null Instance OrderData ou null si non trouvée
     */
    public function getOneByPsOrderId(int $ps_order_id, array $options = [])
    {
        $queryString = http_build_query(['filter' => ['prestashop_order_id' => $ps_order_id]]);
        $this->setRoute('orders?' . $queryString);

        $response = $this->get($options);

        if ($response['status'] && $response['body'] && isset($response['body'][0])) {
            return OrderData::create($response['body'][0]);
        }

        return null;
    }
}
