<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Managers;

use Ciklik;
use Configuration;
use Db;
use DbQuery;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CiklikFrequency
{
    public static function save(int $id_attribute,
        string $interval,
        int $interval_count,
        int $id_frequency = 0): int
    {
        if ($id_frequency) {
            Db::getInstance()->update('ciklik_frequencies', ['interval' => $interval, 'interval_count' => $interval_count], '`id_frequency` = ' . (int) $id_frequency);
        } else {
            Db::getInstance()->execute('
            INSERT INTO `' . _DB_PREFIX_ . 'ciklik_frequencies` (`id_attribute`, `interval`, `interval_count`) 
            VALUES (' . $id_attribute . ', \'' . pSQL($interval) . '\', ' . $interval_count . ')
        ');

            return (int) Db::getInstance()->Insert_ID();
        }

        return $id_frequency;
    }

    public static function getByIdAttribute(int $id_attribute): array
    {
        return (array) Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'ciklik_frequencies` WHERE `id_attribute` = ' . $id_attribute);
    }

    public static function getByIdProductAttribute(int $id_product_attribute): array
    {
        $query = new DbQuery();
        $query->select('*');
        $query->from('ciklik_frequencies', 'cf');
        $query->leftJoin('product_attribute_combination', 'pac', 'pac.`id_attribute` = cf.`id_attribute`');
        $query->where('pac.`id_product_attribute` = ' . $id_product_attribute);
        $query->where('pac.`id_attribute` IN (SELECT id_attribute FROM `' . _DB_PREFIX_ . 'attribute` WHERE id_attribute_group = ' . (int) Configuration::get(Ciklik::CONFIG_FREQUENCIES_ATTRIBUTE_GROUP_ID) . ')');

        return (array) Db::getInstance()->getRow($query);
    }

    public static function deleteByIdAttribute(int $id_attribute): bool
    {
        return Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . 'ciklik_frequencies` WHERE `id_attribute` = ' . $id_attribute);
    }

    public static function isFrequencyAttribute(int $id_attribute): bool
    {
        return Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue('
            SELECT `id_attribute_group`
            FROM `' . _DB_PREFIX_ . 'attribute`
            WHERE `id_attribute` = ' . $id_attribute . '
        ') === (int) Configuration::get('CIKLIK_FREQUENCIES_ATTRIBUTE_GROUP_ID');
    }
}
