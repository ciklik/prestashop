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
     * Refus connus de l'API sur le champ « product » des routes produits
     * d'abonnement, reconnus au début de leur texte (en français côté API) :
     * clé du refus => motif. Le module traduit chaque clé dans la langue du
     * client.
     */
    const KNOWN_PRODUCT_REFUSALS = [
        'not_attached' => "/^Ce produit n'est pas rattach/u",
        'last_product' => '/^Impossible de retirer le dernier produit/u',
        'unknown_product' => "/^Le produit n'existe pas/u",
        'other_tenant' => "/^Ce produit n'appartient pas/u",
    ];

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
        $decoded = json_decode($bodyContents, true);

        // Réponse non JSON (429 ou 502 en texte brut, page HTML d'un proxy) :
        // aucun contenu exploitable. array_key_exists() sur null levait une
        // TypeError en PHP 8, soit une erreur 500 au lieu d'un refus propre.
        $responseContents = is_array($decoded) ? $decoded : null;

        return [
            'status' => $this->responseIsSuccessful($responseContents, $response->getStatusCode()),
            'httpCode' => $response->getStatusCode(),
            'body' => null !== $responseContents && array_key_exists('data', $responseContents) ? $responseContents['data'] : [],
            'meta' => $responseContents['meta'] ?? null,
            'links' => $responseContents['links'] ?? null,
            'message' => $response->getReasonPhrase(),
            'errors' => $responseContents['errors'] ?? [],
        ];
    }

    /**
     * Message d'erreur à montrer au client pour une réponse refusée par l'API
     *
     * Refus connu du champ « product » : sa traduction par le module, si
     * elle est fournie. Sinon, premier message exploitable de errors, échappé
     * en HTML comme les autres textes de l'API affichés au client, à
     * condition que le client lise la langue de l'API ; $fallback (message du
     * module) dans tous les autres cas.
     *
     * @param array $response Réponse formatée par handleResponse() ou buildErrorResponse()
     * @param string $fallback Message générique de repli
     * @param array $knownRefusals Traductions des refus connus : clé de KNOWN_PRODUCT_REFUSALS => message
     * @param bool $apiTextAllowed Le texte brut de l'API (en français) peut être montré au client
     *
     * @return string
     */
    public static function customerErrorMessage($response, $fallback, array $knownRefusals = [], $apiTextAllowed = true)
    {
        $refusal = self::knownProductRefusal($response);

        if (null !== $refusal && isset($knownRefusals[$refusal]) && is_string($knownRefusals[$refusal])) {
            return $knownRefusals[$refusal];
        }

        if (!$apiTextAllowed) {
            return $fallback;
        }

        $message = is_array($response) && isset($response['errors'])
            ? self::firstErrorMessage($response['errors'])
            : null;

        if (null === $message) {
            return $fallback;
        }

        $escaped = htmlentities($message, ENT_QUOTES, 'UTF-8');

        // htmlentities rend une chaîne vide sur un UTF-8 invalide
        return '' === $escaped ? $fallback : $escaped;
    }

    /**
     * Refus connu de l'API sur le champ « product » ({@see KNOWN_PRODUCT_REFUSALS}).
     *
     * @param mixed $response Réponse formatée
     *
     * @return string|null Clé du refus, null si aucun refus connu
     */
    public static function knownProductRefusal($response)
    {
        if (!is_array($response) || !isset($response['errors']) || !is_array($response['errors'])
            || !isset($response['errors']['product'])) {
            return null;
        }

        foreach ((array) $response['errors']['product'] as $message) {
            if (!is_string($message)) {
                continue;
            }

            foreach (self::KNOWN_PRODUCT_REFUSALS as $key => $pattern) {
                if (1 === preg_match($pattern, trim($message))) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * Premier message d'erreur exploitable de errors, quelle que soit sa forme :
     * liste de messages (erreurs construites par le module), liste de listes,
     * ou messages rangés par champ (validation de l'API : {"champ": ["message"]}).
     *
     * Une chaîne rangée sous une clé nommée n'est pas retenue : c'est la forme
     * des erreurs réseau fabriquées par le client ({"message": "cURL error ..."}),
     * dont le texte technique n'a pas à être montré au client.
     *
     * @param mixed $errors
     *
     * @return string|null
     */
    public static function firstErrorMessage($errors)
    {
        if (!is_array($errors)) {
            return null;
        }

        foreach ($errors as $key => $error) {
            if (is_array($error)) {
                $error = reset($error);
            } elseif (!is_int($key)) {
                continue;
            }

            if (is_string($error) && '' !== trim($error)) {
                return $error;
            }
        }

        return null;
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
