<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Managers;

use PrestaShop\Module\Ciklik\Helpers\CarrierHttpClient;

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
     * Identifiants du service Pickup « MyPudo » utilisés par le module DPD
     * France : ils sont propres à DPD France auprès de Pickup Services, pas au
     * marchand, et codés en dur à l'identique dans les modules officiels
     * PrestaShop (5.x), WooCommerce et Magento. Seule l'URL du service vient
     * de la configuration du module voisin (DPDFRANCE_RELAIS_MYPUDO_URL), ce
     * qui conditionne la disponibilité de la recherche.
     */
    const DPDFRANCE_PUDO_CARRIER = 'EXA';
    const DPDFRANCE_PUDO_KEY = 'deecd7bc81b71fcc0e292b53e826c48f';

    /**
     * Seuls hôtes MyPudo appelés : celui qu'installe le module DPD France.
     * L'URL vient de la configuration d'un module voisin, jamais d'un hôte
     * arbitraire (réseau interne, métadonnées du serveur).
     */
    const DPDFRANCE_PUDO_HOSTS = ['mypudo.pickup-services.com'];

    /**
     * Services SOAP : WSDL et point d'appel en https. Le WSDL de Mondial Relay
     * annonce une adresse http:// : sans « location » explicite, SoapClient y
     * enverrait l'enseigne et la signature de la requête en clair.
     */
    const MONDIALRELAY_WSDL = 'https://api.mondialrelay.com/Web_Services.asmx?WSDL';
    const MONDIALRELAY_ENDPOINT = 'https://api.mondialrelay.com/Web_Services.asmx';
    const CHRONOPOST_WSDL = 'https://ws.chronopost.fr/recherchebt-ws-cxf/PointRelaisServiceWS?wsdl';
    const CHRONOPOST_ENDPOINT = 'https://ws.chronopost.fr/recherchebt-ws-cxf/PointRelaisServiceWS';

    /**
     * Point d'appel REST (v2, JSON) de la recherche Colissimo. L'adresse
     * précédente (.../PointRetraitServiceWS/2.0/rest/...) menait au service
     * SOAP, qui répond « Error reading XMLStreamReader » à tout corps JSON.
     */
    const COLISSIMO_ENDPOINT = 'https://ws.colissimo.fr/pointretrait-ws-cxf/rest/v2/pointretrait/findRDVPointRetraitAcheminement';

    /**
     * Recherche Chronopost désactivée : route et parseur vérifiés, mais aucun
     * appel réussi n'a été observé avec un contrat réel. Le back-office et
     * l'espace client s'en tiennent aux relais connus (côté client : ceux
     * qui portent un nom, soit aucun pour Chronopost).
     */
    const CHRONOPOST_SEARCH_ENABLED = false;

    /**
     * La recherche est-elle disponible pour ce module transporteur, et pour
     * ce pays de livraison quand il est connu ?
     *
     * @param string $module Nom du module transporteur (minuscules)
     * @param string|null $countryIso Pays de l'adresse de livraison (ISO alpha-2),
     *                                null quand il n'est pas connu (back-office)
     *
     * @return bool
     */
    public static function supportsSearch($module, $countryIso = null)
    {
        switch ($module) {
            case 'mondialrelay':
                return extension_loaded('soap') && null !== self::getMondialrelayCredentials();
            case 'colissimo':
                return function_exists('curl_init') && null !== self::getColissimoCredentials();
            case 'dpdfrance':
                // Le réseau Pickup interrogé ne couvre que la France : pour une
                // adresse d'un autre pays, la recherche ne rendrait jamais rien
                if (is_string($countryIso) && '' !== $countryIso && 'FR' !== strtoupper($countryIso)) {
                    return false;
                }

                return function_exists('curl_init') && null !== self::getDpdfranceEndpoint();
            case 'chronopost':
                return self::CHRONOPOST_SEARCH_ENABLED
                    && extension_loaded('soap')
                    && null !== self::getChronopostCredentials();
            default:
                // nkmgls : widget GLS côté navigateur, module fermé, pas de
                // web service documenté avec les identifiants du marchand
                // (repli : relais connus du client)
                return false;
        }
    }

    /**
     * Recherche des relais autour d'une adresse.
     *
     * @param string $module Nom du module transporteur (minuscules)
     * @param array $address ['zipcode' => string, 'city' => string, 'country_iso' => string]
     * @param CarrierHttpClient|null $httpClient Client HTTP des services REST (Colissimo, DPD) ;
     *                                           injecté par les tests, créé sinon
     *
     * @return array Liste normalisée : relay_id, name, address1, address2,
     *               zipcode, city, country_iso, latitude, longitude, + extras
     *
     * @throws \Exception si la recherche échoue (message générique, détail loggé)
     */
    public static function searchRelays($module, array $address, $httpClient = null)
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
                return self::searchColissimo($zipcode, $city, $countryIso, $httpClient);
            case 'dpdfrance':
                return self::searchDpdfrance($zipcode, $city, $countryIso, $httpClient);
            case 'chronopost':
                if (!self::CHRONOPOST_SEARCH_ENABLED) {
                    throw new \Exception('Search is not available for this carrier');
                }

                return self::searchChronopost($zipcode, $city, $countryIso);
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
            // Création du client (téléchargement du WSDL) et appel sous un
            // délai de socket borné (voir withSocketTimeout), WSDL en cache
            $response = self::withSocketTimeout(self::SEARCH_TIMEOUT, function () use ($params) {
                $client = new \SoapClient(self::MONDIALRELAY_WSDL, self::soapClientOptions(self::MONDIALRELAY_ENDPOINT));

                return $client->WSI4_PointRelais_Recherche($params);
            });
        } catch (\Throwable $e) {
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
                'distance' => self::parseDistance(isset($point->Distance) ? $point->Distance : null),
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
     * Recherche Colissimo : findRDVPointRetraitAcheminement (REST v2, JSON).
     *
     * @return array
     *
     * @throws \Exception
     */
    private static function searchColissimo($zipcode, $city, $countryIso, $httpClient = null)
    {
        $credentials = self::getColissimoCredentials();

        if (null === $credentials) {
            throw new \Exception('Search is not available for this carrier');
        }

        try {
            // Pas de suivi de redirection (CarrierHttpClient) : le corps porte
            // le compte et le mot de passe Colissimo, il ne part que vers ce
            // point d'appel
            $response = self::httpClient($httpClient)->send('POST', self::COLISSIMO_ENDPOINT, [
                'timeout' => self::SEARCH_TIMEOUT,
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
            ]);
        } catch (\Throwable $e) {
            // Ne pas journaliser le message brut : il peut contenir des extraits
            // de requête/réponse (credentials dans le corps). La classe suffit.
            self::logSearchError('colissimo', get_class($e));
            throw new \Exception('Carrier API error');
        }

        return self::parseColissimoResponse($response['status'], $response['body'], $countryIso);
    }

    /**
     * Normalisation de la réponse de findRDVPointRetraitAcheminement (REST v2 :
     * champs à la racine ; une enveloppe « return » reste lue). Pure, sans
     * appel réseau.
     *
     * @param int $status Statut HTTP
     * @param string $body Corps de la réponse
     * @param string $countryIso Pays de la recherche, pour un point sans pays
     *
     * @return array
     *
     * @throws \Exception si le service refuse (statut d'échec, errorCode non nul) ou répond autre chose que du JSON
     */
    public static function parseColissimoResponse(int $status, string $body, string $countryIso = 'FR'): array
    {
        $decoded = json_decode($body, true);
        $result = is_array($decoded) && isset($decoded['return']) && is_array($decoded['return'])
            ? $decoded['return']
            : $decoded;
        $errorCode = is_array($result) && isset($result['errorCode']) && is_numeric($result['errorCode'])
            ? (int) $result['errorCode']
            : null;

        // Redirection, erreur du service (400 et code 201 pour des identifiants
        // refusés) ou réponse illisible : échec, code journalisé pour le marchand
        if ($status < 200 || $status >= 300 || 0 !== $errorCode) {
            self::logSearchError('colissimo', 'HTTP ' . $status . ' errorCode=' . (null === $errorCode ? 'n/a' : $errorCode));
            throw new \Exception('Carrier API error');
        }

        $points = isset($result['listePointRetraitAcheminement']) && is_array($result['listePointRetraitAcheminement'])
            ? $result['listePointRetraitAcheminement']
            : [];

        $items = [];
        foreach ($points as $point) {
            if (!is_array($point) || empty($point['identifiant'])) {
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
                'distance' => self::parseDistance(isset($point['distanceEnMetre']) ? $point['distanceEnMetre'] : null),
            ];

            if (count($items) >= self::MAX_RESULTS) {
                break;
            }
        }

        return $items;
    }

    /**
     * Point d'entrée GetPudoList du service MyPudo, dérivé de l'URL WSDL
     * configurée par le module DPD France (« .../mypudo.asmx?WSDL »). Le
     * binding HTTP GET du même service évite la dépendance à ext-soap.
     *
     * @return string|null
     */
    private static function getDpdfranceEndpoint()
    {
        $url = trim((string) \Configuration::get('DPDFRANCE_RELAIS_MYPUDO_URL'));

        if ('' === $url || !preg_match('#^https?://([^/?\#]+)(/[^?\#]*?)/?(\?.*)?$#i', $url, $m)) {
            return null;
        }

        // Hôte seul, port optionnel : aucun « user:pass@ » ni forme
        // exotique ne doit atteindre le client HTTP depuis la configuration
        if (!preg_match('/^([a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*)(:\d{1,5})?$/i', $m[1], $host)) {
            return null;
        }

        // Hôte du service MyPudo seulement
        if (!in_array(strtolower($host[1]), self::DPDFRANCE_PUDO_HOSTS, true)) {
            return null;
        }

        $path = $m[2];
        if (!preg_match('/\.asmx$/i', $path)) {
            return null;
        }

        return 'https://' . $m[1] . $path . '/GetPudoList';
    }

    /**
     * Recherche DPD France : GetPudoList du service Pickup MyPudo (HTTP GET,
     * réponse XML), mêmes paramètres que le module (adresse et ville en
     * majuscules sans accents, date du jour, pays FR uniquement).
     *
     * @return array
     *
     * @throws \Exception
     */
    private static function searchDpdfrance($zipcode, $city, $countryIso, $httpClient = null)
    {
        $endpoint = self::getDpdfranceEndpoint();

        if (null === $endpoint) {
            throw new \Exception('Search is not available for this carrier');
        }

        // Le réseau Pickup interrogé par le module ne couvre que la France
        if ('FR' !== $countryIso) {
            return [];
        }

        try {
            // Pas de suivi de redirection (CarrierHttpClient) : l'hôte MyPudo
            // de la liste blanche est le seul appelé
            $response = self::httpClient($httpClient)->send('GET', $endpoint, [
                'timeout' => self::SEARCH_TIMEOUT,
                'query' => [
                    'carrier' => self::DPDFRANCE_PUDO_CARRIER,
                    'key' => self::DPDFRANCE_PUDO_KEY,
                    'address' => '',
                    'zipCode' => $zipcode,
                    'city' => strtoupper((string) \Tools::replaceAccentedChars($city)),
                    'countrycode' => 'FR',
                    'requestID' => '1234',
                    'date_from' => date('d/m/Y'),
                    'max_pudo_number' => (string) self::MAX_RESULTS,
                    'max_distance_search' => '',
                    'weight' => '',
                    'category' => '',
                    'holiday_tolerant' => '',
                ],
            ]);
        } catch (\Throwable $e) {
            self::logSearchError('dpdfrance', get_class($e));
            throw new \Exception('Carrier API error');
        }

        // Redirection ou erreur du service : échec, sans suivre ni relancer
        if ($response['status'] < 200 || $response['status'] >= 300) {
            self::logSearchError('dpdfrance', 'HTTP ' . $response['status']);
            throw new \Exception('Carrier API error');
        }

        return self::parseDpdfranceResponse($response['body']);
    }

    /**
     * Normalisation de la réponse XML de GetPudoList (relais actifs, dans
     * l'ordre de distance renvoyé par le service). Pure, sans appel réseau.
     *
     * @param string $body
     *
     * @return array
     *
     * @throws \Exception si la réponse est invalide ou porte une erreur
     */
    public static function parseDpdfranceResponse($body)
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (false === $xml || isset($xml->ERROR)) {
            self::logSearchError('dpdfrance', false === $xml ? 'invalid XML' : 'ERROR=' . trim((string) $xml->ERROR));
            throw new \Exception('Carrier API error');
        }

        $items = [];
        if (isset($xml->PUDO_ITEMS->PUDO_ITEM)) {
            foreach ($xml->PUDO_ITEMS->PUDO_ITEM as $point) {
                $relayId = trim((string) $point->PUDO_ID);
                if ('' === $relayId || 'false' === strtolower(trim((string) $point['active']))) {
                    continue;
                }
                $items[] = [
                    'relay_id' => $relayId,
                    'name' => trim((string) $point->NAME),
                    'address1' => trim((string) $point->ADDRESS1),
                    'address2' => trim((string) $point->ADDRESS2),
                    'zipcode' => trim((string) $point->ZIPCODE),
                    'city' => trim((string) $point->CITY),
                    'country_iso' => 'FR',
                    // Coordonnées renvoyées avec une virgule décimale
                    'latitude' => (float) str_replace(',', '.', (string) $point->LATITUDE),
                    'longitude' => (float) str_replace(',', '.', (string) $point->LONGITUDE),
                    'distance' => self::parseDistance(isset($point->DISTANCE) ? (string) $point->DISTANCE : null),
                ];

                if (count($items) >= self::MAX_RESULTS) {
                    break;
                }
            }
        }

        return $items;
    }

    /**
     * Credentials Chronopost depuis la configuration du module voisin :
     * numéro de contrat et mot de passe (code Chronotrace), les mêmes que
     * ceux que le module envoie à PointRelaisServiceWS pour sa propre carte.
     *
     * @return array|null ['account' => string, 'password' => string]
     */
    private static function getChronopostCredentials()
    {
        $account = \Configuration::get('CHRONOPOST_GENERAL_ACCOUNT');
        $password = \Configuration::get('CHRONOPOST_GENERAL_PASSWORD');

        if ($account && $password) {
            return ['account' => (string) $account, 'password' => (string) $password];
        }

        return null;
    }

    /**
     * Recherche Chronopost : recherchePointChronopostInter (SOAP,
     * PointRelaisServiceWS), mêmes valeurs que le module (points relais,
     * date du jour, jours fériés tolérés).
     *
     * @return array
     *
     * @throws \Exception
     */
    private static function searchChronopost($zipcode, $city, $countryIso)
    {
        $credentials = self::getChronopostCredentials();

        if (null === $credentials || !extension_loaded('soap')) {
            throw new \Exception('Search is not available for this carrier');
        }

        $params = [
            'accountNumber' => $credentials['account'],
            'password' => $credentials['password'],
            'address' => '',
            'zipCode' => $zipcode,
            'city' => $city,
            'countryCode' => $countryIso,
            'type' => 'P',
            'service' => 'L',
            'weight' => '0',
            'shippingDate' => date('d/m/Y'),
            'maxPointChronopost' => (string) self::MAX_RESULTS,
            'maxDistanceSearch' => '40',
            'holidayTolerant' => '1',
            'language' => 'FR',
        ];

        try {
            // Mêmes bornes que Mondial Relay : connexion, socket et cache WSDL
            $response = self::withSocketTimeout(self::SEARCH_TIMEOUT, function () use ($params) {
                $client = new \SoapClient(self::CHRONOPOST_WSDL, self::soapClientOptions(self::CHRONOPOST_ENDPOINT));

                return $client->recherchePointChronopostInter($params);
            });
        } catch (\Throwable $e) {
            // Ne pas journaliser le message brut : il peut contenir des extraits
            // de requête (credentials). La classe suffit au diagnostic.
            self::logSearchError('chronopost', get_class($e));
            throw new \Exception('Carrier API error');
        }

        return self::parseChronopostResponse(isset($response->return) ? $response->return : null);
    }

    /**
     * Normalisation du résultat pointCHRResult de PointRelaisServiceWS
     * (relais actifs, dans l'ordre de distance). Pure, sans appel réseau.
     *
     * @param object|null $result Objet « return » de la réponse SOAP
     *
     * @return array
     *
     * @throws \Exception si le service signale une erreur
     */
    public static function parseChronopostResponse($result)
    {
        if (!is_object($result) || !isset($result->errorCode) || 0 !== (int) $result->errorCode) {
            self::logSearchError('chronopost', 'errorCode=' . (is_object($result) && isset($result->errorCode) ? $result->errorCode : 'n/a'));
            throw new \Exception('Carrier API error');
        }

        $points = [];
        if (isset($result->listePointRelais)) {
            $points = is_array($result->listePointRelais) ? $result->listePointRelais : [$result->listePointRelais];
        }

        $items = [];
        foreach ($points as $point) {
            if (!is_object($point) || empty($point->identifiant)) {
                continue;
            }
            if (isset($point->actif) && !$point->actif) {
                continue;
            }
            $items[] = [
                'relay_id' => trim((string) $point->identifiant),
                'name' => isset($point->nom) ? trim((string) $point->nom) : '',
                'address1' => isset($point->adresse1) ? trim((string) $point->adresse1) : '',
                'address2' => isset($point->adresse2) ? trim((string) $point->adresse2) : '',
                'zipcode' => isset($point->codePostal) ? trim((string) $point->codePostal) : '',
                'city' => isset($point->localite) ? trim((string) $point->localite) : '',
                'country_iso' => isset($point->codePays) ? strtoupper(trim((string) $point->codePays)) : '',
                'latitude' => isset($point->coordGeolocalisationLatitude) ? (float) str_replace(',', '.', (string) $point->coordGeolocalisationLatitude) : 0,
                'longitude' => isset($point->coordGeolocalisationLongitude) ? (float) str_replace(',', '.', (string) $point->coordGeolocalisationLongitude) : 0,
                'distance' => self::parseDistance(isset($point->distanceEnMetre) ? $point->distanceEnMetre : null),
            ];

            if (count($items) >= self::MAX_RESULTS) {
                break;
            }
        }

        return $items;
    }

    /**
     * Client HTTP des services REST : celui injecté (tests), sinon le client
     * curl du module, indépendant de la version de Guzzle chargée (celle du
     * cœur, Guzzle 5, sous PrestaShop 1.7).
     *
     * @param mixed $httpClient
     *
     * @return CarrierHttpClient
     */
    private static function httpClient($httpClient)
    {
        return $httpClient instanceof CarrierHttpClient ? $httpClient : new CarrierHttpClient();
    }

    /**
     * Exécute $callback avec default_socket_timeout abaissé à $seconds, puis
     * restaure la valeur d'origine, exception comprise.
     *
     * Pour SoapClient, connection_timeout ne borne que l'établissement de la
     * connexion, et le délai du contexte de flux ne vaut que pour le
     * téléchargement du WSDL : la lecture de la réponse SOAP suit
     * default_socket_timeout (60 secondes par défaut). Sans cette borne, un
     * service transporteur lent bloquait un worker PHP-FPM une minute par
     * recherche.
     *
     * @param int $seconds
     * @param callable $callback
     *
     * @return mixed Valeur rendue par $callback
     */
    public static function withSocketTimeout(int $seconds, callable $callback)
    {
        $previous = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', (string) $seconds);

        try {
            return $callback();
        } finally {
            if (false !== $previous) {
                ini_set('default_socket_timeout', $previous);
            }
        }
    }

    /**
     * Options des clients SOAP : point d'appel imposé (jamais l'adresse
     * annoncée par le WSDL), délai de connexion, cache du WSDL en mémoire,
     * erreurs levées en exceptions.
     *
     * @param string $endpoint Point d'appel https du service
     *
     * @return array
     */
    public static function soapClientOptions(string $endpoint): array
    {
        return [
            'location' => $endpoint,
            'connection_timeout' => self::SEARCH_TIMEOUT,
            'stream_context' => stream_context_create(['http' => ['timeout' => self::SEARCH_TIMEOUT]]),
            // Constante d'ext-soap (2), absente sans l'extension
            'cache_wsdl' => defined('WSDL_CACHE_MEMORY') ? WSDL_CACHE_MEMORY : 2,
            'exceptions' => true,
        ];
    }

    /**
     * Distance en mètres telle que renvoyée par les services (Mondial Relay
     * « Distance », Colissimo et Chronopost « distanceEnMetre », DPD
     * « DISTANCE »), null si absente ou non numérique. Affichage seulement.
     *
     * @param mixed $raw
     *
     * @return int|null
     */
    public static function parseDistance($raw)
    {
        if (null === $raw || is_array($raw) || (is_object($raw) && !method_exists($raw, '__toString'))) {
            return null;
        }

        $value = trim(str_replace(',', '.', (string) $raw));

        if ('' === $value || !is_numeric($value) || (float) $value < 0) {
            return null;
        }

        return (int) round((float) $value);
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
