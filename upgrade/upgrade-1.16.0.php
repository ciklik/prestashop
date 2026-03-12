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
 * Enregistre le hook actionCiklikCartBeforeRebill pour les installations existantes.
 * Ce hook permet aux modules tiers de modifier le panier avant un rebill.
 */
function upgrade_module_1_16_0($module)
{
    return (bool) $module->registerHook('actionCiklikCartBeforeRebill');
}
