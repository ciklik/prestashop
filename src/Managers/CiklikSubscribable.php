<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Managers;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CiklikSubscribable
{
    /** Préfixe de la clé posée quand l'envoi d'un produit à Ciklik échoue, suivi de la boutique et du produit */
    const SYNC_FAILED_KEY = 'CIKLIK_SYNC_FAILED_';

    /**
     * Enregistre le produit comme abonnable et envoie ses déclinaisons abonnement à Ciklik
     *
     * @return array|null Résultat de l'envoi (sent, failed), null si le produit n'en a aucune
     */
    public static function handle(int $id_product)
    {
        $combinations = CiklikCombination::get($id_product);

        if (count($combinations)) {
            if (!static::isSubscribable($id_product)) {
                static::create($id_product);
            }

            try {
                $result = CiklikSubscribableVariant::pushToCiklik($id_product, $combinations);
            } catch (\Throwable $e) {
                static::markSyncFailed($id_product, true);
                throw $e;
            }
            static::markSyncFailed($id_product, $result['failed'] > 0);

            return $result;
        }

        static::deleteByIdProduct($id_product);

        return null;
    }

    /**
     * Le dernier envoi du produit à Ciklik a échoué (en tout ou partie) : la fiche propose « Renvoyer à Ciklik »
     */
    public static function hasSyncFailed(int $id_product): bool
    {
        return (bool) \Configuration::getGlobalValue(static::syncFailedKey($id_product));
    }

    private static function markSyncFailed(int $id_product, bool $failed)
    {
        if ($failed) {
            \Configuration::updateGlobalValue(static::syncFailedKey($id_product), 1);
        } elseif (static::hasSyncFailed($id_product)) {
            \Configuration::deleteByName(static::syncFailedKey($id_product));
        }
    }

    /**
     * L'envoi part avec la configuration de la boutique du contexte : l'échec est noté par boutique
     */
    private static function syncFailedKey(int $id_product): string
    {
        return self::SYNC_FAILED_KEY . (int) \Context::getContext()->shop->id . '_' . $id_product;
    }

    public static function create(int $id_product): int
    {
        \Db::getInstance()->execute('
            INSERT INTO `' . _DB_PREFIX_ . 'ciklik_subscribables` (`id_product`) 
            VALUES (' . $id_product . ')
        ');

        return (int) \Db::getInstance()->Insert_ID();
    }

    public static function deleteByIdProduct(int $id_product): bool
    {
        static::markSyncFailed($id_product, false);

        return \Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . 'ciklik_subscribables` WHERE `id_product` = ' . $id_product);
    }

    public static function isSubscribable(int $id_product): bool
    {
        return (int) \Db::getInstance()->getValue('
            SELECT `id_subscribable`
            FROM `' . _DB_PREFIX_ . 'ciklik_subscribables`
            WHERE `id_product` = ' . $id_product . '
        ') > 0;
    }

    public static function cartHasSubscribable(\Cart $cart): bool
    {
        $products = $cart->getProducts();

        if (\Configuration::get(\Ciklik::CONFIG_USE_FREQUENCY_MODE)) {
            foreach ($products as $product) {
                // Vérifie si le produit a des personnalisations pour l'abonnement
                $frequencyData = CiklikItemFrequency::getByCartAndProduct($cart->id, $product['id_product']);

                if ($frequencyData && (int) $frequencyData['frequency_id'] > 0) {
                    return true;
                }
            }

            return false;
        }

        foreach ($products as $product) {
            // Fallback sur le comportement existant pour la rétrocompatibilité
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
