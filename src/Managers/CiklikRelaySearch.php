<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Managers;

use GuzzleHttp\Client;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Recherche de points relais via l'API du transporteur, côté serveur, avec
 * les credentials déjà stockés dans la configuration du module transporteur
 * voisin. Aucun widget tiers : les résultats sont normalisés pour l'UI
 * générique du BO (liste + carte Leaflet).
 *
 * Chaque transporteur est livrable indépendamment : {@see supportsSearch()}
 * conditionne l'affichage de la recherche, avec repli sur les relais connus
 * du client + saisie manuelle quand elle est indisponible.
 *
 * NB : les clés de configuration lues ici varient selon les versions des
 * modules transporteurs — chaque driver essaie une liste de candidats et se
 * déclare non supporté s'il ne trouve pas de credentials exploitables.
 */
class CiklikRelaySearch
{
    const SEARCH_TIMEOUT = 8;
    const MAX_RESULTS = 15;

    /**
     * La recherche est-elle disponible pour ce module transporteur ?
     *
     * @param string $module Nom du module transporteur (minuscules)
     *
     * @return bool
     */
    public static function supportsSearch($module)
    {
        switch ($module) {
            case 'mondialrelay':
                return extension_loaded('soap') && null !== self::getMondialrelayCredentials();
            case 'colissimo':
                return null !== self::getColissimoCredentials();
            default:
                // dpdfrance, nkmgls, chronopost : recherche non implémentée à ce
                // stade (repli : relais connus + saisie manuelle)
                return false;
        }
    }

    /**
     * Recherche des relais autour d'une adresse.
     *
     * @param string $module Nom du module transporteur (minuscules)
     * @param array $address ['zipcode' => string, 'city' => string, 'country_iso' => string]
     *
     * @return array Liste normalisée : relay_id, name, address1, address2,
     *               zipcode, city, country_iso, latitude, longitude, + extras
     *
     * @throws \Exception si la recherche échoue (message générique, détail loggé)
     */
    public static function searchRelays($module, array $address)
    {
        $zipcode = isset($address['zipcode']) ? trim((string) $address['zipcode']) : '';
        $city = isset($address['city']) ? trim((string) $address['city']) : '';
        $countryIso = isset($address['country_iso']) && preg_match('/^[a-zA-Z]{2}$/', (string) $address['country_iso'])
            ? strtoupper((string) $address['country_iso'])
            : 'FR';

        if ('' === $zipcode) {
            throw new \Exception('Zip code is required');
        }

        switch ($module) {
            case 'mondialrelay':
                return self::searchMondialrelay($zipcode, $city, $countryIso);
            case 'colissimo':
                return self::searchColissimo($zipcode, $city, $countryIso);
            default:
                throw new \Exception('Search is not available for this carrier');
        }
    }

    /**
     * Credentials Mondial Relay depuis la configuration du module voisin.
     * Selon les versions : clés directes ou JSON MONDIALRELAY_ACCOUNT_DETAIL.
     *
     * @return array|null ['enseigne' => string, 'key' => string]
     */
    private static function getMondialrelayCredentials()
    {
        // Clés vérifiées contre les sources des modules : 3.x officiel
        // (MONDIALRELAY_WEBSERVICE_*, cf. mondialrelay.php const WEBSERVICE_*)
        // puis 2.x legacy (MR_*_WEBSERVICE).
        foreach ([
            ['MONDIALRELAY_WEBSERVICE_ENSEIGNE', 'MONDIALRELAY_WEBSERVICE_KEY'],
            ['MR_ENSEIGNE_WEBSERVICE', 'MR_KEY_WEBSERVICE'],
        ] as $candidates) {
            $enseigne = \Configuration::get($candidates[0]);
            $key = \Configuration::get($candidates[1]);
            if ($enseigne && $key) {
                return ['enseigne' => (string) $enseigne, 'key' => (string) $key];
            }
        }

        return null;
    }

