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

function upgrade_module_1_12_0($module)
{
    // Activer la création de thread de message client par défaut pour les installations existantes
    // Ne pas écraser si la configuration existe déjà (pour préserver les choix des utilisateurs)
    if (Configuration::get(Ciklik::CONFIG_ENABLE_ORDER_THREAD) === false) {
        Configuration::updateValue(Ciklik::CONFIG_ENABLE_ORDER_THREAD, '1');
    }

    // Définir le statut par défaut à 'open' si non défini
    if (Configuration::get(Ciklik::CONFIG_ORDER_THREAD_STATUS) === false) {
        Configuration::updateValue(Ciklik::CONFIG_ORDER_THREAD_STATUS, 'open');
    }

    return true;
}
