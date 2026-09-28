<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Managers;

use PrestaShop\Module\Ciklik\Data\SubscriptionData;
use PrestaShop\Module\Ciklik\Helpers\RelaySelectionSigner;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Présentation du point relais d'un abonnement, partagée entre le bloc
 * back-office (page commande) et l'espace client (Mes abonnements).
 *
 * Ne porte aucune écriture : la résolution du transporteur des rebills, le
 * relais courant (override ou clonage à venir) et la normalisation du payload
 * enregistré dans {@see CiklikDeliveryOverride}.
 */
class CiklikSubscriptionRelay
{
    /** Lien de changement de Mes abonnements : « Changer l'adresse » */
    const CHANGE_ADDRESS = 'address';

    /** Lien de changement de Mes abonnements : « Changer de point relais » */
    const CHANGE_RELAY = 'relay';

    /** Confirmation d'un relais : relais enregistrable */
    const SELECTION_OK = 'ok';

    /** Confirmation d'un relais : aucun relais choisi */
    const SELECTION_MISSING = 'missing';

    /** Confirmation d'un relais : identifiant invalide pour le transporteur */
    const SELECTION_INVALID_ID = 'invalid_id';

    /** Confirmation d'un relais : jeton absent, altéré, d'un autre contexte ou d'un autre relais */
    const SELECTION_INVALID_TOKEN = 'invalid_token';

    /** Confirmation d'un relais : relais signé que l'espace client ne peut pas proposer */
    const SELECTION_NOT_SELECTABLE = 'not_selectable';

    /** Confirmation d'un relais : jeton authentique mais expiré (page restée ouverte trop longtemps) */
    const SELECTION_EXPIRED = 'expired';

    /**
     * Seul lien de changement proposé pour un abonnement dans Mes abonnements.
     * « Changer de point relais » quand l'abonnement est livré en point
     * relais et qu'un relais peut être proposé ; « Changer l'adresse » dans
     * tous les autres cas (livraison à domicile, rien à proposer), comme avant
     * 1.24.0. Jamais les deux.
     *
     * @param bool $shippedToRelay Abonnement livré en point relais ({@see resolveCarrier()})
     * @param callable $hasChoices Un relais peut-il être proposé ? Appelé seulement si nécessaire (requêtes)
     *
     * @return string self::CHANGE_RELAY ou self::CHANGE_ADDRESS
     */
    public static function changeLinkType(bool $shippedToRelay, callable $hasChoices): string
    {
        return $shippedToRelay && call_user_func($hasChoices)
            ? self::CHANGE_RELAY
            : self::CHANGE_ADDRESS;
    }

    /**
     * Résout le transporteur des prochains rebills et vérifie qu'il s'agit
     * d'une offre point relais d'un module supporté.
     *
     * Source de vérité : id_carrier_reference du fingerprint, pas le transporteur
     * d'une commande passée.
     *
     * @param SubscriptionData|null $subscription
     *
     * @return array|null ['module' => string, 'carrier' => \Carrier] ou null
     */
    public static function resolveCarrier($subscription)
    {
        if (!$subscription
            || !$subscription->external_fingerprint
            || empty($subscription->external_fingerprint->id_carrier_reference)) {
            return null;
        }

        $carrier = \Carrier::getCarrierByReference((int) $subscription->external_fingerprint->id_carrier_reference);

        if (!$carrier || empty($carrier->external_module_name)) {
            return null;
        }

        $module = strtolower((string) $carrier->external_module_name);

        if (!in_array($module, CiklikDeliveryOverride::SUPPORTED_MODULES, true)) {
            return null;
        }

        // Offres domicile des modules relais : pas de relais à changer, un
        // override n'y serait jamais appliqué au rebill
        if (!DeliveryModuleManager::carrierSupportsRelay($module, $carrier)) {
            return null;
        }

        return ['module' => $module, 'carrier' => $carrier];
    }

