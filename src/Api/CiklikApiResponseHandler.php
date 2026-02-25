<?php

/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Api;

use Psr\Http\Message\ResponseInterface;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CiklikApiResponseHandler
{
    /**
     * Formate la réponse de l'API
     *
     * @param ResponseInterface $response Réponse HTTP de Guzzle
     *
     * @return array Tableau formaté avec status, httpCode, body, meta, links, message, errors
     */
    public function handleResponse($response)
    {
        // Dans Guzzle 6+, getBody() retourne un stream qui ne peut être lu qu'une seule fois
        // Il faut le convertir en chaîne pour le lire
        $bodyContents = (string) $response->getBody();
        $responseContents = json_decode($bodyContents, true);

        return [
            'status' => $this->responseIsSuccessful($responseContents, $response->getStatusCode()),
            'httpCode' => $response->getStatusCode(),
            'body' => array_key_exists('data', $responseContents) ? $responseContents['data'] : [],
            'meta' => $responseContents['meta'] ?? null,
            'links' => $responseContents['links'] ?? null,
            'message' => $response->getReasonPhrase(),
            'errors' => $responseContents['errors'] ?? [],
        ];
    }

    /**
     * Vérifie si la réponse est réussie ou non (code de réponse 200 à 299)
     *
     * @param array|null $responseContents Contenu de la réponse décodé
     * @param int $httpStatusCode Code de statut HTTP
     *
     * @return bool True si la réponse est réussie, false sinon
     */
    private function responseIsSuccessful($responseContents, $httpStatusCode)
    {
        // Retourner directement true, pas besoin de vérifier le body pour un code de statut 204
        // Le code de statut 204 est uniquement envoyé par /payments/order/update
        if ($httpStatusCode === 204) {
            return true;
        }

        return substr((string) $httpStatusCode, 0, 1) === '2' && $responseContents !== null;
    }
}
