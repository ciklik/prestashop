<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Managers;

use Ciklik;
use Configuration;
use Context;
use Db;
use DbQuery;
use PrestaShop\Module\Ciklik\Api\Subscribable;
use Product;
use Tools;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CiklikSubscribableVariant
{
    public static function pushToCiklik(int $id_product, array $combinations = []): void
    {
        if (count($combinations)) {
            $variants = [];
            $product = new Product($id_product, true, Configuration::get('PS_LANG_DEFAULT'));

            foreach ($combinations as $combination) {
                    $frequency = CiklikFrequency::getByIdAttribute($combination['id_attribute']);

                $variants[] = [
                    'name' => static::formatName($combination, $product),
                    'short_description' => Tools::truncate(strip_tags($product->description_short), 255),
                    'description' => Tools::truncate(strip_tags($product->description), 255),
                    'meta_title' => Tools::truncate($product->meta_title, 255),
                    'meta_description' => Tools::truncate($product->meta_description, 255),
                    'price' => $product->price + $combination['price'],
                    'tax' => (float) $product->tax_rate ? $product->tax_rate / 100 : 0,
                    'active' => (bool) $product->active,
                    'ref' => mb_strlen($combination['reference']) ? $combination['reference'] : $product->id . '-' . $combination['id_product_attribute'],
                    'external_id' => (string) $combination['id_product_attribute'],
                    'frequencies' => [
                        [
                            'interval' => $frequency['interval'],
                            'interval_count' => $frequency['interval_count'],
                        ],
                    ],
                ];
            }

            $context = Context::getContext();

            (new Subscribable($context->link))->push(['products' => $variants]);
        }
    }

    public static function formatName(array $combination, Product $product): string
    {
        $suffix = '';
        $suffixes = implode(',', array_filter(json_decode(Configuration::get(Ciklik::CONFIG_PRODUCT_NAME_SUFFIXES), true) ?? []));

        if (!empty($suffixes) && preg_match('/^(\d+,)*\d+$/', $suffixes)) {
            $query = new DbQuery();
            $query->select('al.name');
            $query->from('product_attribute', 'pa');
            $query->leftJoin('product_attribute_combination', 'pac', 'pac.`id_product_attribute` = pa.`id_product_attribute`');
            $query->leftJoin('attribute_lang', 'al', 'pac.`id_attribute` = al.`id_attribute`');
            $query->where('pa.`id_product_attribute` = "' . (int) $combination['id_product_attribute'] . '"');
            $query->where('al.`id_attribute` IN (SELECT id_attribute FROM `' . _DB_PREFIX_ . 'attribute` WHERE id_attribute_group IN (' . $suffixes . '))');
            $query->where('al.`id_lang` = "' . (int) Configuration::get('PS_LANG_DEFAULT') . '"');

            $attributes = Db::getInstance()->executeS($query);

            if (count($attributes)) {
                foreach ($attributes as $attribute) {
                    $suffix .= ' ' . $attribute['name'];
                }
            }
        }

        return Tools::truncate($product->name . $suffix, 255);
    }
}
