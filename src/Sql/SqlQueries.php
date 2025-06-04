<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Sql;

if (!defined('_PS_VERSION_')) {
    exit;
}

class SqlQueries
{
    /**
     * Install database queries.
     *
     * @return array
     */
    public static function installQueries(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'ciklik_subscribables` (
                `id_subscribable` int(11) unsigned NOT NULL auto_increment,
                `id_product` int(11) unsigned NOT NULL,
                PRIMARY KEY(`id_subscribable`),
                UNIQUE KEY `id_product` (`id_product`)
                ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;',

            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'ciklik_frequencies` (
                `id_frequency` int(11) unsigned NOT NULL auto_increment,
                `id_attribute` int(11) unsigned NOT NULL,
                `interval` varchar(16) NOT NULL,
                `interval_count` tinyint(3) NOT NULL,
                PRIMARY KEY(`id_frequency`),
                UNIQUE KEY `id_attribute` (`id_attribute`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;',

            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'ciklik_customers` (
                `id_ciklik_customer` int(11) unsigned NOT NULL auto_increment,
                `id_customer` int(11) unsigned NOT NULL,
                `ciklik_uuid` varchar(255) NOT NULL,
                `metadata` json DEFAULT NULL,
                PRIMARY KEY(`id_ciklik_customer`),
                UNIQUE KEY `id_customer` (`id_customer`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;',
        ];
    }

    /**
     * Uninstall database queries.
     *
     * @return array
     */
    public static function uninstallQueries(): array
    {
        return [
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'ciklik_subscribables`',
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'ciklik_frequencies`',
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'ciklik_customers`',
        ];
    }

    public static function installFrequencyModeDatabase(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'ciklik_frequency` (
                `id_frequency` int(10) unsigned NOT NULL AUTO_INCREMENT,
                `name` varchar(128) NOT NULL,
                `interval` varchar(20) NOT NULL,
                `interval_count` int(10) unsigned NOT NULL,
                `discount_percent` decimal(5,2)",
                `discount_price` decimal(20,6)",
                PRIMARY KEY (`id_frequency`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;',
            
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'ciklik_product_frequency` (
                `id_product` int(10) unsigned NOT NULL,
                `id_frequency` int(10) unsigned NOT NULL,
                PRIMARY KEY (`id_product`,`id_frequency`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;',

            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'ciklik_items_frequency` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `cart_id` int(11) DEFAULT NULL,
                `order_id` int(11) DEFAULT NULL,
                `frequency_id` int(11) NOT NULL,
                `product_id` int(11) NOT NULL,
                `id_product_attribute` int(11) DEFAULT NULL,
                `customer_id` int(11) DEFAULT NULL,
                `guest_id` int(11) DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `cart_id` (`cart_id`),
                KEY `order_id` (`order_id`),
                KEY `product_id` (`product_id`),
                KEY `customer_id` (`customer_id`),
                KEY `guest_id` (`guest_id`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;'
        ];
    }

    public static function uninstallFrequencyModeDatabase(): array
    {
        return [
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'ciklik_frequency`',
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'ciklik_product_frequency`',
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'ciklik_items_frequency`',
        ];
    }
    
}