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

class CiklikCombination
{
    public static function get(int $id_product): array
    {
        $query = new DbQuery();
        $query->select('pa.id_product_attribute, pa.id_product, pa.reference, pa.price, pac.id_attribute');
        $query->from('product_attribute', 'pa');
        $query->leftJoin('product_attribute_combination', 'pac', 'pac.id_product_attribute = pa.id_product_attribute');
        $query->where('pa.id_product = "' . $id_product . '"');
        $query->where('pac.id_attribute IN (SELECT id_attribute FROM `' . _DB_PREFIX_ . 'attribute` WHERE id_attribute_group = ' . (int) Configuration::get(Ciklik::CONFIG_FREQUENCIES_ATTRIBUTE_GROUP_ID) . ')');

        return Db::getInstance()->executeS($query);
    }

    public static function getIds(int $id_product): array
    {
        $ids = [];
        $combinations = static::get($id_product);

        if (count($combinations)) {
            foreach ($combinations as $combination) {
                $ids[] = $combination['id_product_attribute'];
            }
        }

        return $ids;
    }


    public static function getOne(
        int $id_product,
        int $frequency_id_attribute,
        array $constraint_attributes_ids = [])
    {
        $query = new DbQuery();
        $query->select('pa.id_product_attribute, pa.id_product, pa.reference, pa.price, pac.id_attribute');
        $query->from('product_attribute', 'pa');
        $query->leftJoin('product_attribute_combination', 'pac', 'pa.id_product_attribute = pac.id_product_attribute');
        $query->where('pa.id_product = ' . $id_product);
        $query->where('pac.id_attribute = ' . $frequency_id_attribute);

        if (count($constraint_attributes_ids)) {
            $constraint_attributes_ids = array_reverse($constraint_attributes_ids);
            $constraint_queries = [];
            $i = 0;
            foreach ($constraint_attributes_ids as $constraint_attribute_id) {
                $constraint_query = new DbQuery();
                $constraint_query->select('pa.id_product_attribute');
                $constraint_query->from('product_attribute', 'pa');
                $constraint_query->leftJoin('product_attribute_combination', 'pac', 'pa.id_product_attribute = pac.id_product_attribute');
                $constraint_query->where('pa.id_product = ' . $id_product);
                $constraint_query->where('pac.id_attribute = ' . $constraint_attribute_id);
                if (array_key_exists($i - 1, $constraint_queries)) {
                    $constraint_query->where($constraint_queries[$i - 1]);
                }
                $constraint_queries[] = 'pa.id_product_attribute IN (' . $constraint_query->build() . ')';
                $i++;
            }

            $query->where(end($constraint_queries));
        }

        return Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow($query);
    }

    public static function isSubscribable(int $id_product_attribute): bool
    {
        $query = new DbQuery();
        $query->select('pac.`id_attribute`');
        $query->from('product_attribute_combination', 'pac');
        $query->leftJoin('attribute', 'a', 'a.`id_attribute` = pac.`id_attribute`');
        $query->where('pac.`id_product_attribute` = "' . $id_product_attribute . '"');
        $query->where('a.`id_attribute_group` = "' . (int) Configuration::get(Ciklik::CONFIG_FREQUENCIES_ATTRIBUTE_GROUP_ID) . '"');

        return (int) Db::getInstance()->getValue($query) > 0;
    }
}
