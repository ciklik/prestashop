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
 * Source unique de vérité pour les tests de version PrestaShop.
 *
 * Centralise les `version_compare` du module : chaque capacité correspond à
 * la version d'introduction (vérifiée sur les tags officiels PrestaShop) d'un
 * hook ou d'une API, afin d'enregistrer/dispatcher les bons hooks selon la version.
 *
 * Les méthodes acceptent une version optionnelle (utile pour les tests unitaires) ;
 * par défaut elles lisent `_PS_VERSION_`.
 */
class PsVersionCapabilities
{
    /**
     * Page commande du back-office migrée sous Symfony.
     * Hook `displayAdminOrderMainBottom` introduit en 1.7.7.0.
     * En deçà, utiliser les hooks legacy `displayAdminOrder*`.
     *
     * @param string|null $psVersion
     *
     * @return bool
     */
    public static function hasMigratedOrderPage($psVersion = null)
    {
        return version_compare(self::resolve($psVersion), '1.7.7.0', '>=');
    }

    /**
     * Hook `displayProductActions` sur la fiche produit front, introduit en 1.7.6.0.
     * En deçà, utiliser un hook produit de repli (ex. `displayProductButtons`).
     *
     * @param string|null $psVersion
     *
     * @return bool
     */
    public static function hasProductActionsHook($psVersion = null)
    {
        return version_compare(self::resolve($psVersion), '1.7.6.0', '>=');
    }

    /**
     * Hook `actionAfterUpdateProductFormHandler` (form handler produit), introduit en 1.7.8.0.
     * En deçà, utiliser un repli `actionProduct*` pour la sauvegarde des fréquences.
     *
     * @param string|null $psVersion
     *
     * @return bool
     */
    public static function hasProductFormHandlerHook($psVersion = null)
    {
        return version_compare(self::resolve($psVersion), '1.7.8.0', '>=');
    }

    /**
     * Hook `actionPresentPaymentOptions` (filtrage des options de paiement), introduit en PS 8.0.0.
     *
     * @param string|null $psVersion
     *
     * @return bool
     */
    public static function hasPresentPaymentOptions($psVersion = null)
    {
        return version_compare(self::resolve($psVersion), '8.0.0', '>=');
    }

    /**
     * Retourne la version à comparer (argument explicite ou `_PS_VERSION_`).
     *
     * @param string|null $psVersion
     *
     * @return string
     */
    private static function resolve($psVersion)
    {
        return null === $psVersion ? _PS_VERSION_ : $psVersion;
    }
}
