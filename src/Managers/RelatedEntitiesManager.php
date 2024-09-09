<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Managers;

use Ciklik;
use Configuration;

if (!defined('_PS_VERSION_')) {
    exit;
}

class RelatedEntitiesManager
{
    public static function install(): bool
    {
        $purchaseTypeAttributeGroup = CiklikAttributeGroup::create("Type d'achat", 1);
        $frequenciesAttributeGroup = CiklikAttributeGroup::create("Fréquence d'abonnement", 2);

        if (!$purchaseTypeAttributeGroup || !$frequenciesAttributeGroup) {
            return false;
        }

        Configuration::updateValue(
            Ciklik::CONFIG_ONEOFF_ATTRIBUTE_ID,
            CiklikAttribute::create('Achat en une fois', $purchaseTypeAttributeGroup->id)
        );

        Configuration::updateValue(
            Ciklik::CONFIG_SUBSCRIPTION_ATTRIBUTE_ID,
            CiklikAttribute::create('Abonnement', $purchaseTypeAttributeGroup->id)
        );

        $frequencies = [
            [
                'attribute_name' => 'Mensuel',
                'interval' => 'month',
                'interval_count' => 1,
            ],

            [
                'attribute_name' => 'Tous les 2 mois',
                'interval' => 'month',
                'interval_count' => 2,
            ],

            [
                'attribute_name' => 'Tous les 3 mois',
                'interval' => 'month',
                'interval_count' => 3,
            ],
        ];

        foreach ($frequencies as $key => $properties) {
            $id_attribute = CiklikAttribute::create($properties['attribute_name'], $frequenciesAttributeGroup->id);
            CiklikFrequency::save($id_attribute, $properties['interval'], $properties['interval_count']);

            if (!$key) {
                Configuration::updateValue(Ciklik::CONFIG_DEFAULT_SUBSCRIPTION_ATTRIBUTE_ID, $id_attribute);
            }
        }

        return (bool) Configuration::updateValue(Ciklik::CONFIG_PURCHASE_TYPE_ATTRIBUTE_GROUP_ID, $purchaseTypeAttributeGroup->id)
            && (bool) Configuration::updateValue(Ciklik::CONFIG_FREQUENCIES_ATTRIBUTE_GROUP_ID, $frequenciesAttributeGroup->id);
    }

    public static function uninstall(): bool
    {
        return CiklikAttributeGroup::delete((int) Configuration::get(Ciklik::CONFIG_PURCHASE_TYPE_ATTRIBUTE_GROUP_ID))
            && CiklikAttributeGroup::delete((int) Configuration::get(Ciklik::CONFIG_FREQUENCIES_ATTRIBUTE_GROUP_ID))
            && (bool) Configuration::deleteByName(Ciklik::CONFIG_PURCHASE_TYPE_ATTRIBUTE_GROUP_ID)
            && (bool) Configuration::deleteByName(Ciklik::CONFIG_FREQUENCIES_ATTRIBUTE_GROUP_ID)
            && (bool) Configuration::deleteByName(Ciklik::CONFIG_ONEOFF_ATTRIBUTE_ID)
            && (bool) Configuration::deleteByName(Ciklik::CONFIG_SUBSCRIPTION_ATTRIBUTE_ID)
            && (bool) Configuration::deleteByName(Ciklik::CONFIG_DEFAULT_SUBSCRIPTION_ATTRIBUTE_ID);
    }
}
