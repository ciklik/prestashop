<?php
/**
 * @author    Ciklik SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

use PrestaShop\Module\Ciklik\Sql\SqlQueries;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Mise à niveau 1.23.0, pour les installations existantes. Trois fonctionnalités
 * arrivent dans cette version et partagent ce script, PrestaShop n'appelant qu'une
 * seule fonction par numéro de version.
 *
 * 1. Override de point relais : crée la table ciklik_delivery_override, posée par
 *    le marchand en back-office et consultée par les drivers de DeliveryModuleManager
 *    avant le clonage historique au rebill.
 * 2. Récap panier : mention légale de récurrence et deux avertissements, sous le
 *    panier, en mode fréquence.
 * 3. Consentement à l'abonnement : case à cocher à l'étape paiement.
 *
 * Les réglages 2 et 3 sont désactivés par défaut, leur activation reste un choix
 * volontaire du marchand.
 */
function upgrade_module_1_23_0($module)
{
    foreach (SqlQueries::installDeliveryOverrideQueries() as $query) {
        if (!Db::getInstance()->execute($query)) {
            PrestaShopLogger::addLog(
                'Ciklik upgrade 1.23.0 - Erreur lors de l\'exécution de la requête: ' . $query,
                3,
                null,
                'Ciklik',
                null,
                true
            );

            return false;
        }
    }

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
