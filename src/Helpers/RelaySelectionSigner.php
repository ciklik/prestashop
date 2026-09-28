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
 * Jeton signé portant un relais sélectionnable dans l'espace client.
 *
 * Le serveur signe chaque relais qu'il propose (résultat de recherche ou
 * relais connu) au rendu de la page ; à la confirmation, il ne fait confiance
 * qu'à un jeton dont la signature est valide pour le même client, le même
 * transporteur et la même boutique, et émis depuis moins d'une heure. Le
 * payload enregistré vient donc toujours d'une source serveur, jamais d'un
 * formulaire libre.
 *
 * Format : base64url(json{iat, relay}) . '.' . hmac_sha256(domaine|contexte|blob).
 * Le domaine « ciklik-relay » sépare ces signatures de tout autre usage de la
 * clé serveur (Tools::getToken signe avec la même clé, nue).
 */
class RelaySelectionSigner
{
    const ALGO = 'sha256';

    /** Domaine de signature : jamais la clé serveur nue */
    const DOMAIN = 'ciklik-relay';

    /** Durée de validité d'un jeton de sélection, en secondes */
    const TTL = 3600;

    /** Tolérance d'horloge sur un jeton daté dans le futur, en secondes */
    const CLOCK_SKEW = 60;

    /** Taille maximale acceptée d'un jeton (un relais GLS avec horaires ~1,2 Ko) */
    const MAX_TOKEN_LENGTH = 8192;

    /** Jeton authentique, dans sa durée de validité */
    const VALID = 'valid';

    /** Jeton authentique pour ce contexte, mais émis depuis plus que la durée de validité */
    const EXPIRED = 'expired';

    /** Jeton absent, malformé, altéré, d'un autre contexte ou daté dans le futur */
    const INVALID = 'invalid';

    /**
     * @param array $relay Relais normalisé
     * @param string $secret Clé serveur (jamais transmise)
     * @param string $context Liaison du jeton : « id_client|module|id_boutique »
     * @param int|null $now Horodatage d'émission (tests), time() sinon
     *
     * @return string
     */
    public static function sign(array $relay, string $secret, string $context, $now = null): string
    {
        $now = null === $now ? time() : (int) $now;
        $blob = self::base64UrlEncode((string) json_encode(['iat' => $now, 'relay' => $relay]));

        return $blob . '.' . hash_hmac(self::ALGO, self::signedData($context, $blob), $secret);
    }

    /**
     * Vérifie un jeton et restitue le relais qu'il porte.
     *
     * @param mixed $token Valeur reçue du formulaire
     * @param string $secret
     * @param string $context
     * @param int|null $now Horodatage courant (tests), time() sinon
     * @param int $ttl Durée de validité acceptée
     *
     * @return array|null Relais, ou null si le jeton est absent, altéré, expiré ou d'un autre contexte
     */
    public static function verify($token, string $secret, string $context, $now = null, int $ttl = self::TTL)
    {
        $check = self::check($token, $secret, $context, $now, $ttl);

        return self::VALID === $check['status'] ? $check['relay'] : null;
    }

    /**
     * Vérifie un jeton en distinguant l'expiration du refus : un jeton
     * authentique pour ce contexte mais trop ancien (page restée ouverte)
     * n'appelle pas le même message qu'un jeton forgé. L'expiration n'est
     * rendue qu'une fois la signature vérifiée : elle ne renseigne sur rien
     * d'autre.
     *
     * @param mixed $token Valeur reçue du formulaire
     * @param string $secret
     * @param string $context
     * @param int|null $now Horodatage courant (tests), time() sinon
     * @param int $ttl Durée de validité acceptée
     *
     * @return array ['status' => self::VALID|self::EXPIRED|self::INVALID, 'relay' => array|null] (relais seulement si valide)
     */
    public static function check($token, string $secret, string $context, $now = null, int $ttl = self::TTL): array
    {
        $invalid = ['status' => self::INVALID, 'relay' => null];

        if (!is_string($token) || '' === $token || strlen($token) > self::MAX_TOKEN_LENGTH) {
            return $invalid;
        }

        $parts = explode('.', $token);

        if (2 !== count($parts)
            || !preg_match('/^[A-Za-z0-9_-]+$/', $parts[0])
            || !preg_match('/^[a-f0-9]{64}$/', $parts[1])) {
            return $invalid;
        }

        $expected = hash_hmac(self::ALGO, self::signedData($context, $parts[0]), $secret);

        if (!hash_equals($expected, $parts[1])) {
            return $invalid;
        }

        $decoded = base64_decode(strtr($parts[0], '-_', '+/'), true);
        $data = false !== $decoded ? json_decode($decoded, true) : null;

        if (!is_array($data) || !isset($data['iat'], $data['relay'])
            || !is_int($data['iat']) || !is_array($data['relay'])) {
            return $invalid;
        }

        $now = null === $now ? time() : (int) $now;

        if ($data['iat'] > $now + self::CLOCK_SKEW) {
            return $invalid;
        }

        if ($now - $data['iat'] > $ttl) {
            return ['status' => self::EXPIRED, 'relay' => null];
        }

        return ['status' => self::VALID, 'relay' => $data['relay']];
    }

    /**
     * Jeton propre au module pour les formulaires de la page relais, en plus
     * du jeton des actions d'abonnement. Dérivé de la clé serveur sous le
     * même domaine, lié au contexte ({@see formContext()}).
     *
     * @param string $secret
     * @param string $context
     *
     * @return string
     */
    public static function formToken(string $secret, string $context): string
    {
        return hash_hmac(self::ALGO, self::DOMAIN . '|form|' . $context, $secret);
    }

    /**
     * Contexte signé du jeton de formulaire : client, boutique de la requête
     * et hash du mot de passe du client. Un changement de mot de passe
     * invalide le jeton : sans lui, un jeton recopié restait valable tant que
     * la clé serveur ne changeait pas.
     *
     * @param mixed $idCustomer
     * @param mixed $idShop
     * @param mixed $passwdHash Hash du mot de passe (Customer::$passwd)
     *
     * @return string
     */
    public static function formContext($idCustomer, $idShop, $passwdHash): string
    {
        return (int) $idCustomer . '|' . (int) $idShop . '|' . (is_scalar($passwdHash) ? (string) $passwdHash : '');
    }

    /**
     * Données couvertes par la signature : domaine, contexte, blob.
     *
     * @param string $context
     * @param string $blob
     *
     * @return string
     */
    private static function signedData(string $context, string $blob): string
    {
        return self::DOMAIN . '|' . $context . '|' . $blob;
    }

    /**
     * @param string $data
     *
     * @return string
     */
    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
