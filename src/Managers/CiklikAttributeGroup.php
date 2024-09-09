<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Managers;

use AttributeGroup;
use Configuration;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CiklikAttributeGroup
{
    public static function create(string $name, int $position): AttributeGroup
    {
        $attributeGroup = new AttributeGroup();
        $attributeGroup->group_type = 'radio';
        $attributeGroup->position = AttributeGroup::getHigherPosition() + 1;
        $attributeGroupName = [
            Configuration::get('PS_LANG_DEFAULT') => $name,
        ];
        $attributeGroup->name = $attributeGroupName;
        $attributeGroup->public_name = $attributeGroupName;

        $attributeGroup->add();

        return $attributeGroup;
    }

    public static function delete(int $id_attribute_group): bool
    {
        $attributeGroup = new AttributeGroup($id_attribute_group);

        return $attributeGroup->delete();
    }
}
