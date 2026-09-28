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
 * Éligibilité d'un produit à l'ajout en upsell (« Ajouter à l'abonnement » de
 * la fiche produit). Règle unique de l'affichage du bouton (hook de la fiche)
 * et du contrôleur qui reçoit l'ajout : le serveur refuse tout ce que
 * l'interface ne propose pas.
 *
 * Elle reprend ce qui rend la fiche et le bouton : fonction activée
 * (CIKLIK_ENABLE_UPSELL), produit de la boutique, actif et accessible aux
 * groupes du client (sinon la fiche répond 404 ou 403), visible ailleurs que
 * « nulle part », commandable (les thèmes Classic et Hummingbird masquent le
 * hook en mode catalogue, et Cart::updateQty() refuse un produit non
 * disponible à la commande), et pas un pack (exclu par le hook).
 */
class UpsellEligibility
{
    /** CIKLIK_ENABLE_UPSELL désactivé */
    const DISABLED = 'disabled';

    /** Produit introuvable ou absent de la boutique */
    const UNKNOWN_PRODUCT = 'unknown_product';

    /** Produit inactif, visible nulle part, non disponible à la commande, ou boutique en mode catalogue */
    const NOT_ORDERABLE = 'not_orderable';

    /** Produit réservé à d'autres groupes de clients */
    const NO_ACCESS = 'no_access';

    /** Pack, jamais proposé en upsell */
    const PACK = 'pack';

    /**
     * La fonction d'upsell est-elle activée sur la boutique du contexte ?
     *
     * @return bool
     */
    public static function isEnabled(): bool
    {
        return (bool) \Configuration::get(\Ciklik::CONFIG_ENABLE_UPSELL);
    }

    /**
     * Raison pour laquelle ce produit ne peut pas être ajouté en upsell par ce
     * client, null s'il le peut.
     *
     * @param mixed $product Produit (\Product) chargé dans la boutique du contexte
     * @param int $idCustomer Client connecté
     *
     * @return string|null Une des constantes de refus, ou null
     */
    public static function refusal($product, int $idCustomer)
    {
        if (!self::isEnabled()) {
            return self::DISABLED;
        }

        if (!is_object($product) || !\Validate::isLoadedObject($product) || !$product->isAssociatedToShop()) {
            return self::UNKNOWN_PRODUCT;
        }

        if (!$product->active
            || 'none' === $product->visibility
            || !$product->available_for_order
            || self::isCatalogMode()) {
            return self::NOT_ORDERABLE;
        }

        if (!$product->checkAccess($idCustomer)) {
            return self::NO_ACCESS;
        }

        if (\Pack::isPack((int) $product->id)) {
            return self::PACK;
        }

        return null;
    }

    /**
     * Mode catalogue (commande impossible), selon la règle de PrestaShop quand
     * elle existe : réglage, prix masqués au groupe, pays restreint.
     *
     * @return bool
     */
    private static function isCatalogMode(): bool
    {
        if (method_exists('Configuration', 'isCatalogMode')) {
            return (bool) \Configuration::isCatalogMode();
        }

        return (bool) \Configuration::get('PS_CATALOG_MODE');
    }
}
