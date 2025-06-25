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

    /*
    * Les méthodes suivantes sont utilisées pour le mode fréquence
    */

    public static function getFrequenciesForFrequencyMode()
    {
       // Récupère les fréquences disponibles
       $query = new DbQuery();
       $query->select('*');
       $query->from('ciklik_frequency');
       $frequencies = Db::getInstance()->executeS($query);

       return $frequencies;
    }

    public static function getFrequenciesForProduct(int $id_product)
    {
        $query = new DbQuery();
        $query->select('f.*');
        $query->from('ciklik_frequency', 'f');
        $query->innerJoin('ciklik_product_frequency', 'pf', 'pf.id_frequency = f.id_frequency');
        $query->where('pf.id_product = ' . $id_product);
        
        return Db::getInstance()->executeS($query);
    }

    public static function getFrequencyById(int $id_frequency)
    {
        return Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'ciklik_frequency` WHERE `id_frequency` = ' . $id_frequency);
    }

    public static function getAll()
    {
        $query = new DbQuery();
        $query->select('*');
        $query->from('ciklik_frequency');
        $query->orderBy('name ASC');
        
        return Db::getInstance()->executeS($query);
    }

    /**
     * Sauvegarde une fréquence avec validation et sécurisation
     * 
     * @param array $frequency Données de la fréquence
     * @return int|bool ID de la fréquence créée ou false en cas d'erreur
     * @throws InvalidArgumentException Si les données sont invalides
     */
    public static function saveFrequency(array $frequency)
    {
        // Validation des champs requis
        $requiredFields = ['name', 'interval', 'interval_count'];
        foreach ($requiredFields as $field) {
            if (empty($frequency[$field])) {
                throw new \InvalidArgumentException("Le champ '{$field}' est requis");
            }
        }

        // Validation du nom
        if (!is_string($frequency['name']) || strlen(trim($frequency['name'])) === 0) {
            throw new \InvalidArgumentException("Le nom doit être une chaîne non vide");
        }
        
        if (strlen($frequency['name']) > 128) {
            throw new \InvalidArgumentException("Le nom ne peut pas dépasser 128 caractères");
        }

        // Validation de l'intervalle
        $allowedIntervals = ['day', 'week', 'month', 'year'];
        if (!in_array($frequency['interval'], $allowedIntervals, true)) {
            throw new \InvalidArgumentException("L'intervalle doit être l'un des suivants: " . implode(', ', $allowedIntervals));
        }

        // Validation du compteur d'intervalle
        if (!is_numeric($frequency['interval_count']) || (int)$frequency['interval_count'] <= 0) {
            throw new \InvalidArgumentException("Le compteur d'intervalle doit être un nombre entier positif");
        }

        if ((int)$frequency['interval_count'] > 999) {
            throw new \InvalidArgumentException("Le compteur d'intervalle ne peut pas dépasser 999");
        }

        // Validation des champs optionnels de réduction
        if (isset($frequency['discount_percent'])) {
            if (!is_numeric($frequency['discount_percent']) || 
                (float)$frequency['discount_percent'] < 0 || 
                (float)$frequency['discount_percent'] > 100) {
                throw new \InvalidArgumentException("Le pourcentage de réduction doit être entre 0 et 100");
            }
        }

        if (isset($frequency['discount_price'])) {
            if (!is_numeric($frequency['discount_price']) || (float)$frequency['discount_price'] < 0) {
                throw new \InvalidArgumentException("Le prix de réduction doit être un nombre positif");
            }
        }



        // Préparation des données sécurisées
        $safeData = [
            'name' => pSQL(trim($frequency['name'])),
            'interval' => pSQL($frequency['interval']),
            'interval_count' => (int)$frequency['interval_count'],
            'discount_percent' => isset($frequency['discount_percent']) ? (float)$frequency['discount_percent'] : null,
            'discount_price' => isset($frequency['discount_price']) ? (float)$frequency['discount_price'] : null
        ];

        try {
            // Mise à jour ou insertion
            if (isset($frequency['id_frequency']) && (int)$frequency['id_frequency'] > 0) {
                // Mise à jour
                $result = Db::getInstance()->update('ciklik_frequency', $safeData, 'id_frequency = ' . (int)$frequency['id_frequency']);
                return $result ? (int)$frequency['id_frequency'] : false;
            } else {
                // Insertion
                $result = Db::getInstance()->insert('ciklik_frequency', $safeData);
                return $result ? (int)Db::getInstance()->Insert_ID() : false;
            }
        } catch (\Exception $e) {
            // Log l'erreur pour débuggage
            \PrestaShopLogger::addLog(
                'Erreur lors de la sauvegarde de la fréquence: ' . $e->getMessage(),
                3, // Erreur
                null,
                'CiklikFrequency',
                null,
                true
            );
            return false;
        }
    }

    /**
     * Supprime une fréquence
     * 
     * @param int $id_frequency ID de la fréquence à supprimer
     * @return bool True si supprimée avec succès, false sinon
     */
    public static function deleteFrequency(int $id_frequency): bool
    {
        if ($id_frequency <= 0) {
            return false;
        }

        return Db::getInstance()->delete('ciklik_frequency', 'id_frequency = ' . (int)$id_frequency);
    }

}
