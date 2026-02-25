<?php

/**
 * @author    Ciklik SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

use Ciklik;
use Configuration;

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_15_0($module)
{
    // Définir la base de calcul des réductions fréquence par défaut à 'gross'
    // pour préserver le comportement existant
    if (Configuration::get(Ciklik::CONFIG_FREQUENCY_PRICE_BASE) === false) {
        Configuration::updateValue(Ciklik::CONFIG_FREQUENCY_PRICE_BASE, 'gross');
    }

    return true;
}
