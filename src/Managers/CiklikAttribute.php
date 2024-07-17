<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Managers;

use Configuration;
use Tools;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CiklikAttribute
{
    public static function create(string $name, int $id_attribute_group): int
    {
        $position = \Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue('
            SELECT `position`+1
            FROM `' . _DB_PREFIX_ . 'attribute`
            WHERE `id_attribute_group` = ' . $id_attribute_group . '
            ORDER BY position DESC
        ');

        \Db::getInstance()->execute('
            INSERT INTO `' . _DB_PREFIX_ . 'attribute` (`id_attribute_group`, `position`) 
            VALUES (' . $id_attribute_group . ', ' . (int) $position . ')
        ');

        $id_attribute = (int) \Db::getInstance()->Insert_ID();

        \Db::getInstance()->execute('
            INSERT INTO `' . _DB_PREFIX_ . 'attribute_lang` (`id_attribute`, `id_lang`, `name`)
            VALUES (' . $id_attribute . ', ' . (int) \Configuration::get('PS_LANG_DEFAULT') . ', \'' . $name . '\')
        ');

        \Db::getInstance()->execute('
            INSERT INTO `' . _DB_PREFIX_ . 'attribute_shop` (`id_attribute`, `id_shop`)
            VALUES (' . $id_attribute . ', 1)
        ');

        return $id_attribute;
    }

    public static function delete(int $id_attribute): bool
    {
        return \Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . 'attribute` WHERE `id_attribute` = ' . $id_attribute)

            && \Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . 'attribute_lang` WHERE `id_attribute` = ' . $id_attribute);
    }

    public static function isFrequencyAttribute(int $id_attribute): bool
    {
        if (!$id_attribute) {
            return (int) Configuration::get('CIKLIK_FREQUENCIES_ATTRIBUTE_GROUP_ID') === (int) Tools::getValue('id_attribute_group');
        }

        return \Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue('
            SELECT `id_attribute_group`
            FROM `' . _DB_PREFIX_ . 'attribute`
            WHERE `id_attribute` = ' . $id_attribute . '
        ') === (int) \Configuration::get('CIKLIK_FREQUENCIES_ATTRIBUTE_GROUP_ID');
    }
}
