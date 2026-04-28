<?php

/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

use PrestaShop\Module\Ciklik\Api\Shop;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Mise à jour vers 1.19.0 :
 * - Signale à la plateforme Ciklik que le module supporte le fingerprint JSON
 *   (migration sécurité : suppression de PHP unserialize)
 * - Envoie la version du module dans les metadata
 *
 * @param Module $module Instance du module
 *
 * @return bool
 */
function upgrade_module_1_19_0($module)
{
    $token = Configuration::get(Ciklik::CONFIG_API_TOKEN);

    if (empty($token)) {
        return true;
    }

    try {
        $link = (isset($module->context->link) && $module->context->link) ? $module->context->link : new Link();
        $shopApi = new Shop($link);
        $shopApi->metadata([
            'supports_json_fingerprint' => '1',
            'module_version' => Ciklik::VERSION,
        ]);
    } catch (Exception $e) {
        // Ne pas bloquer la mise à jour si l'appel API échoue
        // Le flag sera renvoyé lors de la prochaine sauvegarde de configuration
        PrestaShopLogger::addLog(
            'Ciklik upgrade 1.19.0: échec envoi supports_json_fingerprint - ' . $e->getMessage(),
            2,
            null,
            'Module',
            $module->id
        );
    }

    return true;
}
