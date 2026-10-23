<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Enregistre les hooks de changement d'email client (voir CustomerEmailSync). Un échec ne fait pas échouer la mise à
 * jour (comme les scripts précédents) : il est journalisé.
 */
function upgrade_module_1_27_0($module)
{
    try {
        if (!$module->registerHook(['actionObjectCustomerUpdateBefore', 'actionObjectCustomerUpdateAfter'])) {
            PrestaShopLogger::addLog('Ciklik upgrade 1.27.0 - enregistrement des hooks de changement d\'email échoué', 3);
        }
    } catch (Throwable $e) {
        PrestaShopLogger::addLog('Ciklik upgrade 1.27.0 - ' . $e->getMessage(), 3);
    }

    return true;
}
