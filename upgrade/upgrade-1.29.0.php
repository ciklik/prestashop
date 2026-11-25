<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

use PrestaShop\Module\Ciklik\Install\WebservicePermissions;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Mise à niveau 1.29.0, pour les installations existantes : droit de lecture des déclinaisons (combinations) pour la
 * clé webservice du module, ajouté sans toucher aux autres droits de la clé.
 *
 * Comme les scripts précédents, le script renvoie toujours vrai : un échec ferait désactiver le module de paiement par
 * PrestaShop, sans jamais rejouer le script. Sans le droit, Ciklik affiche les déclinaisons par leur seul identifiant ;
 * l'enregistrement de la configuration du module refait l'ajout.
 *
 * @param Module $module Instance du module
 *
 * @return bool
 */
function upgrade_module_1_29_0($module)
{
    try {
        if (!WebservicePermissions::ensureCombinations()) {
            PrestaShopLogger::addLog('Ciklik upgrade 1.29.0 - droit combinations non ajouté à la clé webservice du module', 2);
        }
    } catch (Throwable $e) {
        PrestaShopLogger::addLog('Ciklik upgrade 1.29.0 - ' . $e->getMessage(), 3);
    }

    return true;
}
