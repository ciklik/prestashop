<?php
/**
 * @author    Ciklik SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Récap panier et consentement à l'abonnement : enregistre les hooks
 * displayShoppingCartFooter et termsAndConditions, et pose les valeurs par
 * défaut pour les installations existantes.
 *
 * Le footer et les deux avertissements (panier mixte, fréquences différentes)
 * sont désactivés par défaut : l'activation est un choix volontaire du
 * marchand en back-office. Les messages sont laissés vides — le template
 * retombe alors sur des textes traduisibles par défaut (mode fréquence
 * uniquement).
 *
 * La case de consentement à l'abonnement est désactivée par défaut elle aussi.
 * Une fois activée, elle n'apparaît à l'étape paiement que si le panier
 * contient un abonnement.
 */
function upgrade_module_1_23_0($module)
{
    // Ne pose chaque défaut que si la clé n'existe pas encore, pour ne pas
    // écraser un choix marchand si le script venait à être rejoué.
    foreach ([
        Ciklik::CONFIG_CART_FOOTER_ENABLED,
        Ciklik::CONFIG_CART_ALERT_MIXED_ENABLED,
        Ciklik::CONFIG_CART_ALERT_FREQ_ENABLED,
        Ciklik::CONFIG_ENABLE_SUBSCRIPTION_CONSENT,
    ] as $key) {
        if (Configuration::get($key) === false) {
            Configuration::updateGlobalValue($key, '0');
        }
    }

    foreach (['displayShoppingCartFooter', 'termsAndConditions'] as $hook) {
        if (!$module->isRegisteredInHook($hook) && !$module->registerHook($hook)) {
            return false;
        }
    }

    return true;
}