    /**
     * Recherche Mondial Relay : WSI4_PointRelais_Recherche (SOAP).
     * La signature Security est le MD5 majuscule de la concaténation des
     * paramètres envoyés (dans l'ordre du contrat) suivie de la clé privée.
     *
     * @return array
     *
     * @throws \Exception
     */
    private static function searchMondialrelay($zipcode, $city, $countryIso)
    {
        $credentials = self::getMondialrelayCredentials();

        if (null === $credentials || !extension_loaded('soap')) {
            throw new \Exception('Search is not available for this carrier');
        }

        $params = [
            'Enseigne' => $credentials['enseigne'],
            'Pays' => $countryIso,
            'NumPointRelais' => '',
            'Ville' => $city,
            'CP' => $zipcode,
            'Latitude' => '',
            'Longitude' => '',
            'Taille' => '',
            'Poids' => '',
            'Action' => '',
            'DelaiEnvoi' => '0',
            'RayonRecherche' => '20',
            'TypeActivite' => '',
            'NombreResultats' => (string) self::MAX_RESULTS,
        ];
        $params['Security'] = strtoupper(md5(implode('', $params) . $credentials['key']));

        try {
            // connection_timeout ne borne que l'établissement de connexion ; la
            // lecture de la réponse et le téléchargement du WSDL dépendent du
            // socket. On borne explicitement le socket (default_socket_timeout
            // vaut 60s par défaut) pour qu'une API Mondial Relay lente ne bloque
            // pas un worker PHP-FPM du back-office, et on met le WSDL en cache.
            $streamContext = stream_context_create(['http' => ['timeout' => self::SEARCH_TIMEOUT]]);
            $client = new \SoapClient('https://api.mondialrelay.com/Web_Services.asmx?WSDL', [
                'connection_timeout' => self::SEARCH_TIMEOUT,
                'stream_context' => $streamContext,
                'cache_wsdl' => WSDL_CACHE_MEMORY,
                'exceptions' => true,
            ]);
            $response = $client->WSI4_PointRelais_Recherche($params);
        } catch (\Exception $e) {
            // Ne pas journaliser le message brut : il peut contenir des extraits
            // de requête/réponse. La classe suffit au diagnostic.
            self::logSearchError('mondialrelay', get_class($e));
            throw new \Exception('Carrier API error');
        }

        $result = isset($response->WSI4_PointRelais_RechercheResult) ? $response->WSI4_PointRelais_RechercheResult : null;

        if (!$result || !isset($result->STAT) || '0' !== (string) $result->STAT) {
            self::logSearchError('mondialrelay', 'STAT=' . ($result && isset($result->STAT) ? $result->STAT : 'n/a'));
            throw new \Exception('Carrier API error');
        }

        $points = [];
        if (isset($result->PointsRelais->PointRelais_Details)) {
            $points = is_array($result->PointsRelais->PointRelais_Details)
                ? $result->PointsRelais->PointRelais_Details
                : [$result->PointsRelais->PointRelais_Details];
        }

        $items = [];
        foreach ($points as $point) {
            $items[] = [
                'relay_id' => trim((string) $point->Num),
                'name' => trim((string) $point->LgAdr1),
                'name2' => trim((string) $point->LgAdr2),
                'address1' => trim((string) $point->LgAdr3),
                'address2' => trim((string) $point->LgAdr4),
                'zipcode' => trim((string) $point->CP),
                'city' => trim((string) $point->Ville),
                'country_iso' => trim((string) $point->Pays),
                // Coordonnées renvoyées avec une virgule décimale
                'latitude' => (float) str_replace(',', '.', (string) $point->Latitude),
                'longitude' => (float) str_replace(',', '.', (string) $point->Longitude),
            ];
        }

        return $items;
    }

