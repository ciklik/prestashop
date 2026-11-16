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
 * Signature du lien qui envoie le client de la boutique vers la page de paiement Ciklik.
 *
 * Ciklik n'accepte le lien que si la signature couvre le même client, le même panier, le même moyen de paiement et une
 * expiration encore à venir. La clé est celle du webservice créée pour Ciklik : la boutique et Ciklik sont seuls à la
 * connaître. Le domaine « ciklik-checkout » sépare ces signatures de tout autre usage de la clé.
 *
 * Données signées : ciklik-checkout|id_client|id_panier|moyen|expiration, en HMAC-SHA256 hexadécimal.
 */
class CheckoutUrlSigner
{
    const ALGO = 'sha256';

    /** Domaine de signature : jamais la clé nue */
    const DOMAIN = 'ciklik-checkout';

    /** Durée de validité du lien, en secondes */
    const TTL = 1800;

    /**
     * Paramètres de requête à ajouter au lien de paiement.
     *
     * @param int $idCustomer
     * @param int $idCart
     * @param string $classKey Moyen de paiement
     * @param string $secret Clé du webservice Ciklik (jamais transmise)
     * @param int|null $now Horodatage courant (tests), time() sinon
     *
     * @return array ['expires' => int, 'signature' => string]
     */
    public static function sign($idCustomer, $idCart, string $classKey, string $secret, $now = null): array
    {
        $expires = (null === $now ? time() : (int) $now) + self::TTL;

        return [
            'expires' => $expires,
            'signature' => hash_hmac(self::ALGO, self::signedData($idCustomer, $idCart, $classKey, $expires), $secret),
        ];
    }

    /**
     * Données couvertes par la signature.
     *
     * @param int $idCustomer
     * @param int $idCart
     * @param string $classKey
     * @param int $expires
     *
     * @return string
     */
    public static function signedData($idCustomer, $idCart, string $classKey, $expires): string
    {
        return self::DOMAIN . '|' . (int) $idCustomer . '|' . (int) $idCart . '|' . $classKey . '|' . (int) $expires;
    }
}
