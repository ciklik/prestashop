<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_1_0($object)
{
    $purchase_type_attributes = AdminConfigureCiklikController::getCiklikAttributes(Ciklik::CONFIG_PURCHASE_TYPE_ATTRIBUTE_GROUP_ID);
    $frequencies_attributes = AdminConfigureCiklikController::getCiklikAttributes(Ciklik::CONFIG_FREQUENCIES_ATTRIBUTE_GROUP_ID);
    $object->registerHook('actionGetProductPropertiesBefore');
    $id_hook = Hook::getIdByName('actionGetProductPropertiesBefore', false);

    return
        Configuration::updateValue(Ciklik::CONFIG_ONEOFF_ATTRIBUTE_ID, $purchase_type_attributes[0]['id_attribute']) &&
        Configuration::updateValue(Ciklik::CONFIG_SUBSCRIPTION_ATTRIBUTE_ID, $purchase_type_attributes[1]['id_attribute']) &&
        Configuration::updateValue(Ciklik::CONFIG_DEFAULT_SUBSCRIPTION_ATTRIBUTE_ID, $frequencies_attributes[0]['id_attribute']) &&
        $object->updatePosition($id_hook, 0, 1)
    ;
}
