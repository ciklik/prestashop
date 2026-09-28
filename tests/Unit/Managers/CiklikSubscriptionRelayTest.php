<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Tests\Unit\Managers;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\Ciklik\Managers\CiklikSubscriptionRelay;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CiklikSubscriptionRelayTest extends TestCase
{
    /**
     * Seuls les champs attendus par les drivers sont conserves ; coordonnees,
     * jeton et extras inconnus sont ecartes
     */
    public function testBuildPayloadKeepsWhitelistedFieldsOnly()
    {
        $payload = CiklikSubscriptionRelay::buildPayload([
            'relay_id' => '012345',
            'name' => 'Tabac',
            'name2' => 'Le Cèdre',
            'address1' => '12 rue de la Paix',
            'address2' => '',
            'zipcode' => '75002',
            'city' => 'Paris',
            'country_iso' => 'fr',
            'phone' => '',
            'product_code' => 'A2P',
            'network' => 'X00',
            'parcel_shop_working_day' => '{"mon":"9-18"}',
            'latitude' => 48.87,
            'longitude' => 2.33,
            'token' => 'abc.def',
            'raw' => ['x' => 'y'],
        ]);

        $this->assertSame([
            'name' => 'Tabac',
            'name2' => 'Le Cèdre',
            'address1' => '12 rue de la Paix',
            'zipcode' => '75002',
            'city' => 'Paris',
            'country_iso' => 'FR',
            'product_code' => 'A2P',
            'network' => 'X00',
            'parcel_shop_working_day' => '{"mon":"9-18"}',
        ], $payload);
    }

    /**
     * Caracteres de controle remplaces, valeurs non scalaires ecartees,
     * code pays invalide retire
     */
    public function testBuildPayloadSanitizes()
    {
        $payload = CiklikSubscriptionRelay::buildPayload([
            'name' => "Tabac\x00 du\ncoin ",
            'city' => ['Paris'],
            'zipcode' => 75002,
            'country_iso' => 'FRA',
        ]);

        $this->assertSame(['name' => 'Tabac du coin', 'zipcode' => '75002'], $payload);
    }

    /**
     * Un relais dont le payload ne passe pas les regles partagees avec le BO
     * (longueur, validateur PrestaShop) n'est pas enregistrable
     */
    public function testBuildPayloadRejectsInvalidPayload()
    {
        $this->assertNull(CiklikSubscriptionRelay::buildPayload(['relay_id' => '012345', 'zipcode' => '75002!']));
        $this->assertNull(CiklikSubscriptionRelay::buildPayload(['relay_id' => '012345', 'name' => str_repeat('a', 65)]));
        $this->assertNull(CiklikSubscriptionRelay::buildPayload(['relay_id' => '012345', 'city' => 'Paris;']));
        $this->assertSame([], CiklikSubscriptionRelay::buildPayload(['relay_id' => '012345']));
    }

    /**
     * Seuls les relais proposables sont conserves : identifiant valide pour le
     * module, nom renseigne, pays egal a celui de l'adresse quand il est
     * connu, payload conforme ; un relais sans pays (relais connu DPD, GLS)
     * reste, un relais sans nom (relais connu Chronopost) est ecarte
     */
    public function testFilterSelectable()
    {
        $relays = [
            ['relay_id' => '012345', 'name' => 'Paris', 'country_iso' => 'FR'],
            ['relay_id' => '012346', 'name' => 'Bruxelles', 'country_iso' => 'BE'],
            ['relay_id' => '012347', 'name' => 'Sans pays', 'country_iso' => ''],
            ['relay_id' => '012348', 'name' => 'Minuscules', 'country_iso' => 'fr'],
            ['relay_id' => '0123456', 'name' => 'Trop long', 'country_iso' => 'FR'],
            ['relay_id' => '01 345', 'name' => 'Espace', 'country_iso' => 'FR'],
            ['relay_id' => '012349', 'name' => 'Payload', 'zipcode' => '75002!', 'country_iso' => 'FR'],
            ['name' => 'Sans identifiant', 'country_iso' => 'FR'],
            ['relay_id' => '012350', 'name' => '', 'country_iso' => 'FR'],
            ['relay_id' => '012351', 'name' => "  \n ", 'country_iso' => 'FR'],
            ['relay_id' => '012352', 'country_iso' => 'FR'],
            ['relay_id' => '012353', 'name' => ['Tabac'], 'country_iso' => 'FR'],
            'chaine',
        ];

        $selectable = CiklikSubscriptionRelay::filterSelectable($relays, 'mondialrelay', 'FR');

        $this->assertSame(['012345', '012347', '012348'], array_column($selectable, 'relay_id'));

        // Pays de l'adresse en minuscules : meme resultat
        $this->assertCount(3, CiklikSubscriptionRelay::filterSelectable($relays, 'mondialrelay', 'fr'));

        // Adresse belge : seuls le relais belge et celui sans pays passent
        $this->assertSame(['012346', '012347'], array_column(
            CiklikSubscriptionRelay::filterSelectable($relays, 'mondialrelay', 'BE'),
            'relay_id'
        ));

        // Module inconnu : rien
        $this->assertSame([], CiklikSubscriptionRelay::filterSelectable($relays, 'laposte', 'FR'));
    }

    /**
     * Donnees signees : identifiant et payload enregistrable seulement ;
     * coordonnees, distance et champs inconnus restent hors du jeton
     */
    public function testSelectionDataCarriesRelayIdAndPayloadOnly()
    {
        $data = CiklikSubscriptionRelay::selectionData([
            'relay_id' => '012345',
            'name' => 'Tabac',
            'address1' => '12 rue de la Paix',
            'zipcode' => '75002',
            'city' => 'Paris',
            'country_iso' => 'fr',
            'latitude' => 48.87,
            'longitude' => 2.33,
            'distance' => 850,
            'distance_label' => '850 m',
            'raw' => ['x' => 'y'],
        ]);

        $this->assertSame([
            'relay_id' => '012345',
            'name' => 'Tabac',
            'address1' => '12 rue de la Paix',
            'zipcode' => '75002',
            'city' => 'Paris',
            'country_iso' => 'FR',
        ], $data);

        // Relais sans identifiant ou non enregistrable : rien a signer
        $this->assertNull(CiklikSubscriptionRelay::selectionData(['name' => 'Tabac']));
        $this->assertNull(CiklikSubscriptionRelay::selectionData(['relay_id' => ['012345']]));
        $this->assertNull(CiklikSubscriptionRelay::selectionData(['relay_id' => '012345', 'zipcode' => '75002!']));
    }

    /**
     * Choix possibles : recherche disponible pour le pays de livraison, ou
     * relais deja utilises ; DPD ne cherche qu'en France
     */
    public function testHasChoicesDependsOnDeliveryCountry()
    {
        \Configuration::resetMocks();
        \Db::resetMocks();
        // Tables des transporteurs absentes : aucun relais connu
        \Db::setMockGetValue('0');
        \Configuration::updateValue('DPDFRANCE_RELAIS_MYPUDO_URL', 'http://mypudo.pickup-services.com/mypudo/mypudo.asmx?WSDL');

        $this->assertTrue(CiklikSubscriptionRelay::hasChoices(42, 'dpdfrance', 'FR'));
        $this->assertFalse(CiklikSubscriptionRelay::hasChoices(42, 'dpdfrance', 'BE'));
        $this->assertFalse(CiklikSubscriptionRelay::hasChoices(42, 'nkmgls', 'FR'));

        \Configuration::resetMocks();
    }

    /**
     * Relais connus : seuls ceux que la page peut proposer comptent pour
     * afficher le lien (nom, pays, identifiant), sinon le lien menait a une
     * page vide
     */
    public function testHasChoicesCountsOnlySelectableKnownRelays()
    {
        \Configuration::resetMocks();
        \Db::resetMocks();
        // Tables des transporteurs presentes
        \Db::setMockGetValue('1');

        // Chronopost sans recherche : relais connus reduits a leur numero
        \Db::setMockExecuteS([['id_pr' => 'R1', 'last_cart' => '30'], ['id_pr' => 'R2', 'last_cart' => '20']]);
        $this->assertSame([], CiklikSubscriptionRelay::selectableKnownRelays(42, 'chronopost', 'FR'));
        $this->assertFalse(CiklikSubscriptionRelay::hasChoices(42, 'chronopost', 'FR'));

        // GLS sans recherche : relais nommes, sans pays, proposables
        \Db::setMockExecuteS([[
            'parcel_shop_id' => 'G1',
            'name' => 'GLS POINT',
            'address1' => '1 RUE',
            'address2' => '',
            'postcode' => '75001',
            'city' => 'PARIS',
            'parcel_shop_working_day' => '',
        ]]);
        $this->assertSame(['G1'], array_column(CiklikSubscriptionRelay::selectableKnownRelays(42, 'nkmgls', 'FR'), 'relay_id'));
        $this->assertTrue(CiklikSubscriptionRelay::hasChoices(42, 'nkmgls', 'FR'));

        // Mondial Relay sans credentials : relais connu d'un autre pays seulement
        \Db::setMockExecuteS([[
            'selected_relay_num' => '000111',
            'selected_relay_adr1' => 'RELAIS BRUXELLES',
            'selected_relay_adr2' => '',
            'selected_relay_adr3' => '1 RUE',
            'selected_relay_adr4' => '',
            'selected_relay_postcode' => '1000',
            'selected_relay_city' => 'BRUXELLES',
            'selected_relay_country_iso' => 'BE',
        ]]);
        $this->assertFalse(CiklikSubscriptionRelay::hasChoices(42, 'mondialrelay', 'FR'));
        $this->assertTrue(CiklikSubscriptionRelay::hasChoices(42, 'mondialrelay', 'BE'));
    }

    /**
     * Un seul lien de changement : « Changer de point relais » pour un
     * abonnement en relais avec un relais à proposer ; « Changer
     * l'adresse » sinon. Les choix ne sont calculés que si besoin
     */
    public function testSingleChangeLink()
    {
        $calls = 0;
        $choices = function () use (&$calls) {
            ++$calls;

            return true;
        };
        $noChoice = function () use (&$calls) {
            ++$calls;

            return false;
        };

        // Livraison à domicile
        $this->assertSame(CiklikSubscriptionRelay::CHANGE_ADDRESS, CiklikSubscriptionRelay::changeLinkType(false, $choices));
        $this->assertSame(0, $calls, 'choix jamais calculés à domicile');

        // Relais sans rien à proposer
        $this->assertSame(CiklikSubscriptionRelay::CHANGE_ADDRESS, CiklikSubscriptionRelay::changeLinkType(true, $noChoice));
        // Relais avec un choix possible : le lien relais seul
        $this->assertSame(CiklikSubscriptionRelay::CHANGE_RELAY, CiklikSubscriptionRelay::changeLinkType(true, $choices));
        $this->assertSame(2, $calls);
    }

    /**
     * Adresse de l'empreinte telle que la porte SubscriptionData::$address
     */
    private function fingerprintAddress(string $address, string $postcode, string $city): \stdClass
    {
        $fingerprint = new \stdClass();
        $fingerprint->address = $address;
        $fingerprint->address1 = '';
        $fingerprint->postcode = $postcode;
        $fingerprint->city = $city;

        return $fingerprint;
    }

    private function override(string $relayId, array $payload): array
    {
        return ['relay_id' => $relayId, 'payload' => $payload];
    }

    /**
     * Surcharge différente de l'empreinte : le relais s'affiche à sa place
     */
    public function testDifferentOverrideIsDisplayed()
    {
        $relay = CiklikSubscriptionRelay::relayToDisplay(
            $this->override('025804', [
                'name' => 'LOCKER LECLERC DRIVE ST JUST EN',
                'address1' => '143 RUE DE PARIS',
                'zipcode' => '60130',
                'city' => 'SAINT JUST EN CHAUSSEE',
                'country_iso' => 'FR',
            ]),
            $this->fingerprintAddress('23 RUE DE BEAUVAIS', '60130', 'SAINT JUST EN CHAUSSEE')
        );

        $this->assertSame([
            'relay_id' => '025804',
            'name' => 'LOCKER LECLERC DRIVE ST JUST EN',
            'address1' => '143 RUE DE PARIS',
            'address2' => '',
            'zipcode' => '60130',
            'city' => 'SAINT JUST EN CHAUSSEE',
        ], $relay);
    }

    /**
     * Sans surcharge, ou surcharge identique à l'empreinte (casse, accents,
     * ponctuation et espaces près) : rien ne change
     */
    public function testNoOrSameOverrideKeepsFingerprintAddress()
    {
        $fingerprint = $this->fingerprintAddress('23 rue de Beauvais', '60130', 'Saint-Just-en-Chaussée');

        $this->assertNull(CiklikSubscriptionRelay::relayToDisplay(null, $fingerprint));
        $this->assertNull(CiklikSubscriptionRelay::relayToDisplay([], $fingerprint));
        $this->assertNull(CiklikSubscriptionRelay::relayToDisplay($this->override('', ['name' => 'X']), $fingerprint));

        $this->assertNull(CiklikSubscriptionRelay::relayToDisplay(
            $this->override('006047', [
                'name' => 'LES IMAGES DELISABETH',
                'address1' => '23  RUE DE BEAUVAIS ',
                'zipcode' => '60130',
                'city' => 'SAINT JUST EN CHAUSSEE',
            ]),
            $fingerprint
        ));

        // Même rue dans une autre ville : pas la même adresse
        $this->assertNotNull(CiklikSubscriptionRelay::relayToDisplay(
            $this->override('006048', ['name' => 'AUTRE', 'address1' => '23 RUE DE BEAUVAIS', 'zipcode' => '60000', 'city' => 'BEAUVAIS']),
            $fingerprint
        ));
    }

    /**
     * Surcharge sans adresse (numéro seul saisi en back-office) ou empreinte
     * inconnue : le relais s'affiche, par son numéro à défaut de nom
     */
    public function testOverrideWithoutAddressIsDisplayed()
    {
        $relay = CiklikSubscriptionRelay::relayToDisplay(
            $this->override('CH9999', []),
            $this->fingerprintAddress('23 RUE DE BEAUVAIS', '60130', 'SAINT JUST EN CHAUSSEE')
        );

        $this->assertSame('CH9999', $relay['relay_id']);
        $this->assertSame('', $relay['name']);
        $this->assertSame('', $relay['address1']);

        $this->assertNotNull(CiklikSubscriptionRelay::relayToDisplay(
            $this->override('025804', ['name' => 'LOCKER', 'address1' => '143 RUE DE PARIS']),
            null
        ));
    }

    /**
     * La surcharge est lue comme le rebill la lit : table
     * ciklik_delivery_override, par client et module transporteur
     */
    public function testNextDeliveryRelayReadsTheOverrideUsedByTheRebill()
    {
        \Db::resetMocks();
        \Db::setMockGetRow([
            'relay_id' => '025804',
            'payload' => (string) json_encode(['name' => 'LOCKER LECLERC DRIVE ST JUST EN', 'address1' => '143 RUE DE PARIS', 'zipcode' => '60130', 'city' => 'SAINT JUST EN CHAUSSEE']),
        ]);

        $relay = CiklikSubscriptionRelay::nextDeliveryRelay(
            42,
            'mondialrelay',
            $this->fingerprintAddress('23 RUE DE BEAUVAIS', '60130', 'SAINT JUST EN CHAUSSEE')
        );

        $this->assertSame('025804', $relay['relay_id']);
        $this->assertSame('143 RUE DE PARIS', $relay['address1']);

        \Db::setMockGetRow(false);
        $this->assertNull(CiklikSubscriptionRelay::nextDeliveryRelay(42, 'mondialrelay', $this->fingerprintAddress('23 RUE DE BEAUVAIS', '60130', 'SAINT JUST EN CHAUSSEE')));
        \Db::resetMocks();
    }

    /**
     * Libelle « nom - ville », tolerant aux champs absents
     */
    public function testFormatLabel()
    {
        $this->assertSame('Tabac - Paris', CiklikSubscriptionRelay::formatLabel(['name' => 'Tabac', 'city' => 'Paris']));
        $this->assertSame('Tabac', CiklikSubscriptionRelay::formatLabel(['name' => 'Tabac']));
        $this->assertSame('Paris', CiklikSubscriptionRelay::formatLabel(['city' => 'Paris']));
        $this->assertSame('', CiklikSubscriptionRelay::formatLabel([]));
        $this->assertSame('', CiklikSubscriptionRelay::formatLabel(['name' => ['x'], 'city' => null]));
    }

    /**
     * Libelle de distance : metres sous le kilometre, kilometres a une
     * decimale au-dela, vide si inconnue
     */
    public function testFormatDistance()
    {
        $this->assertSame('850 m', CiklikSubscriptionRelay::formatDistance(850));
        $this->assertSame('', CiklikSubscriptionRelay::formatDistance(0));
        $this->assertSame('1 km', CiklikSubscriptionRelay::formatDistance(1000));
        $this->assertSame('1.2 km', CiklikSubscriptionRelay::formatDistance(1249));
        $this->assertSame('12.5 km', CiklikSubscriptionRelay::formatDistance('12480'));
        $this->assertSame('', CiklikSubscriptionRelay::formatDistance(null));
        $this->assertSame('', CiklikSubscriptionRelay::formatDistance('far'));
        $this->assertSame('', CiklikSubscriptionRelay::formatDistance(-3));
    }

    /**
     * Kilomètres mis en forme selon la locale du client ; mètres inchangés ;
     * mise en forme inutilisable : repli sur le point décimal
     */
    public function testFormatDistanceFollowsLocale()
    {
        // Locale française : virgule décimale, comme Locale::formatNumber
        $french = function ($number) {
            return str_replace('.', ',', (string) $number);
        };

        $this->assertSame('1,2 km', CiklikSubscriptionRelay::formatDistance(1249, $french));
        $this->assertSame('12,5 km', CiklikSubscriptionRelay::formatDistance('12480', $french));
        $this->assertSame('1 km', CiklikSubscriptionRelay::formatDistance(1000, $french));
        $this->assertSame('850 m', CiklikSubscriptionRelay::formatDistance(850, $french));

        $broken = function ($number) {
            return '';
        };
        $this->assertSame('1.2 km', CiklikSubscriptionRelay::formatDistance(1249, $broken));
        $this->assertSame('1.2 km', CiklikSubscriptionRelay::formatDistance(1249, 'pas_une_fonction'));
    }
}
