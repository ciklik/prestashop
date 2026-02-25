<?php

/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Helpers;

use Tools;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Helper pour le formatage des prix
 * Compatible PrestaShop 1.7.7 à 9.x
 */
class PriceHelper
{
    /**
     * Formate un prix de manière compatible PS 1.7.7+ et PS9
     *
     * Utilise getCurrentLocale()->formatPrice() qui est la méthode recommandée
     * depuis PS 1.7.6. Fait un fallback sur Tools::displayPrice() si le locale
     * n'est pas disponible (peut arriver dans certains contextes comme les upgrades).
     *
     * @param float $price Le prix à formater
     * @param \Currency|null $currency La devise (utilise la devise courante si null)
     *
     * @return string Le prix formaté
     */
    public static function formatPrice($price, $currency = null)
    {
        $context = \Context::getContext();

        if ($currency === null) {
            $currency = $context->currency;
        }

        // Méthode recommandée : utiliser getCurrentLocale()->formatPrice()
        // Disponible depuis PS 1.7.6, mais peut retourner null dans certains contextes
        if (method_exists($context, 'getCurrentLocale')) {
            $locale = $context->getCurrentLocale();
            if ($locale !== null) {
                return $locale->formatPrice($price, $currency->iso_code);
            }
        }

        // Fallback : Tools::displayPrice() pour les cas où le locale n'est pas dispo
        // Note : Cette méthode n'existe plus en PS9
        if (method_exists('Tools', 'displayPrice')) {
            return \Tools::displayPrice($price, $currency);
        }

        // Fallback ultime si rien n'est disponible
        return number_format((float) $price, 2, ',', ' ') . ' ' . $currency->sign;
    }
}
