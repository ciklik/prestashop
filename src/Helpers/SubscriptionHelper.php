<?php
/**
 * @author    Ciklik SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Helpers;

use Db;
use DbQuery;

if (!defined('_PS_VERSION_')) {
    exit;
}

class SubscriptionHelper
{
    /**
     * Vérifie si l'abonnement est activé pour un produit
     */
    public static function isSubscriptionEnabled(int $idProduct): bool
    {
        $query = new DbQuery();
        $query->select('COUNT(*)');
        $query->from('ciklik_product_frequency');
        $query->where('id_product = ' . (int)$idProduct);
        return (bool)Db::getInstance()->getValue($query);
    }

    /**
     * Récupère les fréquences disponibles pour un produit
     */
    public static function getProductFrequencies(int $idProduct): array
    {
        $query = new DbQuery();
        $query->select('f.*');
        $query->from('ciklik_frequency', 'f');
        $query->innerJoin('ciklik_product_frequency', 'pf', 'pf.id_frequency = f.id_frequency');
        $query->where('pf.id_product = ' . (int)$idProduct);
        
        return Db::getInstance()->executeS($query);
    }
} 