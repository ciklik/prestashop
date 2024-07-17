<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Managers;

use Cart;
use Db;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CiklikSubscribable
{
    public static function handle(int $id_product)
    {
        $combinations = CiklikCombination::get($id_product);

        if (count($combinations)) {
            if (!static::isSubscribable($id_product)) {
                static::create($id_product);
            }

            CiklikSubscribableVariant::pushToCiklik($id_product, $combinations);
        } else {
            static::deleteByIdProduct($id_product);
        }
    }

    public static function create(int $id_product): int
    {
        Db::getInstance()->execute('
            INSERT INTO `' . _DB_PREFIX_ . 'ciklik_subscribables` (`id_product`) 
            VALUES (' . $id_product . ')
        ');

        return (int) Db::getInstance()->Insert_ID();
    }

    public static function deleteByIdProduct(int $id_product): bool
    {
        return Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . 'ciklik_subscribables` WHERE `id_product` = ' . $id_product);
    }

    public static function isSubscribable(int $id_product): bool
    {
        return (int) Db::getInstance()->getValue('
            SELECT `id_subscribable`
            FROM `' . _DB_PREFIX_ . 'ciklik_subscribables`
            WHERE `id_product` = ' . $id_product . '
        ') > 0;
    }

    public static function cartHasSubscribable(Cart $cart): bool
    {
        $products = $cart->getProducts();

        foreach ($products as $product) {
            if (array_key_exists('id_product_attribute', $product) && (int) $product['id_product_attribute']) {
                $combinations = CiklikCombination::get($product['id_product']);

                foreach ($combinations as $combination) {
                    if ($product['id_product_attribute'] === $combination['id_product_attribute']) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
