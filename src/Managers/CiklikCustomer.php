<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Managers;

use Db;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CiklikCustomer
{
    public static function save(int $id_customer,
        string $ciklik_uuid,
        array $metadata = []): int
    {
        if (is_array($customer = self::getByIdCustomer($id_customer)) && array_key_exists('id_ciklik_customer', $customer)) {
            Db::getInstance()->update('ciklik_customers', ['ciklik_uuid' => $ciklik_uuid, 'metadata' => json_encode($metadata)], '`id_customer` = ' . $id_customer);

            return $customer['id_ciklik_customer'];
        }

        Db::getInstance()->execute('
            INSERT INTO `' . _DB_PREFIX_ . 'ciklik_customers` (`id_customer`, `ciklik_uuid`, `metadata`) 
            VALUES (' . $id_customer . ', \'' . pSQL($ciklik_uuid) . '\', \'' . json_encode($metadata) . '\')
        ');

        return (int) Db::getInstance()->Insert_ID();
    }

    public static function getByIdCustomer(int $id_customer): array
    {
        return (array) Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'ciklik_customers` WHERE `id_customer` = ' . $id_customer);
    }

    public static function deleteByIdCustomer(int $id_customer): bool
    {
        return Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . 'ciklik_customers` WHERE `id_customer` = ' . $id_customer);
    }

    public static function getByCiklikUuid(string $ciklik_uuid): array
    {
        return (array) Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'ciklik_customers` WHERE `ciklik_uuid` = "' . pSQL($ciklik_uuid) . '"');
    }
}
