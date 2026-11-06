<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Managers;

use PrestaShop\Module\Ciklik\Api\Subscribable;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CiklikSubscribableVariant
{
    /** Variantes envoyées par requête : un gros produit ne doit pas dépasser le délai du client HTTP */
    const BATCH_SIZE = 100;

    /**
     * Envoie à Ciklik les déclinaisons abonnement d'un produit, par lots
     *
     * @return array sent, failed (nombre de variantes)
     */
    public static function pushToCiklik(int $id_product, array $combinations = []): array
    {
        if (!count($combinations)) {
            return ['sent' => 0, 'failed' => 0];
        }

        $variants = [];
        $product = new \Product($id_product, true, \Configuration::get('PS_LANG_DEFAULT'));

        foreach ($combinations as $combination) {
            $frequency = CiklikFrequency::getByIdAttribute($combination['id_attribute']);

            $variants[] = [
                'name' => static::formatName($combination, $product),
                'short_description' => \Tools::truncate(strip_tags($product->description_short), 255),
                'description' => \Tools::truncate(strip_tags($product->description), 255),
                'meta_title' => \Tools::truncate($product->meta_title, 255),
                'meta_description' => \Tools::truncate($product->meta_description, 255),
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

        $api = new Subscribable(\Context::getContext()->link);

        return static::sendInBatches($variants, function (array $batch) use ($api) {
            return $api->push(['products' => $batch]);
        });
    }

    /**
     * Une requête par lot de BATCH_SIZE variantes, à la suite. Un lot refusé est journalisé avec
     * ses external_id et n'empêche pas les suivants ; l'envoi est idempotent (external_id), un
     * nouvel enregistrement de la fiche le rejoue.
     *
     * @param callable $send Envoi d'un lot, rend la réponse formatée de l'API (clé status)
     *
     * @return array sent, failed (nombre de variantes)
     */
    public static function sendInBatches(array $variants, callable $send): array
    {
        $result = ['sent' => 0, 'failed' => 0];

        foreach (array_chunk($variants, self::BATCH_SIZE) as $batch) {
            $response = $send($batch);
            if (is_array($response) && !empty($response['status'])) {
                $result['sent'] += count($batch);
                continue;
            }

            $result['failed'] += count($batch);
            \PrestaShopLogger::addLog(
                'Ciklik - envoi des variantes refusé (' . (is_array($response) && isset($response['httpCode']) ? (int) $response['httpCode'] : 0) . ') : '
                . implode(',', array_column($batch, 'external_id')),
                3
            );
        }

        return $result;
    }

    public static function formatName(array $combination, \Product $product): string
    {
        $suffix = '';
        $suffixes = implode(',', array_filter(json_decode(\Configuration::get(\Ciklik::CONFIG_PRODUCT_NAME_SUFFIXES), true) ?? []));

        if (!empty($suffixes) && preg_match('/^(\d+,)*\d+$/', $suffixes)) {
            $query = new \DbQuery();
            $query->select('al.name');
            $query->from('product_attribute', 'pa');
            $query->leftJoin('product_attribute_combination', 'pac', 'pac.`id_product_attribute` = pa.`id_product_attribute`');
            $query->leftJoin('attribute_lang', 'al', 'pac.`id_attribute` = al.`id_attribute`');
            $query->where('pa.`id_product_attribute` = "' . (int) $combination['id_product_attribute'] . '"');
            $query->where('al.`id_attribute` IN (SELECT id_attribute FROM `' . _DB_PREFIX_ . 'attribute` WHERE id_attribute_group IN (' . $suffixes . '))');
            $query->where('al.`id_lang` = "' . (int) \Configuration::get('PS_LANG_DEFAULT') . '"');

            $attributes = \Db::getInstance()->executeS($query);

            if (count($attributes)) {
                foreach ($attributes as $attribute) {
                    $suffix .= ' ' . $attribute['name'];
                }
            }
        }

        return \Tools::truncate($product->name . $suffix, 255);
    }
}
