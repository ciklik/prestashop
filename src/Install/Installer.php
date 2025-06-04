<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Install;

use Ciklik;
use Configuration;
use Db;
use Module;
use PrestaShop\Module\Ciklik\Managers\RelatedEntitiesManager;
use PrestaShop\Module\Ciklik\Sql\SqlQueries;
use Tools;
use WebserviceKey;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Installer
{
    /**
     * Module's installation entry point.
     *
     * @param Module $module
     *
     * @return bool
     */
    public function install(Module $module): bool
    {
        if (!$this->installDatabase()) {
            return false;
        }

        if (!$this->installFrequencyModeDatabase()) {
            return false;
        }

        if (!RelatedEntitiesManager::install()) {
            return false;
        }

        if (!$this->registerHooks($module)) {
            return false;
        }

        if (!$this->installConfiguration()) {
            return false;
        }

        if (!$this->installWebservice()) {
            return false;
        }

        return true;
    }

    /**
     * Module's uninstallation entry point.
     *
     * @return bool
     */
    public function uninstall(): bool
    {
        return RelatedEntitiesManager::uninstall()
            && $this->uninstallWebservice()
            && $this->uninstallDatabase()
            && $this->uninstallConfiguration()
            && $this->uninstallFrequencyModeDatabase();
    }

    /**
     * Install default module configuration
     *
     * @return bool
     */
    private function installConfiguration(): bool
    {
        return (bool) Configuration::updateGlobalValue(Ciklik::CONFIG_API_TOKEN, null)
            && (bool) Configuration::updateGlobalValue(Ciklik::CONFIG_MODE, 'LIVE')
            && (bool) Configuration::updateGlobalValue(Ciklik::CONFIG_HOST, null)
            && (bool) Configuration::updateGlobalValue(Ciklik::CONFIG_PRODUCT_NAME_SUFFIXES, json_encode([]))
            && (bool) Configuration::updateGlobalValue(Ciklik::CONFIG_WEBSERVICE_ID, '0')
            && (bool) Configuration::updateGlobalValue(Ciklik::CONFIG_DEBUG_LOGS_ENABLED, '0');
    }

    /**
     * Uninstall module configuration
     *
     * @return bool
     */
    private function uninstallConfiguration(): bool
    {
        return (bool) Configuration::deleteByName(Ciklik::CONFIG_API_TOKEN)
            && (bool) Configuration::deleteByName(Ciklik::CONFIG_MODE)
            && (bool) Configuration::deleteByName(Ciklik::CONFIG_HOST)
            && (bool) Configuration::deleteByName(Ciklik::CONFIG_PRODUCT_NAME_SUFFIXES)
            && (bool) Configuration::deleteByName(Ciklik::CONFIG_WEBSERVICE_ID)
            && (bool) Configuration::deleteByName(Ciklik::CONFIG_DEBUG_LOGS_ENABLED);
    }

    /**
     * Install the database modifications required for this module.
     *
     * @return bool
     */
    private function installDatabase(): bool
    {
        return $this->executeQueries(SqlQueries::installQueries());
    }

    /**
     * Uninstall database modifications.
     *
     * @return bool
     */
    private function uninstallDatabase(): bool
    {
        return $this->executeQueries(SqlQueries::uninstallQueries());
    }

    /**
     * Install the database modifications required for this module.
     *
     * @return bool
     */
    private function installWebservice(): bool
    {
        Configuration::updateValue('PS_WEBSERVICE', 1);

        $webservice = new WebserviceKey();
        $webservice->key = Tools::passwdGen(32);
        $webservice->description = 'Ciklik Webservice';
        $webservice->is_module = 1;
        $webservice->module_name = 'ciklik';
        $webservice->save();

        if ($webservice) {
            Configuration::updateValue(Ciklik::CONFIG_WEBSERVICE_ID, $webservice->id);

            $permissions = [
                'addresses' => ['GET' => 1, 'HEAD' => 1],
                'carts' => ['GET' => 1, 'HEAD' => 1],
                'countries' => ['GET' => 1, 'HEAD' => 1],
                'customers' => ['GET' => 1, 'HEAD' => 1],
                'order_carriers' => ['GET' => 1, 'HEAD' => 1],
                'order_cart_rules' => ['GET' => 1, 'HEAD' => 1],
                'order_details' => ['GET' => 1, 'HEAD' => 1],
                'order_histories' => ['GET' => 1, 'HEAD' => 1],
                'order_invoices' => ['GET' => 1, 'HEAD' => 1],
                'order_payments' => ['GET' => 1, 'HEAD' => 1],
                'order_slip' => ['GET' => 1, 'HEAD' => 1],
                'orders' => ['GET' => 1, 'HEAD' => 1],
                'product_option_values' => ['GET' => 1, 'HEAD' => 1],
                'product_options' => ['GET' => 1, 'HEAD' => 1],
                'products' => ['GET' => 1, 'HEAD' => 1],
            ];

            WebserviceKey::setPermissionForAccount($webservice->id, $permissions);

            return true;
        }

        return false;
    }

    /**
     * Uninstall database modifications.
     *
     * @return bool
     */
    private function uninstallWebservice(): bool
    {
        $webservice = new WebserviceKey(Configuration::get(Ciklik::CONFIG_WEBSERVICE_ID));

        return (bool) $webservice->delete()
            && (bool) Configuration::updateValue(Ciklik::CONFIG_WEBSERVICE_ID, '0');
    }

    /**
     * Register hooks for the module.
     *
     * @param Module $module
     *
     * @return bool
     */
    private function registerHooks(Module $module): bool
    {
        $prestashopVersion = _PS_VERSION_;

        if (version_compare($prestashopVersion, '8.0.0', '<')) {
            // Hooks pour presta 1.7.7
            $hooks = [
                'actionAfterUpdateProductFormHandler',
                'actionAttributeDelete',
                'actionAttributeSave',
                'actionGetProductPropertiesBefore',
                'actionObjectShopAddAfter',
                // 'actionPresentPaymentOptions',
                'actionProductDelete',
                'displayAdminOrderMainBottom',
                'displayAttributeForm',
                'displayCustomerAccount',
                'displayOrderConfirmation',
                'displayOrderDetail',
                'displayPaymentReturn',
                'displayPDFInvoice',
                'moduleRoutes',
                'paymentOptions',
                'actionObjectProductUpdateAfter',
                'actionObjectProductAddAfter',
                //après le mode frequenty
                'actionFrontControllerSetMedia',
                'displayAdminProductsExtra',
                'displayProductActions',
                'actionCartUpdateQuantityBefore',
                'displayShoppingCart',
                'actionAuthentication',
            ];
        } else {
            // Hooks pour presta 8+
            $hooks = [
                'actionAfterUpdateProductFormHandler',
                'actionAttributeDelete',
                'actionAttributeSave',
                'actionGetProductPropertiesBefore',
                'actionObjectShopAddAfter',
                'actionPresentPaymentOptions',
                'actionProductDelete',
                'displayAdminOrderMainBottom',
                'displayAttributeForm',
                'displayCustomerAccount',
                'displayOrderConfirmation',
                'displayOrderDetail',
                'displayPaymentReturn',
                'displayPDFInvoice',
                'moduleRoutes',
                'paymentOptions',
                //après le mode frequenty
                'actionFrontControllerSetMedia',
                'displayAdminProductsExtra',
                'displayProductActions',
                'actionCartUpdateQuantityBefore',
                'displayShoppingCart',
                'actionAuthentication',
            ];
        }

        return (bool) $module->registerHook($hooks);
    }

    /**
     * A helper that executes multiple database queries.
     *
     * @param array $queries
     *
     * @return bool
     */
    private function executeQueries(array $queries): bool
    {
        foreach ($queries as $query) {
            if (!Db::getInstance()->execute($query)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Install the database modifications required for frequency mode.
     *
     * @return bool
     */
    private function installFrequencyModeDatabase(): bool
    {
        return $this->executeQueries(SqlQueries::installFrequencyModeDatabase());
    }

    /**
     * Uninstall the database modifications required for frequency mode.
     *
     * @return bool
     */
    private function uninstallFrequencyModeDatabase(): bool
    {
        return $this->executeQueries(SqlQueries::uninstallFrequencyModeDatabase());
    }
}
