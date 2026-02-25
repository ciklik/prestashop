<?php

/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Helpers;

use Customer;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Class CustomerHelper
 */
class CustomerHelper
{
    /**
     * Assigne un groupe spécifique à un client.
     *
     * Cette fonction ajoute le groupe configuré dans Ciklik::CONFIG_CUSTOMER_GROUP_TO_ASSIGN
     * aux groupes existants du client. Si le client appartient déjà à ce groupe,
     * aucun changement n'est effectué.
     *
     * @param int $customer_id L'identifiant du client
     *
     * @return \Customer L'objet Customer mis à jour
     */
    public static function assignCustomerGroup(int $customer_id)
    {
        $customer = new \Customer($customer_id);
        $customerGroupToAssign = \Configuration::get(\Ciklik::CONFIG_CUSTOMER_GROUP_TO_ASSIGN);
        $existingGroups = $customer->getGroups();

        $newGroups = array_unique(array_merge($existingGroups, [$customerGroupToAssign]));
        $customer->updateGroup($newGroups);

        return $customer;
    }

    /**
     * Supprime un groupe spécifique d'un client.
     *
     * Cette fonction retire le groupe configuré dans Ciklik::CONFIG_CUSTOMER_GROUP_TO_ASSIGN
     * des groupes existants du client. Si le client n'appartient pas à ce groupe,
     * aucun changement n'est effectué.
     *
     * @param int $customer_id L'identifiant du client
     *
     * @return \Customer L'objet Customer mis à jour ou non
     */
    public static function removeCustomerGroup(int $customer_id)
    {
        $customer = new \Customer($customer_id);
        $customerGroupToAssign = \Configuration::get(\Ciklik::CONFIG_CUSTOMER_GROUP_TO_ASSIGN);
        $existingGroups = $customer->getGroups();
        $customer->updateGroup(array_diff($existingGroups, [$customerGroupToAssign]));

        return $customer;
    }
}
