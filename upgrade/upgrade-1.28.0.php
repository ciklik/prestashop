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
 * Crée l'onglet caché AdminCiklikProductTransform (« Transformer en abonnement » de la fiche produit)
 *
 * @param Module $module Instance du module
 *
 * @return bool
 */
function upgrade_module_1_28_0($module)
{
    return (new PrestaShop\Module\Ciklik\Install\Installer())->installAdminTabs($module);
}
