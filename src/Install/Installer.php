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
use Language;
use Module;
use PrestaShop\Module\Ciklik\Managers\RelatedEntitiesManager;
use PrestaShop\Module\Ciklik\Sql\SqlQueries;
use Tab;
use Tools;
use WebserviceKey;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Installer
{
    /**
     * Point d'entrée pour l'installation du module
     *
     * @param Module $module Instance du module
     *
     * @return bool True si l'installation a réussi, false sinon
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

        if (!$this->installAdminTabs($module)) {
            return false;
        }

        return true;
    }

    /**
     * Point d'entrée pour la désinstallation du module
     *
     * @return bool True si la désinstallation a réussi, false sinon
     */
    public function uninstall(): bool
    {
        return RelatedEntitiesManager::uninstall()
            && $this->uninstallWebservice()
            && $this->uninstallDatabase()
            && $this->uninstallConfiguration()
            && $this->uninstallFrequencyModeDatabase()
            && $this->uninstallAdminTabs();
    }

    /**
     * Installe la configuration par défaut du module
     *
     * @return bool True si l'installation a réussi, false sinon
     */
    private function installConfiguration(): bool
    {
        return (bool) Configuration::updateGlobalValue(Ciklik::CONFIG_API_TOKEN, null)
            && (bool) Configuration::updateGlobalValue(Ciklik::CONFIG_MODE, 'LIVE')
            && (bool) Configuration::updateGlobalValue(Ciklik::CONFIG_HOST, null)
            && (bool) Configuration::updateGlobalValue(Ciklik::CONFIG_PRODUCT_NAME_SUFFIXES, json_encode([]))
            && (bool) Configuration::updateGlobalValue(Ciklik::CONFIG_WEBSERVICE_ID, '0')
            && (bool) Configuration::updateGlobalValue(Ciklik::CONFIG_DEBUG_LOGS_ENABLED, '0')
            && (bool) Configuration::updateGlobalValue(Ciklik::CONFIG_ENABLE_ORDER_THREAD, '1')
            && (bool) Configuration::updateGlobalValue(Ciklik::CONFIG_ORDER_THREAD_STATUS, 'open');
    }

    /**
     * Désinstalle la configuration du module
     *
     * @return bool True si la désinstallation a réussi, false sinon
     */
    private function uninstallConfiguration(): bool
    {
        return (bool) Configuration::deleteByName(Ciklik::CONFIG_API_TOKEN)
            && (bool) Configuration::deleteByName(Ciklik::CONFIG_MODE)
            && (bool) Configuration::deleteByName(Ciklik::CONFIG_HOST)
            && (bool) Configuration::deleteByName(Ciklik::CONFIG_PRODUCT_NAME_SUFFIXES)
            && (bool) Configuration::deleteByName(Ciklik::CONFIG_WEBSERVICE_ID)
            && (bool) Configuration::deleteByName(Ciklik::CONFIG_DEBUG_LOGS_ENABLED)
            && (bool) Configuration::deleteByName(Ciklik::CONFIG_ENABLE_ORDER_THREAD)
            && (bool) Configuration::deleteByName(Ciklik::CONFIG_ORDER_THREAD_STATUS);
    }

    /**
     * Installe les modifications de base de données requises pour ce module
     *
     * @return bool True si l'installation a réussi, false sinon
     */
    private function installDatabase(): bool
    {
        return $this->executeQueries(SqlQueries::installQueries());
    }

    /**
     * Désinstalle les modifications de base de données
     *
     * @return bool True si la désinstallation a réussi, false sinon
     */
    private function uninstallDatabase(): bool
    {
        return $this->executeQueries(SqlQueries::uninstallQueries());
    }

    /**
     * Installe le webservice requis pour ce module
     *
     * @return bool True si l'installation a réussi, false sinon
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
     * Désinstalle le webservice
     *
     * @return bool True si la désinstallation a réussi, false sinon
     */
    private function uninstallWebservice(): bool
    {
        $webservice = new WebserviceKey(Configuration::get(Ciklik::CONFIG_WEBSERVICE_ID));

        return (bool) $webservice->delete()
            && (bool) Configuration::updateValue(Ciklik::CONFIG_WEBSERVICE_ID, '0');
    }

    /**
     * Enregistre les hooks pour le module
     *
     * @param Module $module Instance du module
     *
     * @return bool True si l'enregistrement a réussi, false sinon
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
                'actionProductUpdate',
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
                'actionProductUpdate',
            ];
        }

        return (bool) $module->registerHook($hooks);
    }

    /**
     * Méthode helper qui exécute plusieurs requêtes de base de données
     *
     * @param array $queries Tableau de requêtes SQL à exécuter
     *
     * @return bool True si toutes les requêtes ont réussi, false sinon
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
     * Installe les modifications de base de données requises pour le mode fréquence
     *
     * @return bool True si l'installation a réussi, false sinon
     */
    private function installFrequencyModeDatabase(): bool
    {
        return $this->executeQueries(SqlQueries::installFrequencyModeDatabase());
    }

    /**
     * Désinstalle les modifications de base de données requises pour le mode fréquence
     *
     * @return bool True si la désinstallation a réussi, false sinon
     */
    private function uninstallFrequencyModeDatabase(): bool
    {
        return $this->executeQueries(SqlQueries::uninstallFrequencyModeDatabase());
    }


    /**
     * Installe ou met à jour les onglets d'administration du module
     * 
     * @param Module $module Instance du module
     * @return bool True si l'installation a réussi, false sinon
     */
    public function installAdminTabs(Module $module): bool
    {
        // Recherche ou création de l'onglet parent (AdminConfigureCiklik)
        $parentTabId = Tab::getIdFromClassName('AdminConfigureCiklik');
        if (!$parentTabId) {
            // Si l'onglet parent n'existe pas, le créer
            $parentTab = new Tab();
            $parentTab->active = 1;
            $parentTab->class_name = 'AdminConfigureCiklik';
            $parentTab->name = [];
            foreach (Language::getLanguages(true) as $lang) {
                $parentTab->name[$lang['id_lang']] = 'Ciklik';
            }
            $parentTab->id_parent = (int) Tab::getIdFromClassName('IMPROVE');
            $parentTab->module = $module->name;
            $parentTab->icon = 'extension';
            
            if (!$parentTab->add()) {
                return false;
            }
            $parentTabId = $parentTab->id;
        } else {
            // Mise à jour de l'onglet parent existant pour s'assurer qu'il est actif et bien configuré
            $parentTab = new Tab($parentTabId);
            $parentTab->active = 1; // S'assurer qu'il est actif
            $parentTab->icon = 'extension';
            // S'assurer que le parent est bien "IMPROVE" (menu Améliorer)
            $improveTabId = Tab::getIdFromClassName('IMPROVE');
            if ($improveTabId && $parentTab->id_parent != $improveTabId) {
                $parentTab->id_parent = $improveTabId;
            }
            // Mettre à jour les noms dans toutes les langues
            $parentTab->name = [];
            foreach (Language::getLanguages(true) as $lang) {
                $parentTab->name[$lang['id_lang']] = 'Ciklik';
            }
            $parentTab->save();
        }

        // Onglet pour la gestion des fréquences
        // Toujours créer l'onglet, mais le rendre actif/inactif selon le mode fréquence
        $isFrequencyModeEnabled = Configuration::get('CIKLIK_FREQUENCY_MODE');
        $frequenciesTab = $this->updateOrCreateTab(
            'AdminCiklikFrequencies',
            'Frequency Management',
            'Gestion des Fréquences',
            $parentTabId,
            $module,
            $isFrequencyModeEnabled ? 1 : 0
        );
        
        if (!$frequenciesTab) {
            return false;
        }

        // Onglet pour les abonnements et commandes
        // Supprimer l'onglet existant s'il existe pour éviter les conflits
        $subscriptionsOrdersTabId = Tab::getIdFromClassName('AdminCiklikSubscriptionsOrders');
        if ($subscriptionsOrdersTabId) {
            $oldTab = new Tab($subscriptionsOrdersTabId);
            $oldTab->delete();
        }

        $subscriptionsOrdersTab = $this->createMultilingualTab(
            'AdminCiklikSubscriptionsOrders',
            'Subscriptions and Orders',
            'Abonnements et Commandes',
            $parentTabId,
            $module,
            1
        );
        
        if (!$subscriptionsOrdersTab) {
            return false;
        }

        // Dans PrestaShop, les permissions sont créées automatiquement lors de Tab::add()
        // pour le profil super-admin. Les autres profils héritent généralement des permissions du parent.
        // S'assurer que tous les onglets sont correctement configurés et actifs
        $frequenciesTab->active = $isFrequencyModeEnabled ? 1 : 0;
        $frequenciesTab->id_parent = $parentTabId;
        $frequenciesTab->save();
        
        $subscriptionsOrdersTab->active = 1;
        $subscriptionsOrdersTab->id_parent = $parentTabId;
        $subscriptionsOrdersTab->save();
        
        // S'assurer que l'onglet parent est actif
        $parentTab = new Tab($parentTabId);
        $parentTab->active = 1;
        $parentTab->save();

        return true;
    }

    /**
     * Met à jour la visibilité de l'onglet des fréquences selon le mode fréquence
     * 
     * @return bool True si la mise à jour a réussi, false sinon
     */
    public function updateFrequenciesTabVisibility(): bool
    {
        $frequenciesTabId = Tab::getIdFromClassName('AdminCiklikFrequencies');
        $isFrequencyModeEnabled = Configuration::get('CIKLIK_FREQUENCY_MODE');
        
        if ($frequenciesTabId) {
            $frequenciesTab = new Tab($frequenciesTabId);
            $frequenciesTab->active = $isFrequencyModeEnabled ? 1 : 0;
            $result = $frequenciesTab->save();
            return $result !== false;
        }
        
        return true; // L'onglet n'existe pas encore, sera créé lors de la prochaine mise à jour du module
    }

    /**
     * Crée un onglet avec des noms multilingues (anglais/français)
     * 
     * @param string $className Nom de la classe du contrôleur
     * @param string $nameEn Nom en anglais
     * @param string $nameFr Nom en français
     * @param int $parentTabId ID de l'onglet parent
     * @param Module $module Instance du module
     * @param int $active État actif (1) ou inactif (0)
     * @return Tab|false Instance de Tab créée ou false en cas d'erreur
     */
    private function createMultilingualTab($className, $nameEn, $nameFr, $parentTabId, Module $module, $active = 1)
    {
        $tab = new Tab();
        $tab->active = $active;
        $tab->class_name = $className;
        $tab->name = [];
        
        foreach (Language::getLanguages(true) as $lang) {
            $langIso = strtolower($lang['iso_code']);
            if ($langIso === 'en' || $langIso === 'en-us' || $langIso === 'en-gb') {
                $tab->name[$lang['id_lang']] = $nameEn;
            } else {
                $tab->name[$lang['id_lang']] = $nameFr;
            }
        }
        
        $tab->id_parent = $parentTabId;
        $tab->module = $module->name;
        
        if (!$tab->add()) {
            return false;
        }
        
        return $tab;
    }

    /**
     * Met à jour ou crée un onglet selon son existence
     * 
     * @param string $className Nom de la classe du contrôleur
     * @param string $nameEn Nom en anglais
     * @param string $nameFr Nom en français
     * @param int $parentTabId ID de l'onglet parent
     * @param Module $module Instance du module
     * @param int $active État actif (1) ou inactif (0)
     * @return Tab|false Instance de Tab mise à jour/créée ou false en cas d'erreur
     */
    private function updateOrCreateTab($className, $nameEn, $nameFr, $parentTabId, Module $module, $active = 1)
    {
        $tabId = Tab::getIdFromClassName($className);
        
        if ($tabId) {
            // Mise à jour de l'onglet existant
            $tab = new Tab($tabId);
            $tab->active = $active;
            $tab->id_parent = $parentTabId;
            
            // Mettre à jour les noms dans toutes les langues
            foreach (Language::getLanguages(true) as $lang) {
                $langIso = strtolower($lang['iso_code']);
                if ($langIso === 'en' || $langIso === 'en-us' || $langIso === 'en-gb') {
                    $tab->name[$lang['id_lang']] = $nameEn;
                } else {
                    $tab->name[$lang['id_lang']] = $nameFr;
                }
            }
            
            if (!$tab->save()) {
                return false;
            }
            return $tab;
        } else {
            // Création d'un nouvel onglet
            return $this->createMultilingualTab($className, $nameEn, $nameFr, $parentTabId, $module, $active);
        }
    }

    private function uninstallAdminTabs(): bool
    {

        foreach (['AdminCiklikFrequencies', 'AdminCiklikSubscriptionsOrders'] as $tabClassName) {
            $idTab = Tab::getIdFromClassName($tabClassName);
            if ($idTab) {
                $tab = new Tab($idTab);
                if (!$tab->delete()) {
                    return false;
                }
            }
        }

        return true;
    }
}