    /**
     * Relais courant des prochains rebills : l'override s'il existe, sinon
     * celui que le clonage historique utilisera.
     *
     * @param int $idCustomer
     * @param string $module Nom du module transporteur (minuscules)
     * @param int $idAddressDelivery Adresse de livraison des rebills (fingerprint)
     *
     * @return array|null ['source' => 'override'|'auto', 'relay_id' => string, 'label' => string] ou null
     */
    public static function getCurrent(int $idCustomer, string $module, int $idAddressDelivery = 0)
    {
        $override = CiklikDeliveryOverride::get($idCustomer, $module);

        if ($override) {
            return [
                'source' => 'override',
                'relay_id' => $override['relay_id'],
                'label' => self::formatLabel($override['payload']),
            ];
        }

        $peek = DeliveryModuleManager::peekLegacyRelay($idCustomer, $module, $idAddressDelivery);

        return $peek ? array_merge(['source' => 'auto'], $peek) : null;
    }

    /**
     * Point relais que le prochain renouvellement utilisera, à afficher dans
     * Mes abonnements à la place de l'adresse de l'empreinte : la surcharge
     * lue comme la lisent les drivers de DeliveryModuleManager au rebill
     * (CiklikDeliveryOverride::get, par client et module transporteur),
     * quand elle diffère de cette adresse. Lecture en base seulement, aucun
     * appel à l'API.
     *
     * @param int $idCustomer
     * @param string $module Nom du module transporteur (minuscules)
     * @param mixed $fingerprintAddress Adresse de l'empreinte (SubscriptionDeliveryAddressData)
     *
     * @return array|null Voir relayToDisplay()
     */
    public static function nextDeliveryRelay(int $idCustomer, string $module, $fingerprintAddress)
    {
        return self::relayToDisplay(CiklikDeliveryOverride::get($idCustomer, $module), $fingerprintAddress);
    }

    /**
     * Décision pure de l'affichage : null sans surcharge ou quand la surcharge
     * désigne l'adresse de l'empreinte (même rue, code postal et ville, casse,
     * accents et ponctuation ignorés) ; sinon le relais à afficher.
     *
     * @param mixed $override ['relay_id' => string, 'payload' => array] ou null
     * @param mixed $fingerprintAddress Objet portant address, postcode et city, ou null
     *
     * @return array|null ['relay_id', 'name', 'address1', 'address2', 'zipcode', 'city'], valeurs nettoyées
     */
    public static function relayToDisplay($override, $fingerprintAddress)
    {
        if (!is_array($override) || !isset($override['relay_id']) || !is_scalar($override['relay_id'])
            || '' === trim((string) $override['relay_id'])) {
            return null;
        }

        $payload = isset($override['payload']) && is_array($override['payload']) ? $override['payload'] : [];
        $relay = ['relay_id' => CiklikDeliveryOverride::cleanPayloadValue($override['relay_id'])];
        foreach (['name', 'address1', 'address2', 'zipcode', 'city'] as $field) {
            $relay[$field] = isset($payload[$field]) ? CiklikDeliveryOverride::cleanPayloadValue($payload[$field]) : '';
        }

        // Surcharge identique à l'empreinte : l'adresse affichée est déjà celle
        // de la prochaine livraison
        if (is_object($fingerprintAddress) && '' !== $relay['address1']
            && self::sameAddressPart($relay['address1'], isset($fingerprintAddress->address) ? $fingerprintAddress->address : '')
            && self::sameAddressPart($relay['zipcode'], isset($fingerprintAddress->postcode) ? $fingerprintAddress->postcode : '')
            && self::sameAddressPart($relay['city'], isset($fingerprintAddress->city) ? $fingerprintAddress->city : '')) {
            return null;
        }

        return $relay;
    }

