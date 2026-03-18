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
 * Mise à jour vers 1.17.0 :
 * - Ajout de l'onglet "Prévision de Stock" dans le back-office
 *
 * @param Module $module Instance du module
 *
 * @return bool
 */
function upgrade_module_1_17_0($module)
{
    $installer = new PrestaShop\Module\Ciklik\Install\Installer();

    return $installer->installAdminTabs($module);
}
