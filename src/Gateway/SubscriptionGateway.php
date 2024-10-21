<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Gateway;

use PrestaShop\Module\Ciklik\Helpers\CustomerHelper;
use PrestaShop\Module\Ciklik\Managers\CiklikCustomer;
use Tools;
use Configuration;

if (!defined('_PS_VERSION_')) {
    exit;
}

class SubscriptionGateway extends AbstractGateway implements EntityGateway
{

    public function post()
    {
        // Vérifie si la requête est de type mise à jour du statut d'abonnement
        if (Tools::getValue('request_type') === 'subscription_status_update') {
            
            // Récupère le client Ciklik à partir de l'UUID fourni
            $ciklik_customer = CiklikCustomer::getByCiklikUuid((string) Tools::getValue('customer_uuid'));
         
            // Si l'abonnement est activé et que l'assignation de groupe est activée dans la configuration
            if (Tools::getValue('active') === '1' && Configuration::get(\Ciklik::CONFIG_ENABLE_CUSTOMER_GROUP_ASSIGNMENT)) {
                // Assigne le groupe spécifique au client
                CustomerHelper::assignCustomerGroup((int) $ciklik_customer['id_customer']);
            }

            // Si l'abonnement est désactivé et que l'assignation de groupe est activée dans la configuration
            if (Tools::getValue('active') === '0' && Configuration::get(\Ciklik::CONFIG_ENABLE_CUSTOMER_GROUP_ASSIGNMENT)) {
                // Retire le groupe spécifique du client
                CustomerHelper::removeCustomerGroup((int) $ciklik_customer['id_customer']);
            }
    
           // Renvoie une réponse de succès
           return (new Response())->setBody([])->sendCreated();
        }
        
        return (new Response())->setBody([])->sendBadRequest();
    }

    public function get()
    {
        return (new Response())->setBody([])->sendCreated();
    }
}
