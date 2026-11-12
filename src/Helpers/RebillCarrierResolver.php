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
 * Transporteur du panier de renouvellement.
 *
 * Ordre : le transporteur de l'abonnement (par référence), puis le transporteur
 * par défaut de la boutique s'il en désigne un vrai, puis le premier transporteur
 * qui livre la zone du client. Le réglage par défaut peut valoir « meilleur prix »
 * ou « meilleur classement » (valeurs négatives) : il ne désigne alors aucun
 * transporteur et le panier partait sans transporteur.
 */
class RebillCarrierResolver
{
    /**
     * @param int $idReference
     * @param int $idZone
     * @param array $groups groupes du client
     *
     * @return \Carrier|null null si aucun transporteur ne livre ce client
     */
    public static function resolve($idReference, $idZone, array $groups)
    {
        $carrier = \Carrier::getCarrierByReference((int) $idReference);
        if (self::isUsable($carrier)) {
            return $carrier;
        }

        $carrier = new \Carrier((int) \Configuration::get('PS_CARRIER_DEFAULT'));
        if (self::isUsable($carrier)) {
            return $carrier;
        }

        // getCarriersForOrder() exige un panier en contexte : getCarriers() filtre zone et groupes sans panier
        $rows = \Carrier::getCarriers((int) \Configuration::get('PS_LANG_DEFAULT'), true, false, (int) $idZone, $groups, \Carrier::ALL_CARRIERS);
        foreach ((array) $rows as $row) {
            $carrier = new \Carrier((int) $row['id_carrier']);
            if (self::isUsable($carrier)) {
                return $carrier;
            }
        }

        return null;
    }

    private static function isUsable($carrier)
    {
        return $carrier instanceof \Carrier && (int) $carrier->id > 0 && $carrier->active && !$carrier->deleted;
    }
}
