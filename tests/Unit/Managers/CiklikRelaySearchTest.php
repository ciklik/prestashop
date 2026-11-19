<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Managers;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Helpers\CarrierHttpClient;
use PrestaShop\Module\Ciklik\Managers\CiklikRelaySearch;
use PrestaShop\Module\Ciklik\Managers\DeliveryModuleManager;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CiklikRelaySearchTest extends TestCase
{
    protected function setUp(): void
    {
        \Configuration::resetMocks();
    }

    protected function tearDown(): void
    {
        \Configuration::resetMocks();
    }

    /**
     * Client HTTP du module sans réseau : réponses préparées, options curl de
     * chaque requête enregistrées
     */
    private function fakeClient(array $responses): FakeCarrierHttpClient
    {
        return new FakeCarrierHttpClient($responses);
    }

    /**
     * Paramètres d'URL d'une requête enregistrée
     */
    private static function queryOf(array $curlOptions): array
    {
        parse_str((string) parse_url($curlOptions[CURLOPT_URL], PHP_URL_QUERY), $query);

        return $query;
    }

    /**
     * Requête sans suivi de redirection, en https seulement, délai de la recherche
     */
    private function assertSafeRequest(array $curlOptions)
    {
        $this->assertFalse($curlOptions[CURLOPT_FOLLOWLOCATION]);
        $this->assertSame(0, $curlOptions[CURLOPT_MAXREDIRS]);
        $this->assertSame(CURLPROTO_HTTPS, $curlOptions[CURLOPT_PROTOCOLS]);
        $this->assertTrue($curlOptions[CURLOPT_SSL_VERIFYPEER]);
        $this->assertSame(CiklikRelaySearch::SEARCH_TIMEOUT, $curlOptions[CURLOPT_TIMEOUT]);
        $this->assertSame(CiklikRelaySearch::SEARCH_TIMEOUT, $curlOptions[CURLOPT_CONNECTTIMEOUT]);
    }

    /**
     * Recherche DPD : GetPudoList appelé en GET et en https sur l'hôte
     * configuré, sans suivre de redirection, ville en majuscules sans
     * accents ; réponse normalisée
     */
    public function testDpdfranceSearchCallsGetPudoListOverHttps()
    {
        \Configuration::updateValue('DPDFRANCE_RELAIS_MYPUDO_URL', 'http://mypudo.pickup-services.com/mypudo/mypudo.asmx?WSDL');
        $client = $this->fakeClient([['status' => 200, 'body' => $this->dpdResponse()]]);

        $items = CiklikRelaySearch::searchRelays(
            'dpdfrance',
            ['zipcode' => '75011', 'city' => 'Paris Élysée', 'country_iso' => 'fr'],
            $client
        );

        $this->assertSame(['P32500', 'P10351'], array_column($items, 'relay_id'));

        $this->assertCount(1, $client->requests);
        $request = $client->requests[0];
        $this->assertTrue($request[CURLOPT_HTTPGET]);
        $this->assertSame('https', parse_url($request[CURLOPT_URL], PHP_URL_SCHEME));
        $this->assertSame('mypudo.pickup-services.com', parse_url($request[CURLOPT_URL], PHP_URL_HOST));
        $this->assertSame('/mypudo/mypudo.asmx/GetPudoList', parse_url($request[CURLOPT_URL], PHP_URL_PATH));

        $query = self::queryOf($request);
        $this->assertSame(CiklikRelaySearch::DPDFRANCE_PUDO_CARRIER, $query['carrier']);
        $this->assertSame('75011', $query['zipCode']);
        $this->assertSame('PARIS ELYSEE', $query['city']);
        $this->assertSame('FR', $query['countrycode']);
        $this->assertSame('', $query['address']);
        $this->assertSame((string) CiklikRelaySearch::MAX_RESULTS, $query['max_pudo_number']);

        $this->assertSafeRequest($request);
    }

    /**
     * Redirection ou erreur du service DPD : échec générique, la
     * redirection n'est jamais suivie
     */
    public function testDpdfranceSearchFailsOnRedirectOrServerError()
    {
        \Configuration::updateValue('DPDFRANCE_RELAIS_MYPUDO_URL', 'http://mypudo.pickup-services.com/mypudo/mypudo.asmx?WSDL');

        foreach ([
            'redirection' => ['status' => 302, 'body' => ''],
            'erreur serveur' => ['status' => 500, 'body' => 'Server Error'],
            'page illisible' => ['status' => 200, 'body' => '<html>Maintenance'],
            'réseau' => new \RuntimeException('HTTP request failed (curl error 28)'),
        ] as $case => $response) {
            $client = $this->fakeClient([$response, ['status' => 200, 'body' => $this->dpdResponse()]]);
            $message = null;

            try {
                CiklikRelaySearch::searchRelays('dpdfrance', ['zipcode' => '75011', 'city' => 'Paris', 'country_iso' => 'FR'], $client);
            } catch (\Exception $e) {
                $message = $e->getMessage();
            }

            $this->assertSame('Carrier API error', $message, $case);
            $this->assertCount(1, $client->requests, $case . ' : une seule requête, aucune redirection suivie');
        }
    }

    /**
     * Erreur fatale pendant l'appel (ce que produisait Guzzle 5 sous
     * PrestaShop 1.7) : journalisée par sa classe, rendue en échec générique
     * que le contrôleur sait afficher, jamais une erreur 500
     */
    public function testFatalErrorDuringCallBecomesGenericFailure()
    {
        \PrestaShopLogger::resetLogs();
        \Configuration::updateValue('DPDFRANCE_RELAIS_MYPUDO_URL', 'http://mypudo.pickup-services.com/mypudo/mypudo.asmx?WSDL');
        $client = $this->fakeClient([new \Error('Call to undefined method GuzzleHttp\\Client::request()')]);

        $caught = null;
        try {
            CiklikRelaySearch::searchRelays('dpdfrance', ['zipcode' => '75011', 'city' => 'Paris', 'country_iso' => 'FR'], $client);
        } catch (\Throwable $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(\Exception::class, $caught);
        $this->assertSame('Carrier API error', $caught->getMessage());
        $this->assertSame('CiklikRelaySearch[dpdfrance] - Error', end(\PrestaShopLogger::$logs)['message']);
    }

    /**
     * La recherche DPD n'est offerte que si le module voisin a configure
     * l'URL du service MyPudo, et seulement pour un point .asmx
     */
    public function testDpdfranceSearchDependsOnConfiguredMypudoUrl()
    {
        $this->assertFalse(CiklikRelaySearch::supportsSearch('dpdfrance'));

        \Configuration::updateValue('DPDFRANCE_RELAIS_MYPUDO_URL', 'http://mypudo.pickup-services.com/mypudo/mypudo.asmx?WSDL');
        $this->assertTrue(CiklikRelaySearch::supportsSearch('dpdfrance'));

        \Configuration::updateValue('DPDFRANCE_RELAIS_MYPUDO_URL', 'https://example.com/anything?WSDL');
        $this->assertFalse(CiklikRelaySearch::supportsSearch('dpdfrance'));
    }

    /**
     * Une URL MyPudo portant des identifiants (user:pass@) ou un hote qui
     * n'est pas un nom d'hote n'est jamais appelee
     */
    public function testDpdfranceSearchRejectsCredentialsOrOddHostInConfiguredUrl()
    {
        foreach ([
            'http://user:pass@mypudo.pickup-services.com/mypudo/mypudo.asmx?WSDL',
            'http://user@mypudo.pickup-services.com/mypudo/mypudo.asmx?WSDL',
            'http://mypudo.pickup-services.com%2F@evil.example/mypudo/mypudo.asmx',
            'http://-mypudo.example/mypudo/mypudo.asmx',
            'http://mypudo.example:abc/mypudo/mypudo.asmx',
        ] as $url) {
            \Configuration::updateValue('DPDFRANCE_RELAIS_MYPUDO_URL', $url);
            $this->assertFalse(CiklikRelaySearch::supportsSearch('dpdfrance'), $url);
        }

        \Configuration::updateValue('DPDFRANCE_RELAIS_MYPUDO_URL', 'http://mypudo.pickup-services.com:8443/mypudo/mypudo.asmx?WSDL');
        $this->assertTrue(CiklikRelaySearch::supportsSearch('dpdfrance'));
    }

    /**
     * Liste blanche : seul l'hôte MyPudo du module DPD France est appelé,
     * quelle que soit l'URL posée dans la configuration du module voisin
     */
    public function testDpdfranceSearchOnlyCallsTheMypudoHost()
    {
        foreach ([
            'http://evil.example/mypudo/mypudo.asmx?WSDL',
            'http://mypudo.pickup-services.com.evil.example/mypudo/mypudo.asmx?WSDL',
            'http://evilmypudo.pickup-services.com/mypudo/mypudo.asmx?WSDL',
            'http://127.0.0.1/mypudo/mypudo.asmx?WSDL',
            'http://169.254.169.254/latest/mypudo.asmx',
            'http://localhost/mypudo/mypudo.asmx',
        ] as $url) {
            \Configuration::updateValue('DPDFRANCE_RELAIS_MYPUDO_URL', $url);
            $this->assertFalse(CiklikRelaySearch::supportsSearch('dpdfrance'), $url);
        }

        foreach ([
            'http://mypudo.pickup-services.com/mypudo/mypudo.asmx?WSDL',
            'https://MYPUDO.Pickup-Services.com/mypudo/mypudo.asmx?wsdl',
        ] as $url) {
            \Configuration::updateValue('DPDFRANCE_RELAIS_MYPUDO_URL', $url);
            $this->assertTrue(CiklikRelaySearch::supportsSearch('dpdfrance'), $url);
        }

        // Aucun appel réseau vers un hôte refusé
        \Configuration::updateValue('DPDFRANCE_RELAIS_MYPUDO_URL', 'http://evil.example/mypudo/mypudo.asmx?WSDL');
        $client = $this->fakeClient([['status' => 200, 'body' => $this->dpdResponse()]]);
        $message = null;
        try {
            CiklikRelaySearch::searchRelays('dpdfrance', ['zipcode' => '75011', 'city' => 'Paris', 'country_iso' => 'FR'], $client);
        } catch (\Exception $e) {
            $message = $e->getMessage();
        }
        $this->assertSame('Search is not available for this carrier', $message);
        $this->assertSame([], $client->requests);
    }

    /**
     * DPD : recherche proposée pour une adresse française seulement ; pays
     * inconnu (back-office) : selon la configuration seule
     */
    public function testDpdfranceSearchIsFranceOnly()
    {
        \Configuration::updateValue('DPDFRANCE_RELAIS_MYPUDO_URL', 'http://mypudo.pickup-services.com/mypudo/mypudo.asmx?WSDL');

        $this->assertTrue(CiklikRelaySearch::supportsSearch('dpdfrance', 'FR'));
        $this->assertTrue(CiklikRelaySearch::supportsSearch('dpdfrance', 'fr'));
        $this->assertTrue(CiklikRelaySearch::supportsSearch('dpdfrance'));
        $this->assertTrue(CiklikRelaySearch::supportsSearch('dpdfrance', ''));

        foreach (['BE', 'LU', 'es', 'DE'] as $country) {
            $this->assertFalse(CiklikRelaySearch::supportsSearch('dpdfrance', $country), $country);
        }

        // Les autres transporteurs ne dépendent pas du pays
        \Configuration::updateValue('COLISSIMO_ACCOUNT_LOGIN', '123456');
        \Configuration::updateValue('COLISSIMO_ACCOUNT_PASSWORD', 'secret');
        $this->assertTrue(CiklikRelaySearch::supportsSearch('colissimo', 'BE'));
    }

    /**
     * GLS n'a pas de recherche serveur : repli sur les relais connus du client
     */
    public function testCarriersWithoutServerSideSearch()
    {
        \Configuration::updateValue('GLS_GLSRELAIS_ID', '12');

        $this->assertFalse(CiklikRelaySearch::supportsSearch('nkmgls'));
        $this->assertFalse(CiklikRelaySearch::supportsSearch('unknown'));
    }

    /**
     * Credentials Chronopost résolus par la classe (méthode privée : jamais
     * exposés hors de la recherche)
     */
    private static function chronopostCredentials($carrierReference = null)
    {
        $method = new \ReflectionMethod(CiklikRelaySearch::class, 'getChronopostCredentials');
        if (PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }

        return $method->invoke(null, $carrierReference);
    }

    /**
     * Configuration du module Chronopost multi-contrats : deux contrats,
     * l'offre Chrono Relais (transporteur 7) sur le second
     */
    private function configureChronopostContracts()
    {
        \Configuration::updateValue('CHRONOPOST_GENERAL_ACCOUNTTYPE', '1');
        \Configuration::updateValue('CHRONOPOST_GENERAL_ACCOUNTS', json_encode([
            ['account' => '11111111', 'subaccount' => '', 'password' => '111111', 'accountname' => 'Contrat domicile'],
            ['account' => '22222222', 'subaccount' => '001', 'password' => '222222', 'accountname' => 'Contrat relais'],
        ]));
        \Configuration::updateValue('CHRONOPOST_CHRONO13_ACCOUNT', '11111111');
        \Configuration::updateValue('CHRONOPOST_CHRONO13_ID', '5');
        \Configuration::updateValue('CHRONOPOST_CHRONORELAIS_ACCOUNT', '22222222');
        \Configuration::updateValue('CHRONOPOST_CHRONORELAIS_ID', '7');
    }

    /**
     * Recherche Chronopost éteinte par défaut, même avec un contrat
     * configuré : la page s'en tient aux relais connus
     */
    public function testChronopostSearchIsDisabledByDefault()
    {
        $this->configureChronopostContracts();

        $this->assertFalse(CiklikRelaySearch::isChronopostSearchEnabled());
        $this->assertFalse(CiklikRelaySearch::supportsSearch('chronopost'));
        $this->assertFalse(CiklikRelaySearch::supportsSearch('chronopost', 'FR'));

        $message = null;
        try {
            CiklikRelaySearch::searchRelays('chronopost', ['zipcode' => '75001', 'city' => 'Paris', 'country_iso' => 'FR']);
        } catch (\Exception $e) {
            $message = $e->getMessage();
        }
        $this->assertSame('Search is not available for this carrier', $message);
    }

    /**
     * Réglage allumé : recherche proposée quand un contrat est trouvé,
     * jamais sans contrat
     */
    public function testChronopostSearchEnabledBySetting()
    {
        if (!extension_loaded('soap')) {
            $this->markTestSkipped('ext-soap requise');
        }

        \Configuration::updateValue(\Ciklik::CONFIG_CHRONOPOST_SEARCH_ENABLED, '1');
        $this->assertTrue(CiklikRelaySearch::isChronopostSearchEnabled());
        $this->assertFalse(CiklikRelaySearch::supportsSearch('chronopost'));

        $this->configureChronopostContracts();
        $this->assertTrue(CiklikRelaySearch::supportsSearch('chronopost'));
        $this->assertTrue(CiklikRelaySearch::supportsSearch('chronopost', 'FR'));

        \Configuration::updateValue(\Ciklik::CONFIG_CHRONOPOST_SEARCH_ENABLED, '0');
        $this->assertFalse(CiklikRelaySearch::supportsSearch('chronopost'));
    }

    /**
     * Ancien module : CHRONOPOST_GENERAL_ACCOUNT et CHRONOPOST_GENERAL_PASSWORD
     */
    public function testChronopostLegacyCredentials()
    {
        \Configuration::updateValue('CHRONOPOST_GENERAL_ACCOUNT', '19869502');
        \Configuration::updateValue('CHRONOPOST_GENERAL_PASSWORD', '255582');

        $this->assertSame(
            ['account' => '19869502', 'password' => '255582', 'subaccount' => ''],
            self::chronopostCredentials()
        );

        \Configuration::updateValue('CHRONOPOST_GENERAL_PASSWORD', '');
        $this->assertNull(self::chronopostCredentials());
    }

    /**
     * Module multi-contrats : contrat de l'offre relais du transporteur,
     * sous-compte compris, même quand l'ancien format est encore présent
     */
    public function testChronopostRelayOfferContract()
    {
        $this->configureChronopostContracts();
        \Configuration::updateValue('CHRONOPOST_GENERAL_ACCOUNT', '19869502');
        \Configuration::updateValue('CHRONOPOST_GENERAL_PASSWORD', '255582');

        $expected = ['account' => '22222222', 'password' => '222222', 'subaccount' => '001'];
        $this->assertSame($expected, self::chronopostCredentials(7));
        // Transporteur inconnu : contrat d'une offre relais, pas le premier de la liste
        $this->assertSame($expected, self::chronopostCredentials());
        $this->assertSame($expected, self::chronopostCredentials(999));
    }

    /**
     * Le contrat de l'offre du transporteur passe avant celui des autres
     * offres relais
     */
    public function testChronopostCarrierOfferContractComesFirst()
    {
        $this->configureChronopostContracts();
        \Configuration::updateValue('CHRONOPOST_TOSHOPDIRECT_ACCOUNT', '11111111');
        \Configuration::updateValue('CHRONOPOST_TOSHOPDIRECT_ID', '9');

        $this->assertSame('11111111', self::chronopostCredentials(9)['account']);
        $this->assertSame('22222222', self::chronopostCredentials(7)['account']);
        $this->assertSame('22222222', self::chronopostCredentials()['account']);
    }

    /**
     * Contrat de l'offre relais absent de la liste (ou aucune offre relais
     * configurée) : premier contrat, celui de la carte du module Chronopost
     */
    public function testChronopostRelayContractMissingFromListFallsBackToFirstContract()
    {
        $this->configureChronopostContracts();
        \Configuration::updateValue('CHRONOPOST_CHRONORELAIS_ACCOUNT', '99999999');

        $first = ['account' => '11111111', 'password' => '111111', 'subaccount' => ''];
        $this->assertSame($first, self::chronopostCredentials(7));

        \Configuration::updateValue('CHRONOPOST_CHRONORELAIS_ACCOUNT', '');
        $this->assertSame($first, self::chronopostCredentials(7));
    }

    /**
     * Entrées incomplètes ou mal typées ignorées ; numéro de contrat en
     * entier accepté
     */
    public function testChronopostIncompleteContractsAreIgnored()
    {
        \Configuration::updateValue('CHRONOPOST_GENERAL_ACCOUNTS', json_encode([
            ['account' => '22222222', 'password' => ''],
            ['account' => '', 'password' => '111111'],
            'texte',
            ['account' => ['22222222'], 'password' => '222222'],
            ['account' => true, 'password' => true],
            ['account' => 33333333, 'password' => '333333'],
        ]));
        \Configuration::updateValue('CHRONOPOST_CHRONORELAIS_ACCOUNT', '22222222');

        $this->assertSame(
            ['account' => '33333333', 'password' => '333333', 'subaccount' => ''],
            self::chronopostCredentials(7)
        );
    }

    /**
     * Liste présente mais sans contrat complet : aucun contrat, les
     * anciennes clés (laissées par la mise à jour du module Chronopost, qu'il
     * ne relit plus) ne sont pas utilisées
     */
    public function testChronopostLegacyKeysIgnoredOnceContractListExists()
    {
        \Configuration::updateValue('CHRONOPOST_GENERAL_ACCOUNT', '19869502');
        \Configuration::updateValue('CHRONOPOST_GENERAL_PASSWORD', '255582');

        foreach (['[]', json_encode([['account' => '22222222', 'password' => '']])] as $raw) {
            \Configuration::updateValue('CHRONOPOST_GENERAL_ACCOUNTS', $raw);
            $this->assertNull(self::chronopostCredentials(7), $raw);
        }

        // Liste vide ou absente : ancien module, anciennes clés
        \Configuration::updateValue('CHRONOPOST_GENERAL_ACCOUNTS', '');
        $this->assertSame('19869502', self::chronopostCredentials(7)['account']);
    }

    /**
     * JSON invalide : aucun contrat, sans erreur ni journal (rien du
     * contenu de la configuration ne doit être écrit), même avec les
     * anciennes clés encore présentes
     */
    public function testChronopostInvalidJsonGivesNoCredentials()
    {
        \PrestaShopLogger::resetLogs();
        \Configuration::updateValue('CHRONOPOST_GENERAL_ACCOUNT', '19869502');
        \Configuration::updateValue('CHRONOPOST_GENERAL_PASSWORD', '255582');
        \Configuration::updateValue('CHRONOPOST_CHRONORELAIS_ACCOUNT', '22222222');

        foreach (['[{"account":"22222222","password":"222222"', 'null', '"texte"', '42', '{}'] as $raw) {
            \Configuration::updateValue('CHRONOPOST_GENERAL_ACCOUNTS', $raw);
            $this->assertNull(self::chronopostCredentials(7), $raw);
        }
        $this->assertSame([], \PrestaShopLogger::$logs);
    }

    /**
     * Compte « Petits pros » : le module Chronopost remplace les contrats
     * configurés par le sien, la recherche n'est pas proposée (nouveau
     * comme ancien format)
     */
    public function testChronopostSmallBusinessAccountGivesNoCredentials()
    {
        $this->configureChronopostContracts();
        \Configuration::updateValue('CHRONOPOST_GENERAL_ACCOUNTTYPE', '3');
        $this->assertNull(self::chronopostCredentials(7));

        \Configuration::resetMocks();
        \Configuration::updateValue('CHRONOPOST_GENERAL_ACCOUNTTYPE', '3');
        \Configuration::updateValue('CHRONOPOST_GENERAL_ACCOUNT', '19869502');
        \Configuration::updateValue('CHRONOPOST_GENERAL_PASSWORD', '255582');
        $this->assertNull(self::chronopostCredentials(7));
    }

    /**
     * Réglage allumé sans contrat : recherche refusée, sans appel
     */
    public function testChronopostSearchWithoutContractIsRefused()
    {
        \Configuration::updateValue(\Ciklik::CONFIG_CHRONOPOST_SEARCH_ENABLED, '1');

        $message = null;
        try {
            CiklikRelaySearch::searchRelays('chronopost', ['zipcode' => '75001', 'city' => 'Paris', 'country_iso' => 'FR'], null, 7);
        } catch (\Exception $e) {
            $message = $e->getMessage();
        }
        $this->assertSame('Search is not available for this carrier', $message);
    }

    /**
     * Offre relais du transporteur reconnue par son id_reference
     */
    public function testChronopostRelayOfferOfCarrier()
    {
        $this->configureChronopostContracts();
        \Configuration::updateValue('CHRONOPOST_TOSHOPDIRECT_ID', '9');

        $this->assertSame('CHRONORELAIS', CiklikRelaySearch::getChronopostRelayOffer(7));
        $this->assertSame('TOSHOPDIRECT', CiklikRelaySearch::getChronopostRelayOffer(9));
        // Offre domicile, transporteur inconnu ou absent : aucune offre relais
        $this->assertNull(CiklikRelaySearch::getChronopostRelayOffer(5));
        $this->assertNull(CiklikRelaySearch::getChronopostRelayOffer(999));
        $this->assertNull(CiklikRelaySearch::getChronopostRelayOffer(null));
        $this->assertNull(CiklikRelaySearch::getChronopostRelayOffer(0));
    }

    /**
     * Transporteurs relais Chronopost : les six offres relais du module
     */
    public function testChronoRelaisIdsCoverTheSixRelayOffers()
    {
        $ids = [];
        foreach (['CHRONORELAIS' => 7, 'CHRONORELAIS_AMBIENT' => 8, 'RELAISEUROPE' => 10, 'RELAISDOM' => 11, 'TOSHOPDIRECT' => 12, 'TOSHOPDIRECT_EUROPE' => 13] as $offer => $id) {
            \Configuration::updateValue('CHRONOPOST_' . $offer . '_ID', (string) $id);
            $ids[] = $id;
        }

        $relayIds = DeliveryModuleManager::getChronoRelaisIDs();
        sort($relayIds);
        $this->assertSame($ids, $relayIds);
    }

    /**
     * Requête construite comme celle de la carte du module Chronopost :
     * méthode France ou internationale, code produit de l'offre, commerces
     * seulement pour ToShop, mode de dépôt du module sinon
     */
    public function testChronopostSearchRequest()
    {
        $credentials = ['account' => '22222222', 'password' => '222222', 'subaccount' => ''];

        $request = CiklikRelaySearch::chronopostSearchRequest($credentials, '75001', 'Paris', 'FR', 'CHRONORELAIS', '19/11/2026');
        $this->assertSame('recherchePointChronopost', $request['method']);
        $this->assertSame('22222222', $request['params']['accountNumber']);
        $this->assertSame('222222', $request['params']['password']);
        $this->assertSame('FR', $request['params']['countryCode']);
        $this->assertSame('86', $request['params']['productCode']);
        $this->assertSame('P', $request['params']['type']);
        $this->assertSame('19/11/2026', $request['params']['shippingDate']);
        $this->assertArrayNotHasKey('language', $request['params']);

        $request = CiklikRelaySearch::chronopostSearchRequest($credentials, '98000', 'Monaco', 'MC', 'CHRONORELAIS', '19/11/2026');
        $this->assertSame('recherchePointChronopost', $request['method']);
        $this->assertSame('FR', $request['params']['countryCode']);

        $request = CiklikRelaySearch::chronopostSearchRequest($credentials, '1000', 'Bruxelles', 'BE', 'RELAISEUROPE', '19/11/2026');
        $this->assertSame('recherchePointChronopostInter', $request['method']);
        $this->assertSame('BE', $request['params']['countryCode']);
        $this->assertSame('49', $request['params']['productCode']);

        $request = CiklikRelaySearch::chronopostSearchRequest($credentials, '75001', 'Paris', 'FR', 'TOSHOPDIRECT', '19/11/2026');
        $this->assertSame('C', $request['params']['type']);
        $this->assertSame('5X', $request['params']['productCode']);

        // Offre inconnue : pas de code produit ; mode de dépôt du module
        \Configuration::updateValue('CHRONOPOST_MAP_DROPMODE', 'T');
        $request = CiklikRelaySearch::chronopostSearchRequest($credentials, '75001', 'Paris', 'FR', null, '19/11/2026');
        $this->assertSame('', $request['params']['productCode']);
        $this->assertSame('T', $request['params']['type']);

        \Configuration::updateValue('CHRONOPOST_MAP_DROPMODE', 'P;X');
        $request = CiklikRelaySearch::chronopostSearchRequest($credentials, '75001', 'Paris', 'FR', null, '19/11/2026');
        $this->assertSame('P', $request['params']['type']);
    }

    /**
     * pointCHRResult : relais actifs normalises au contrat commun, relais
     * inactifs et entrees sans identifiant ecartes, resultat unique accepte
     */
    public function testParseChronopostResponseNormalizesActivePoints()
    {
        $result = $this->chronopostResult([
            $this->chronopostPoint('12345A', 'TABAC DU CENTRE', '3 RUE DE LA GARE', '75010', 'PARIS', true),
            $this->chronopostPoint('99999Z', 'FERME', '1 RUE CLOSE', '75001', 'PARIS', false),
            $this->chronopostPoint('', 'SANS ID', '2 RUE VIDE', '75002', 'PARIS', true),
            $this->chronopostPoint('67890B', 'PRESSING', '8 AVENUE DU NORD', '75018', 'PARIS', true),
        ]);

        $items = CiklikRelaySearch::parseChronopostResponse($result);

        $this->assertCount(2, $items);
        $this->assertSame('12345A', $items[0]['relay_id']);
        $this->assertSame('TABAC DU CENTRE', $items[0]['name']);
        $this->assertSame('3 RUE DE LA GARE', $items[0]['address1']);
        $this->assertSame('75010', $items[0]['zipcode']);
        $this->assertSame('PARIS', $items[0]['city']);
        $this->assertSame('FR', $items[0]['country_iso']);
        $this->assertEqualsWithDelta(48.876, $items[0]['latitude'], 0.0001);
        $this->assertSame(500, $items[0]['distance']);
        $this->assertSame('67890B', $items[1]['relay_id']);

        $single = $this->chronopostResult($this->chronopostPoint('12345A', 'SEUL', '1 RUE', '75001', 'PARIS', true));
        $this->assertCount(1, CiklikRelaySearch::parseChronopostResponse($single));
    }

    public function testParseChronopostResponseRejectsServiceError()
    {
        $result = new \stdClass();
        $result->errorCode = 1500;
        $result->errorMessage = 'invalid account or password';

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Carrier API error');

        CiklikRelaySearch::parseChronopostResponse($result);
    }

    public function testParseChronopostResponseRejectsMissingResult()
    {
        $this->expectException(\Exception::class);

        CiklikRelaySearch::parseChronopostResponse(null);
    }

    private function chronopostResult($points): \stdClass
    {
        $result = new \stdClass();
        $result->errorCode = 0;
        $result->errorMessage = null;
        $result->qualiteReponse = 1;
        $result->listePointRelais = $points;

        return $result;
    }

    private function chronopostPoint(string $id, string $name, string $address, string $zip, string $city, bool $active): \stdClass
    {
        $point = new \stdClass();
        $point->identifiant = $id;
        $point->nom = $name;
        $point->adresse1 = $address;
        $point->adresse2 = '';
        $point->codePostal = $zip;
        $point->localite = $city;
        $point->codePays = 'fr';
        $point->coordGeolocalisationLatitude = '48.876';
        $point->coordGeolocalisationLongitude = '2.358';
        $point->actif = $active;
        $point->distanceEnMetre = 500;

        return $point;
    }

    /**
     * Services SOAP appelés en https, quelle que soit l'adresse annoncée par
     * le WSDL (http:// chez Mondial Relay) : le point d'appel est imposé
     */
    public function testSoapServicesAreCalledOverHttps()
    {
        foreach ([
            CiklikRelaySearch::MONDIALRELAY_WSDL,
            CiklikRelaySearch::MONDIALRELAY_ENDPOINT,
            CiklikRelaySearch::CHRONOPOST_WSDL,
            CiklikRelaySearch::CHRONOPOST_ENDPOINT,
        ] as $url) {
            $this->assertStringStartsWith('https://', $url);
        }

        $options = CiklikRelaySearch::soapClientOptions(CiklikRelaySearch::MONDIALRELAY_ENDPOINT);
        $this->assertSame('https://api.mondialrelay.com/Web_Services.asmx', $options['location']);
        $this->assertSame(CiklikRelaySearch::SEARCH_TIMEOUT, $options['connection_timeout']);
        $this->assertTrue($options['exceptions']);

        $this->assertSame(
            'https://ws.chronopost.fr/recherchebt-ws-cxf/PointRelaisServiceWS',
            CiklikRelaySearch::soapClientOptions(CiklikRelaySearch::CHRONOPOST_ENDPOINT)['location']
        );
    }

    /**
     * Colissimo : identifiants envoyés au seul point d'appel https, sans
     * suivre de redirection (une redirection vaut échec générique)
     */
    public function testColissimoSearchNeverFollowsRedirects()
    {
        \Configuration::updateValue('COLISSIMO_ACCOUNT_LOGIN', '123456');
        \Configuration::updateValue('COLISSIMO_ACCOUNT_PASSWORD', 'secret');

        $client = $this->fakeClient([['status' => 200, 'body' => $this->colissimoResponse()]]);

        $items = CiklikRelaySearch::searchRelays('colissimo', ['zipcode' => '75001', 'city' => 'Paris', 'country_iso' => 'FR'], $client);

        $this->assertSame('987654', $items[0]['relay_id']);
        $this->assertSame(420, $items[0]['distance']);

        $request = $client->requests[0];
        $this->assertTrue($request[CURLOPT_POST]);
        $this->assertSame(CiklikRelaySearch::COLISSIMO_ENDPOINT, $request[CURLOPT_URL]);
        $this->assertSame('/pointretrait-ws-cxf/rest/v2/pointretrait/findRDVPointRetraitAcheminement', parse_url($request[CURLOPT_URL], PHP_URL_PATH));
        $this->assertSame('ws.colissimo.fr', parse_url($request[CURLOPT_URL], PHP_URL_HOST));
        $this->assertContains('Content-Type: application/json', $request[CURLOPT_HTTPHEADER]);
        $sent = json_decode($request[CURLOPT_POSTFIELDS], true);
        $this->assertSame('123456', $sent['accountNumber']);
        $this->assertSame('75001', $sent['zipCode']);
        $this->assertSame('FR', $sent['countryCode']);
        $this->assertSafeRequest($request);

        $client = $this->fakeClient([
            ['status' => 307, 'body' => ''],
            ['status' => 200, 'body' => '{}'],
        ]);
        $message = null;
        try {
            CiklikRelaySearch::searchRelays('colissimo', ['zipcode' => '75001', 'city' => 'Paris', 'country_iso' => 'FR'], $client);
        } catch (\Exception $e) {
            $message = $e->getMessage();
        }

        $this->assertSame('Carrier API error', $message);
        $this->assertCount(1, $client->requests, 'identifiants jamais renvoyés vers la cible de la redirection');
    }

    /**
     * Délai de socket borné pendant l'appel SOAP, restauré après, y compris
     * quand l'appel échoue
     */
    public function testSocketTimeoutIsBoundedThenRestored()
    {
        $original = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', '60');

        $seen = CiklikRelaySearch::withSocketTimeout(8, function () {
            return ini_get('default_socket_timeout');
        });
        $this->assertSame('8', $seen);
        $this->assertSame('60', ini_get('default_socket_timeout'));

        $caught = null;
        try {
            CiklikRelaySearch::withSocketTimeout(8, function () {
                throw new \RuntimeException('service lent');
            });
        } catch (\RuntimeException $e) {
            $caught = $e->getMessage();
        }
        $this->assertSame('service lent', $caught);
        $this->assertSame('60', ini_get('default_socket_timeout'));

        ini_set('default_socket_timeout', (string) $original);
    }

    /**
     * Réponse REST v2 de Colissimo (champs à la racine) : points normalisés au
     * contrat commun ; l'enveloppe « return » reste lue
     */
    public function testParseColissimoResponse()
    {
        $items = CiklikRelaySearch::parseColissimoResponse(200, $this->colissimoResponse(), 'FR');

        $this->assertCount(2, $items);
        $this->assertSame('987654', $items[0]['relay_id']);
        $this->assertSame('BUREAU DE POSTE', $items[0]['name']);
        $this->assertSame('1 PLACE DE LA POSTE', $items[0]['address1']);
        $this->assertSame('75001', $items[0]['zipcode']);
        $this->assertSame('PARIS', $items[0]['city']);
        $this->assertSame('FR', $items[0]['country_iso']);
        $this->assertSame('A2P', $items[0]['product_code']);
        $this->assertSame('R03', $items[0]['network']);
        $this->assertEqualsWithDelta(48.8629, $items[0]['latitude'], 0.0001);
        $this->assertSame(420, $items[0]['distance']);
        $this->assertSame('BE', $items[1]['country_iso'], 'pays du point quand il est donné');

        $wrapped = (string) json_encode(['return' => json_decode($this->colissimoResponse(), true)]);
        $this->assertCount(2, CiklikRelaySearch::parseColissimoResponse(200, $wrapped, 'FR'));
    }

    /**
     * Refus du service : identifiants refusés (400, code 201, réponse réelle
     * du 26/09/2026), faute SOAP de l'ancienne adresse (500), code d'erreur en
     * 200, réponse illisible : échec générique
     *
     * @dataProvider colissimoFailuresProvider
     */
    public function testParseColissimoResponseRejectsFailures(int $status, string $body)
    {
        $message = null;
        try {
            CiklikRelaySearch::parseColissimoResponse($status, $body, 'FR');
        } catch (\Exception $e) {
            $message = $e->getMessage();
        }

        $this->assertSame('Carrier API error', $message);
    }

    public function colissimoFailuresProvider(): array
    {
        return [
            'identifiants refusés' => [400, '{"errorCode":201,"errorMessage":"Identifiant / mot de passe invalide","qualiteReponse":0,"wsRequestId":"","listePointRetraitAcheminement":null,"rdv":false}'],
            'faute SOAP' => [500, '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><soap:Fault><faultcode>soap:Client</faultcode><faultstring>Error reading XMLStreamReader.</faultstring></soap:Fault></soap:Body></soap:Envelope>'],
            'code d\'erreur en 200' => [200, '{"errorCode":203,"errorMessage":"Code postal invalide","listePointRetraitAcheminement":null}'],
            'sans code' => [200, '{"listePointRetraitAcheminement":[]}'],
            'illisible' => [200, 'Service unavailable'],
        ];
    }

    /**
     * Réponse REST v2 de findRDVPointRetraitAcheminement, deux points
     */
    private function colissimoResponse(): string
    {
        return (string) json_encode([
            'errorCode' => 0,
            'errorMessage' => 'Code retour OK',
            'qualiteReponse' => 2,
            'wsRequestId' => '',
            'listePointRetraitAcheminement' => [
                [
                    'identifiant' => '987654',
                    'nom' => 'BUREAU DE POSTE',
                    'adresse1' => '1 PLACE DE LA POSTE',
                    'adresse2' => '',
                    'codePostal' => '75001',
                    'localite' => 'PARIS',
                    'codePays' => 'FR',
                    'typeDePoint' => 'A2P',
                    'reseau' => 'R03',
                    'coordGeolocalisationLatitude' => '48.8629',
                    'coordGeolocalisationLongitude' => '2.3363',
                    'distanceEnMetre' => 420,
                ],
                [
                    'identifiant' => '123456',
                    'nom' => 'POINT BELGE',
                    'adresse1' => '2 RUE DE BRUXELLES',
                    'codePostal' => '7500',
                    'localite' => 'TOURNAI',
                    'codePays' => 'BE',
                    'distanceEnMetre' => 900,
                ],
                ['nom' => 'SANS IDENTIFIANT'],
            ],
            'rdv' => false,
        ]);
    }

    public function testColissimoSearchDependsOnCredentials()
    {
        $this->assertFalse(CiklikRelaySearch::supportsSearch('colissimo'));

        \Configuration::updateValue('COLISSIMO_ACCOUNT_LOGIN', '123456');
        \Configuration::updateValue('COLISSIMO_ACCOUNT_PASSWORD', 'secret');
        $this->assertTrue(CiklikRelaySearch::supportsSearch('colissimo'));
    }

    /**
     * Reponse reelle de GetPudoList : relais actifs normalises au contrat
     * commun, virgule decimale convertie, relais inactifs ecartes
     */
    public function testParseDpdfranceResponseNormalizesActivePudos()
    {
        $items = CiklikRelaySearch::parseDpdfranceResponse($this->dpdResponse());

        $this->assertCount(2, $items);
        $this->assertSame('P32500', $items[0]['relay_id']);
        $this->assertSame('SIMON SERVICES', $items[0]['name']);
        $this->assertSame('20 RUE SAINT SABIN', $items[0]['address1']);
        $this->assertSame('', $items[0]['address2']);
        $this->assertSame('75011', $items[0]['zipcode']);
        $this->assertSame('PARIS', $items[0]['city']);
        $this->assertSame('FR', $items[0]['country_iso']);
        $this->assertEqualsWithDelta(48.8558333333, $items[0]['latitude'], 0.000001);
        $this->assertEqualsWithDelta(2.37166666667, $items[0]['longitude'], 0.000001);
        $this->assertSame(837, $items[0]['distance']);
        $this->assertSame('P10351', $items[1]['relay_id']);
        $this->assertSame(862, $items[1]['distance']);
    }

    /**
     * Distance en metres : entier depuis chaine ou nombre, virgule decimale
     * toleree, null si absente, vide, non numerique ou negative
     */
    public function testParseDistance()
    {
        $this->assertSame(837, CiklikRelaySearch::parseDistance('837'));
        $this->assertSame(500, CiklikRelaySearch::parseDistance(500));
        $this->assertSame(1250, CiklikRelaySearch::parseDistance('1249,6'));
        $this->assertNull(CiklikRelaySearch::parseDistance(null));
        $this->assertNull(CiklikRelaySearch::parseDistance(''));
        $this->assertNull(CiklikRelaySearch::parseDistance('n/a'));
        $this->assertNull(CiklikRelaySearch::parseDistance('-5'));
        $this->assertNull(CiklikRelaySearch::parseDistance(['837']));
    }

    public function testParseDpdfranceResponseReturnsNothingWhenNoPudo()
    {
        $this->assertSame([], CiklikRelaySearch::parseDpdfranceResponse(
            '<?xml version="1.0" encoding="utf-8"?><RESPONSE quality="0"><REQUEST_ID>1234</REQUEST_ID><PUDO_ITEMS /></RESPONSE>'
        ));
    }

    public function testParseDpdfranceResponseRejectsServiceError()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Carrier API error');

        CiklikRelaySearch::parseDpdfranceResponse(
            '<?xml version="1.0" encoding="utf-8"?><RESPONSE><ERROR>Invalid key</ERROR></RESPONSE>'
        );
    }

    public function testParseDpdfranceResponseRejectsInvalidXml()
    {
        $this->expectException(\Exception::class);

        CiklikRelaySearch::parseDpdfranceResponse('<html>Service unavailable');
    }

    /**
     * Extrait d'une reponse reelle du service (23/09/2026), troisieme relais
     * rendu inactif pour le test
     */
    private function dpdResponse(): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>
<RESPONSE quality="2">
  <REQUEST_ID>1234</REQUEST_ID>
  <PUDO_ITEMS>
    <PUDO_ITEM active="true">
      <PUDO_ID>P32500</PUDO_ID>
      <DISTANCE>837</DISTANCE>
      <NAME>SIMON SERVICES</NAME>
      <ADDRESS1>20 RUE SAINT SABIN</ADDRESS1>
      <ADDRESS2>
      </ADDRESS2>
      <ADDRESS3>
      </ADDRESS3>
      <LOCAL_HINT>
      </LOCAL_HINT>
      <ZIPCODE>75011</ZIPCODE>
      <CITY>PARIS</CITY>
      <LONGITUDE>2,37166666667</LONGITUDE>
      <LATITUDE>48,85583333330</LATITUDE>
      <MAP_URL>
      </MAP_URL>
      <AVAILABLE>full</AVAILABLE>
      <OPENING_HOURS_ITEMS>
        <OPENING_HOURS_ITEM>
          <DAY_ID>1</DAY_ID>
          <START_TM>09:00</START_TM>
          <END_TM>12:00</END_TM>
        </OPENING_HOURS_ITEM>
      </OPENING_HOURS_ITEMS>
      <HOLIDAY_ITEMS />
    </PUDO_ITEM>
    <PUDO_ITEM active="true">
      <PUDO_ID>P10351</PUDO_ID>
      <DISTANCE>862</DISTANCE>
      <NAME>LAND OR</NAME>
      <ADDRESS1>5 Rue Chapon</ADDRESS1>
      <ADDRESS2>
      </ADDRESS2>
      <ADDRESS3>
      </ADDRESS3>
      <LOCAL_HINT>Paris</LOCAL_HINT>
      <ZIPCODE>75003</ZIPCODE>
      <CITY>PARIS</CITY>
      <LONGITUDE>2,35583333333</LONGITUDE>
      <LATITUDE>48,86301400000</LATITUDE>
      <MAP_URL>
      </MAP_URL>
      <AVAILABLE>full</AVAILABLE>
      <OPENING_HOURS_ITEMS />
      <HOLIDAY_ITEMS />
    </PUDO_ITEM>
    <PUDO_ITEM active="false">
      <PUDO_ID>P99999</PUDO_ID>
      <NAME>FERME</NAME>
      <ADDRESS1>1 RUE CLOSE</ADDRESS1>
      <ZIPCODE>75001</ZIPCODE>
      <CITY>PARIS</CITY>
      <LONGITUDE>2,3</LONGITUDE>
      <LATITUDE>48,8</LATITUDE>
    </PUDO_ITEM>
  </PUDO_ITEMS>
</RESPONSE>';
    }
}

/**
 * Client HTTP du module sans réseau : rend les réponses préparées (ou lève
 * l'exception préparée) et garde les options curl de chaque requête
 */
class FakeCarrierHttpClient extends CarrierHttpClient
{
    /** @var array Options curl des requêtes, dans l'ordre */
    public $requests = [];

    /** @var array Réponses ['status' => int, 'body' => string] ou exceptions */
    private $responses;

    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    protected function execute(array $curlOptions): array
    {
        $this->requests[] = $curlOptions;
        $next = array_shift($this->responses);

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }
}
