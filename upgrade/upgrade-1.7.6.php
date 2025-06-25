<?php
/**
 * @author    Ciklik SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3.0-php/ Academic Free License (AFL 3.0)
 */

use PrestaShop\Module\Ciklik\Install\Installer;
use PrestaShop\Module\Ciklik\Sql\SqlQueries;

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_7_6($module)
{
    
    // Exécuter les requêtes SQL du mode fréquence
    $queries = SqlQueries::installFrequencyModeDatabase();
    
    foreach ($queries as $query) {
        if (!Db::getInstance()->execute($query)) {
            \PrestaShopLogger::addLog(
                'Ciklik upgrade 1.7.6 - Erreur lors de l\'exécution de la requête: ' . $query,
                3,
                null,
                'Ciklik',
                null,
                true
            );
            return false;
        }
    }
    
    return true;
} 