    /**
     * Deux éléments d'adresse désignent-ils la même chose, à la casse, aux
     * accents, à la ponctuation et aux espaces près ?
     *
     * @param mixed $left
     * @param mixed $right
     *
     * @return bool
     */
    private static function sameAddressPart($left, $right): bool
    {
        $normalize = function ($value) {
            $value = is_scalar($value) ? (string) \Tools::replaceAccentedChars((string) $value) : '';

            return strtoupper(trim((string) preg_replace('/[^A-Za-z0-9]+/', ' ', $value)));
        };

        return $normalize($left) === $normalize($right);
    }

    /**
     * Le client a-t-il au moins un relais à choisir pour ce transporteur :
     * recherche par adresse disponible pour le pays de livraison, ou relais
     * déjà utilisés que la page pourra proposer ({@see selectableKnownRelays()}) ?
     * Sans choix possible, l'espace client ne propose pas le changement de
     * relais.
     *
     * @param int $idCustomer
     * @param string $module Nom du module transporteur (minuscules)
     * @param string $countryIso Pays de l'adresse de livraison des rebills
     *
     * @return bool
     */
    public static function hasChoices(int $idCustomer, string $module, string $countryIso): bool
    {
        return CiklikRelaySearch::supportsSearch($module, $countryIso)
            || [] !== self::selectableKnownRelays($idCustomer, $module, $countryIso);
    }

    /**
     * Relais déjà utilisés par le client que l'espace client peut proposer :
     * mêmes règles qu'au rendu et à la confirmation ({@see filterSelectable()}).
     * Le test « rien à proposer » porte sur cette liste, pas sur la liste
     * brute, sans quoi un lien menait à une page vide.
     *
     * @param int $idCustomer
     * @param string $module Nom du module transporteur (minuscules)
     * @param string $countryIso Pays de l'adresse de livraison des rebills
     *
     * @return array
     */
    public static function selectableKnownRelays(int $idCustomer, string $module, string $countryIso): array
    {
        return self::filterSelectable(DeliveryModuleManager::getKnownRelays($idCustomer, $module), $module, $countryIso);
    }

    /**
     * Pays de livraison des rebills : celui de l'adresse du fingerprint si
     * elle appartient bien au client, sinon le pays par défaut de la
     * boutique, FR en dernier recours. Jamais saisi par le client.
     *
     * @param SubscriptionData|null $subscription
     * @param int $idCustomer
     *
     * @return string Code ISO alpha-2 en majuscules
     */
    public static function rebillCountryIso($subscription, int $idCustomer): string
    {
        $countryIso = '';
        $idAddress = $subscription && $subscription->external_fingerprint
            ? (int) $subscription->external_fingerprint->id_address_delivery
            : 0;

        if ($idAddress > 0) {
            $address = new \Address($idAddress);
            if (\Validate::isLoadedObject($address) && (int) $address->id_customer === $idCustomer) {
                $countryIso = (string) \Country::getIsoById((int) $address->id_country);
            }
        }

        if (!preg_match('/^[A-Za-z]{2}$/', $countryIso)) {
            $countryIso = (string) \Country::getIsoById((int) \Configuration::get('PS_COUNTRY_DEFAULT'));
        }

        return preg_match('/^[A-Za-z]{2}$/', $countryIso) ? strtoupper($countryIso) : 'FR';
    }

    /**
     * Construit le payload à stocker depuis un relais normalisé (résultat de
     * recherche ou relais connu) : liste blanche des champs de
     * CiklikDeliveryOverride::RELAY_PAYLOAD_RULES, valeurs nettoyées, puis
     * validation par les mêmes règles que le back-office (longueurs,
     * Validate::*). Un relais qui ne passe pas n'est pas enregistrable.
     *
     * @param array $relay
     *
     * @return array|null Payload, ou null s'il ne respecte pas les règles
     */
    public static function buildPayload(array $relay)
    {
        $payload = [];

        foreach (array_keys(CiklikDeliveryOverride::RELAY_PAYLOAD_RULES) as $field) {
            if (!isset($relay[$field])) {
                continue;
            }

            $value = CiklikDeliveryOverride::cleanPayloadValue($relay[$field]);

            if ('' === $value) {
                continue;
            }

            $payload[$field] = $value;
        }

        if (isset($payload['country_iso'])) {
            if (preg_match('/^[a-zA-Z]{2}$/', $payload['country_iso'])) {
                $payload['country_iso'] = strtoupper($payload['country_iso']);
            } else {
                unset($payload['country_iso']);
            }
        }

        return CiklikDeliveryOverride::isValidPayload($payload) ? $payload : null;
    }

