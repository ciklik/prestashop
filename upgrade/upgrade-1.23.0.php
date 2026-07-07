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
 * Récap panier : enregistre le hook displayShoppingCartFooter et pose les
 * valeurs par défaut pour les installations existantes.
 *
 * Le footer et les deux avertissements (panier mixte, fréquences différentes)
 * sont activés par défaut ; les messages sont laissés vides — le template
 * retombe alors sur des textes traduisibles par défaut. Activations et textes
 * sont personnalisables en back-office (mode fréquence uniquement).
 *
 * NB : cible : release 1.23.0.
 */
function upgrade_module_1_23_0($module)
{
    Configuration::updateGlobalValue(Ciklik::CONFIG_CART_FOOTER_ENABLED, '1');
    Configuration::updateGlobalValue(Ciklik::CONFIG_CART_ALERT_MIXED_ENABLED, '1');
    Configuration::updateGlobalValue(Ciklik::CONFIG_CART_ALERT_FREQ_ENABLED, '1');

    if ($module->isRegisteredInHook('displayShoppingCartFooter')) {
        return true;
    }

    return (bool) $module->registerHook('displayShoppingCartFooter');
}
