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
 * Enregistre le hook displayCartExtraProductInfo pour les installations existantes.
 *
 * Ce hook (introduit en PrestaShop 8.2) permet d'afficher des informations
 * supplémentaires sur chaque ligne produit du récap panier. Le module l'utilise
 * pour signaler le type d'achat (abonnement / achat unique) et la fréquence
 * sélectionnée lorsque le mode fréquence est actif.
 *
 * L'enregistrement est silencieux sur les versions < 8.2 (le hook est accepté
 * par le noyau mais ne sera appelé par aucun thème jusqu'à l'ajout manuel
 * dans cart-detailed-product-line.tpl côté thème).
 */
function upgrade_module_1_21_0($module)
{
    if ($module->isRegisteredInHook('displayCartExtraProductInfo')) {
        return true;
    }

    return (bool) $module->registerHook('displayCartExtraProductInfo');
}