    /**
     * Données signées d'un relais proposé au client : son identifiant et le
     * payload enregistrable ({@see buildPayload()}), rien d'autre (ni
     * coordonnées, ni distance, ni champ inconnu). Le jeton porte ainsi
     * exactement ce que la confirmation enregistrera.
     *
     * @param array $relay Relais normalisé, déjà retenu par filterSelectable()
     *
     * @return array|null ['relay_id' => string] + payload, null si le relais n'est pas enregistrable
     */
    public static function selectionData(array $relay)
    {
        if (!isset($relay['relay_id']) || !is_scalar($relay['relay_id'])) {
            return null;
        }

        $payload = self::buildPayload($relay);

        if (null === $payload) {
            return null;
        }

        // relay_id n'est pas un champ de payload : l'union ne l'écrase jamais
        return ['relay_id' => (string) $relay['relay_id']] + $payload;
    }

    /**
     * Ne garde que les relais que l'espace client peut proposer : identifiant
     * valide pour le module, nom renseigné (le client doit pouvoir le
     * reconnaître : les relais connus Chronopost, réduits à leur numéro,
     * restent au back-office), pays du relais égal à celui de l'adresse de
     * livraison quand il est connu (un relais sans pays, relais connu DPD ou
     * GLS, est conservé), payload enregistrable. Appliqué avant la signature
     * au rendu, et de nouveau à la confirmation.
     *
     * @param array $relays Relais normalisés
     * @param string $module Nom du module transporteur (minuscules)
     * @param string $countryIso Pays de l'adresse de livraison des rebills (ISO alpha-2)
     *
     * @return array Liste réindexée
     */
    public static function filterSelectable(array $relays, string $module, string $countryIso): array
    {
        $countryIso = strtoupper($countryIso);
        $selectable = [];

        foreach ($relays as $relay) {
            if (!is_array($relay) || !isset($relay['relay_id']) || !is_scalar($relay['relay_id'])
                || !CiklikDeliveryOverride::isValidRelayId($module, (string) $relay['relay_id'])) {
                continue;
            }

            $name = isset($relay['name']) ? CiklikDeliveryOverride::cleanPayloadValue($relay['name']) : '';
            if ('' === $name) {
                continue;
            }

            $relayCountry = isset($relay['country_iso']) && is_scalar($relay['country_iso'])
                ? strtoupper(trim((string) $relay['country_iso']))
                : '';
            if ('' !== $relayCountry && $relayCountry !== $countryIso) {
                continue;
            }

            if (null === self::buildPayload($relay)) {
                continue;
            }

            $selectable[] = $relay;
        }

        return $selectable;
    }