    /**
     * Credentials Colissimo depuis la configuration du module voisin.
     *
     * @return array|null ['account' => string, 'password' => string]
     */
    private static function getColissimoCredentials()
    {
        // Clé vérifiée contre les sources du module officiel La Poste :
        // COLISSIMO_ACCOUNT_LOGIN + COLISSIMO_ACCOUNT_PASSWORD.
        foreach ([
            ['COLISSIMO_ACCOUNT_LOGIN', 'COLISSIMO_ACCOUNT_PASSWORD'],
            ['COLISSIMO_ACCOUNT_NUMBER', 'COLISSIMO_ACCOUNT_PASSWORD'],
        ] as $candidates) {
            $account = \Configuration::get($candidates[0]);
            $password = \Configuration::get($candidates[1]);
            if ($account && $password) {
                return ['account' => (string) $account, 'password' => (string) $password];
            }
        }

        return null;
    }

    /**
     * Recherche Colissimo : findRDVPointRetraitAcheminement (REST).
     *
     * @return array
     *
     * @throws \Exception
     */
    private static function searchColissimo($zipcode, $city, $countryIso)
    {
        $credentials = self::getColissimoCredentials();

        if (null === $credentials) {
            throw new \Exception('Search is not available for this carrier');
        }

        try {
            $client = new Client(['timeout' => self::SEARCH_TIMEOUT]);
            $httpResponse = $client->request(
                'POST',
                'https://ws.colissimo.fr/pointretrait-ws-cxf/PointRetraitServiceWS/2.0/rest/findRDVPointRetraitAcheminement',
                [
                    'json' => [
                        'accountNumber' => $credentials['account'],
                        'password' => $credentials['password'],
                        'address' => '',
                        'zipCode' => $zipcode,
                        'city' => $city,
                        'countryCode' => $countryIso,
                        'weight' => '1',
                        'shippingDate' => date('d/m/Y'),
                        'filterRelay' => '1',
                        'optionInter' => '0',
                    ],
                ]
            );
            $body = json_decode((string) $httpResponse->getBody(), true);
        } catch (\Exception $e) {
            // Ne pas journaliser le message brut : il peut contenir des extraits
            // de requête/réponse (credentials dans le corps). La classe suffit.
            self::logSearchError('colissimo', get_class($e));
            throw new \Exception('Carrier API error');
        }

        $return = isset($body['return']) ? $body['return'] : null;

        if (!$return || !isset($return['errorCode']) || 0 !== (int) $return['errorCode']) {
            self::logSearchError('colissimo', 'errorCode=' . ($return && isset($return['errorCode']) ? $return['errorCode'] : 'n/a'));
            throw new \Exception('Carrier API error');
        }

        $points = isset($return['listePointRetraitAcheminement']) ? $return['listePointRetraitAcheminement'] : [];

        $items = [];
        foreach ((array) $points as $point) {
            if (empty($point['identifiant'])) {
                continue;
            }
            $items[] = [
                'relay_id' => trim((string) $point['identifiant']),
                'name' => isset($point['nom']) ? trim((string) $point['nom']) : '',
                'address1' => isset($point['adresse1']) ? trim((string) $point['adresse1']) : '',
                'address2' => isset($point['adresse2']) ? trim((string) $point['adresse2']) : '',
                'zipcode' => isset($point['codePostal']) ? trim((string) $point['codePostal']) : '',
                'city' => isset($point['localite']) ? trim((string) $point['localite']) : '',
                'country_iso' => isset($point['codePays']) ? trim((string) $point['codePays']) : $countryIso,
                'latitude' => isset($point['coordGeolocalisationLatitude']) ? (float) $point['coordGeolocalisationLatitude'] : 0,
                'longitude' => isset($point['coordGeolocalisationLongitude']) ? (float) $point['coordGeolocalisationLongitude'] : 0,
                'product_code' => isset($point['typeDePoint']) ? trim((string) $point['typeDePoint']) : '',
                'network' => isset($point['reseau']) ? trim((string) $point['reseau']) : '',
            ];

            if (count($items) >= self::MAX_RESULTS) {
                break;
            }
        }

        return $items;
    }

    /**
     * @param string $module
     * @param string $detail
     */
    private static function logSearchError($module, $detail)
    {
        \PrestaShopLogger::addLog(
            'CiklikRelaySearch[' . $module . '] - ' . $detail,
            2,
            null,
            'CiklikRelaySearch',
            null,
            true
        );
    }
}
