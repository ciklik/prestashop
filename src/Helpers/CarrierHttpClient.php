<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Helpers;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Client HTTP minimal des services REST des transporteurs (Colissimo, DPD
 * France), en curl : indépendant de la version de Guzzle chargée.
 *
 * Sous PrestaShop 1.7, l'autoloader du cœur passe avant celui du module et
 * fournit Guzzle 5, qui n'a pas de méthode request() : la recherche de relais
 * finissait en erreur fatale. curl est requis par PrestaShop lui-même.
 *
 * Toujours en https (protocole imposé à curl), sans suivi de redirection,
 * certificat vérifié, délai borné.
 */
class CarrierHttpClient
{
    /** Délai par défaut, en secondes */
    const DEFAULT_TIMEOUT = 10;

    /**
     * Envoie une requête et rend le statut et le corps de la réponse. Une
     * réponse d'erreur (4xx, 5xx) ou une redirection est rendue telle quelle :
     * à l'appelant de n'accepter que ce qu'il attend.
     *
     * @param string $method GET ou POST
     * @param string $url Adresse https du service
     * @param array $options query (paramètres d'URL), json (corps JSON), timeout (secondes)
     *
     * @return array ['status' => int, 'body' => string]
     *
     * @throws \InvalidArgumentException méthode ou adresse refusée
     * @throws \RuntimeException si la requête n'aboutit pas (réseau, TLS, délai)
     */
    public function send(string $method, string $url, array $options = []): array
    {
        return $this->execute(self::curlOptions($method, $url, $options));
    }

    /**
     * Options curl de la requête, sans effet de bord.
     *
     * @param string $method GET ou POST
     * @param string $url Adresse https du service
     * @param array $options query, json, timeout
     *
     * @return array Options curl (CURLOPT_*)
     *
     * @throws \InvalidArgumentException méthode ou adresse refusée
     */
    public static function curlOptions(string $method, string $url, array $options = []): array
    {
        $method = strtoupper($method);

        if (!in_array($method, ['GET', 'POST'], true)) {
            throw new \InvalidArgumentException('Unsupported HTTP method');
        }

        if (0 !== stripos($url, 'https://')) {
            throw new \InvalidArgumentException('HTTPS is required');
        }

        if (isset($options['query']) && is_array($options['query']) && [] !== $options['query']) {
            $url .= (false === strpos($url, '?') ? '?' : '&')
                . http_build_query($options['query'], '', '&', PHP_QUERY_RFC3986);
        }

        $timeout = isset($options['timeout']) && (int) $options['timeout'] > 0
            ? (int) $options['timeout']
            : self::DEFAULT_TIMEOUT;

        $headers = ['Accept: application/json, application/xml, text/xml'];

        $curl = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            // Pas de suivi de redirection : seul l'hôte appelé reçoit la requête
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'Ciklik-Prestashop',
        ];

        if ('POST' === $method) {
            $curl[CURLOPT_POST] = true;
            $curl[CURLOPT_POSTFIELDS] = isset($options['json']) ? (string) json_encode($options['json']) : '';
            $headers[] = 'Content-Type: application/json';
        } else {
            $curl[CURLOPT_HTTPGET] = true;
        }

        $curl[CURLOPT_HTTPHEADER] = $headers;

        return $curl;
    }

    /**
     * Exécute la requête curl. Surchargée par les tests, qui n'appellent
     * aucun réseau.
     *
     * @param array $curlOptions
     *
     * @return array ['status' => int, 'body' => string]
     *
     * @throws \RuntimeException si la requête n'aboutit pas
     */
    protected function execute(array $curlOptions): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('curl is not available');
        }

        $handle = curl_init();

        if (false === $handle) {
            throw new \RuntimeException('curl is not available');
        }

        curl_setopt_array($handle, $curlOptions);
        $body = curl_exec($handle);
        $errno = curl_errno($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);

        // Poignée libérée d'elle-même à partir de PHP 8, où curl_close() ne
        // fait plus rien (déprécié en 8.5)
        if (PHP_VERSION_ID < 80000) {
            curl_close($handle);
        }

        if (0 !== $errno || !is_string($body)) {
            // Numéro d'erreur curl seulement : jamais l'adresse ni le corps
            throw new \RuntimeException('HTTP request failed (curl error ' . $errno . ')');
        }

        return ['status' => $status, 'body' => $body];
    }
}