    /**
     * Décision de la confirmation d'un relais (action saverelay), sans accès
     * à la requête ni à la base : le relais choisi doit être porté par un
     * jeton signé par le serveur pour ce client, ce transporteur et cette
     * boutique, et rester proposable (mêmes règles qu'au rendu). Le payload
     * enregistré vient uniquement de ce jeton, jamais du formulaire.
     *
     * @param mixed $relayChoice Identifiant posté (relay_choice)
     * @param mixed $relayData Jetons postés, indexés par identifiant (relay_data)
     * @param string $module Nom du module transporteur (minuscules)
     * @param string $countryIso Pays de l'adresse de livraison des rebills (ISO alpha-2)
     * @param string $secret Clé serveur
     * @param string $signingContext Contexte de signature (client, transporteur, boutique)
     * @param int|null $now Horodatage courant (tests), time() sinon
     *
     * @return array ['status' => self::SELECTION_*, 'relay_id' => string|null, 'payload' => array|null]
     *               (SELECTION_EXPIRED : jeton authentique mais trop ancien, à choisir de nouveau)
     */
    public static function decideSelection($relayChoice, $relayData, string $module, string $countryIso, string $secret, string $signingContext, $now = null): array
    {
        $refused = function ($status, $relayId = null) {
            return ['status' => $status, 'relay_id' => $relayId, 'payload' => null];
        };

        $relayId = is_string($relayChoice) ? trim($relayChoice) : '';

        if ('' === $relayId) {
            return $refused(self::SELECTION_MISSING);
        }

        if (!CiklikDeliveryOverride::isValidRelayId($module, $relayId)) {
            return $refused(self::SELECTION_INVALID_ID);
        }

        $token = is_array($relayData) && isset($relayData[$relayId]) ? $relayData[$relayId] : null;
        $check = RelaySelectionSigner::check($token, $secret, $signingContext, $now);

        if (RelaySelectionSigner::EXPIRED === $check['status']) {
            return $refused(self::SELECTION_EXPIRED, $relayId);
        }

        $relay = $check['relay'];

        // Le jeton doit porter le relais choisi : un jeton valide recopié
        // sous un autre identifiant ne vaut rien
        if (!is_array($relay) || !isset($relay['relay_id']) || !is_scalar($relay['relay_id'])
            || (string) $relay['relay_id'] !== $relayId) {
            return $refused(self::SELECTION_INVALID_TOKEN, $relayId);
        }

        // Mêmes règles qu'au rendu : pays de l'adresse, payload conforme
        $selectable = self::filterSelectable([$relay], $module, $countryIso);
        $payload = [] !== $selectable ? self::buildPayload($selectable[0]) : null;

        if (null === $payload) {
            return $refused(self::SELECTION_NOT_SELECTABLE, $relayId);
        }

        return ['status' => self::SELECTION_OK, 'relay_id' => $relayId, 'payload' => $payload];
    }

    /**
     * Libellé d'une distance en mètres : « 850 m » en dessous du kilomètre,
     * kilomètres à une décimale au-delà, mis en forme par $formatNumber
     * (locale du client : « 1,2 km » en français), à défaut avec un point
     * décimal et sans « .0 ». Chaîne vide si inconnue.
     *
     * @param mixed $meters
     * @param callable|null $formatNumber Mise en forme d'un nombre selon la locale (float => string)
     *
     * @return string
     */
    public static function formatDistance($meters, $formatNumber = null): string
    {
        // 0 signifie « non calculée » chez Mondial Relay (recherche par code
        // postal sans coordonnées) : rien à afficher plutôt qu'un « 0 m » faux.
        if (!is_numeric($meters) || (float) $meters <= 0) {
            return '';
        }

        $meters = (int) round((float) $meters);

        if ($meters < 1000) {
            return $meters . ' m';
        }

        $km = round($meters / 1000, 1);

        if (is_callable($formatNumber)) {
            $formatted = call_user_func($formatNumber, $km);

            if (is_string($formatted) && '' !== trim($formatted)) {
                return trim($formatted) . ' km';
            }
        }

        $km = number_format($km, 1, '.', '');

        if ('.0' === substr($km, -2)) {
            $km = substr($km, 0, -2);
        }

        return $km . ' km';
    }

    /**
     * Libellé court d'un relais : « nom - ville ».
     *
     * @param array $relay Relais normalisé ou payload
     *
     * @return string
     */
    public static function formatLabel(array $relay): string
    {
        $name = isset($relay['name']) && is_scalar($relay['name']) ? (string) $relay['name'] : '';
        $city = isset($relay['city']) && is_scalar($relay['city']) ? (string) $relay['city'] : '';

        return trim($name . ' - ' . $city, ' -');
    }
}
