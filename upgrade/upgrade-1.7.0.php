<?php

/**
 * @author    Ciklik SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

use PrestaShop\Module\Ciklik\Ciklik;

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_7_0($module)
{
    // Création des tables
    // $sql = [
    //     'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'ciklik_frequency` (
    //         `id_frequency` int(10) unsigned NOT NULL AUTO_INCREMENT,
    //         `name` varchar(128) NOT NULL,
    //         `interval` varchar(20) NOT NULL,
    //         `interval_count` int(10) unsigned NOT NULL,
    //         `discount_percent` decimal(5,2)",
    //         `discount_price` decimal(20,6)",
    //         PRIMARY KEY (`id_frequency`)
    //     ) ENGINE=InnoDB DEFAULT CHARSET=utf8;',

    //     'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'ciklik_product_frequency` (
    //         `id_product` int(10) unsigned NOT NULL,
    //         `id_frequency` int(10) unsigned NOT NULL,
    //         PRIMARY KEY (`id_product`,`id_frequency`)
    //     ) ENGINE=InnoDB DEFAULT CHARSET=utf8;',

    //     'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'ciklik_items_frequency` (
    //         `id` int(11) NOT NULL AUTO_INCREMENT,
    //         `cart_id` int(11) DEFAULT NULL,
    //         `order_id` int(11) DEFAULT NULL,
    //         `frequency_id` int(11) NOT NULL,
    //         `product_id` int(11) NOT NULL,
    //         `id_product_attribute` int(11) DEFAULT NULL,
    //         `customer_id` int(11) DEFAULT NULL,
    //         `guest_id` int(11) DEFAULT NULL,
    //         PRIMARY KEY (`id`),
    //         KEY `cart_id` (`cart_id`),
    //         KEY `order_id` (`order_id`),
    //         KEY `product_id` (`product_id`),
    //         KEY `customer_id` (`customer_id`),
    //         KEY `guest_id` (`guest_id`)
    //     ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;'
    // ];

    // foreach ($sql as $query) {
    //     if (!Db::getInstance()->execute($query)) {
    //         return false;
    //     }
    // }

    // Ajout des configuration
    // \Configuration::updateValue(Ciklik::CONFIG_USE_FREQUENCY_MODE, '0');

    // Enregistrement des nouveaux hooks
    // $hooks = [
    //     'actionFrontControllerSetMedia',
    //     'displayAdminProductsExtra',
    //     'displayProductActions',
    //     'actionCartUpdateQuantityBefore',
    //     'displayShoppingCart',
    //     'actionAuthentication'
    // ];

    // foreach ($hooks as $hookName) {
    //     if (!$module->registerHook($hookName)) {
    //         // Log l'erreur mais ne fait pas échouer la mise à jour
    //         \PrestaShopLogger::addLog(
    //             'Failed to register hook: ' . $hookName . ' during upgrade',
    //             2, // Warning level
    //             null,
    //             'Ciklik',
    //             null,
    //             true
    //         );
    //     }
    // }

    // Migration des fréquences existantes
    // $query = new DbQuery();
    // $query->select('a.id_attribute, a.id_attribute_group, al.name');
    // $query->from('attribute', 'a');
    // $query->leftJoin('attribute_lang', 'al', 'al.id_attribute = a.id_attribute');
    // $query->where('a.id_attribute_group = ' . (int)Configuration::get(Ciklik::CONFIG_FREQUENCIES_ATTRIBUTE_GROUP_ID));

    // $frequencies = Db::getInstance()->executeS($query);

    // foreach ($frequencies as $frequency) {
    //     // Extraction de l'intervalle et du compte depuis le nom
    //     if (preg_match('/(\d+)\s+(\w+)/', $frequency['name'], $matches)) {
    //         $count = (int)$matches[1];
    //         $interval = strtolower($matches[2]);

    //         // Conversion en format standard
    //         switch ($interval) {
    //             case 'semaine':
    //             case 'semaines':
    //                 $interval = 'week';
    //                 break;
    //             case 'mois':
    //                 $interval = 'month';
    //                 break;
    //             case 'trimestre':
    //             case 'trimestres':
    //                 $interval = 'month';
    //                 $count *= 3;
    //                 break;
    //             default:
    //                 continue 2;
    //         }

    //         // Insertion dans la nouvelle table
    //         Db::getInstance()->insert('ciklik_frequency', [
    //             'name' => $frequency['name'],
    //             'interval' => $interval,
    //             'interval_count' => $count,
    //         ]);
    //     }
    // }

    return true;
}
