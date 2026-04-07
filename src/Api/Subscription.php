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
     *
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

    /**
     * Récupère les abonnements bruts sans transformation en DTO
     *
     * Utilisé par la prévision de stock pour éviter les appels coûteux
     * de processContents()/processUpsells() lors de l'agrégation.
     *
     * @param array $options Options API (filtres, pagination, etc.)
     *
     * @return array Réponse API brute (body non transformé)
     */
    public function indexRaw(array $options = [])
    {
        $this->setRoute('subscriptions');

        return $this->get($options);
    }

    public function getOne(string $ciklik_subscription_uuid)
    {
        $error = $this->setRouteWithValidation(
            'subscriptions/%s',
            $ciklik_subscription_uuid,
            'uuid',
            'Invalid subscription UUID format'
        );

        if (null !== $error) {
            return $error;
        }

        return $this->get();
    }

    public function update(string $ciklik_subscription_uuid, array $data)
    {
        $error = $this->setRouteWithValidation(
            'subscriptions/%s',
            $ciklik_subscription_uuid,
            'uuid',
            'Invalid subscription UUID format'
        );

        if (null !== $error) {
            return $error;
        }

        return $this->put([
            'json' => $data,
        ]);
    }

    public function remove(string $ciklik_subscription_uuid)
    {
        $error = $this->setRouteWithValidation(
            'subscriptions/%s',
            $ciklik_subscription_uuid,
            'uuid',
            'Invalid subscription UUID format'
        );

        return $error ?? $this->delete();
    }

    /**
     * Met à jour la quantité d'un produit dans un abonnement
     *
     * @param string $subscriptionUuid UUID de l'abonnement
     * @param string $externalId Identifiant externe du produit (format: id_product:id_product_attribute)
     * @param int $quantity Nouvelle quantité
     *
     * @return array Réponse API
     */
    public function updateProductQuantity(string $subscriptionUuid, string $externalId, int $quantity)
    {
        if (!$this->isValidUuid($subscriptionUuid)) {
            return $this->buildErrorResponse('Invalid subscription UUID format');
        }

        if (!$this->isValidExternalId($externalId)) {
            return $this->buildErrorResponse('Invalid product identifier format');
        }

        // rawurlencode évite que parse_url interprète le ":" de l'external_id comme host:port
        $this->setRoute('subscriptions/' . $subscriptionUuid . '/products/' . rawurlencode($externalId));

        return $this->patch([
            'json' => ['quantity' => $quantity],
        ]);
    }

    /**
     * Ajoute un produit à un abonnement (ou met à jour la quantité si déjà présent)
     *
     * @param string $subscriptionUuid UUID de l'abonnement
     * @param array $data Données du produit (external_id, name, quantity, tax)
     *
     * @return array Réponse API
     */
    public function addProduct(string $subscriptionUuid, array $data)
    {
        $error = $this->setRouteWithValidation(
            'subscriptions/%s/products',
            $subscriptionUuid,
            'uuid',
            'Invalid subscription UUID format'
        );

        if (null !== $error) {
            return $error;
        }

        return $this->post([
            'json' => $data,
        ]);
    }

    /**
     * Supprime un produit d'un abonnement
     *
     * @param string $subscriptionUuid UUID de l'abonnement
     * @param string $externalId Identifiant externe du produit (format: id_product:id_product_attribute)
     *
     * @return array Réponse API
     */
    public function removeProduct(string $subscriptionUuid, string $externalId)
    {
        if (!$this->isValidUuid($subscriptionUuid)) {
            return $this->buildErrorResponse('Invalid subscription UUID format');
        }

        if (!$this->isValidExternalId($externalId)) {
            return $this->buildErrorResponse('Invalid product identifier format');
        }

        // rawurlencode évite que parse_url interprète le ":" de l'external_id comme host:port
        $this->setRoute('subscriptions/' . $subscriptionUuid . '/products/' . rawurlencode($externalId));

        return $this->delete();
    }

    /**
     * Valide le format d'un external_id de produit
     *
     * Format attendu : id_product:id_product_attribute (ex: 123:456)
     * Avec optionnellement un suffixe de customisation _md5 (ex: 123:456_abc123def...)
     *
     * @param string $externalId L'identifiant à valider
     *
     * @return bool True si le format est valide
     */
    private function isValidExternalId(string $externalId): bool
    {
        return (bool) preg_match('/^[0-9]+:[0-9]+(_[0-9a-f]{32})?$/i', $externalId);
    }
}
