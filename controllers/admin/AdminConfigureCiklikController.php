<?php

/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

use PrestaShop\Module\Ciklik\Addons\Account;
use PrestaShop\Module\Ciklik\Api\Shop;
use PrestaShop\Module\Ciklik\Data\ShopData;
use PrestaShop\Module\Ciklik\Install\Installer;

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminConfigureCiklikController extends ModuleAdminController
{
    use Account;

    protected $moduleContainer;

    /**
     * Méthode de traduction pour compatibilité PS9
     * En PS9, ModuleAdminController n'a plus la méthode l() directement
     *
     * @param string $string Chaîne à traduire
     * @param string|null $class Nom de la classe (non utilisé, pour compatibilité)
     * @param bool $addslashes Ajouter des slashes
     * @param bool $htmlentities Encoder les entités HTML
     *
     * @return string
     */
    protected function l($string, $class = null, $addslashes = false, $htmlentities = true)
    {
        // Protection si le contexte n'est pas encore prêt lors de l'initialisation
        try {
            if (!$this->module) {
                return $string;
            }

            return $this->module->l($string, 'AdminConfigureCiklikController', $addslashes, $htmlentities);
        } catch (Exception $e) {
            return $string;
        }
    }

    public function __construct()
    {
        $this->bootstrap = true;
        $this->className = 'Configuration';
        $this->table = 'configuration';
        $this->page_header_toolbar_title = 'Ciklik';

        parent::__construct();
    }

    /**
     * Initialisation du controller
     * Les champs de formulaire sont définis ici car le contexte langue
     * est correctement initialisé après parent::init()
     */
    public function init()
    {
        parent::init();

        if (empty(Currency::checkPaymentCurrencies($this->module->id))) {
            $this->warnings[] = $this->l('No currency has been set for this module.');
        }

        $attributes_groups = AttributeGroup::getAttributesGroups((int) Configuration::get('PS_LANG_DEFAULT'));
        $product_suffixes = json_decode(Configuration::get(Ciklik::CONFIG_PRODUCT_NAME_SUFFIXES), JSON_OBJECT_AS_ARRAY);
        $product_suffixes_choices = [];
        $product_suffixes_values = [];

        // PS Accounts n'est pas encore compatible PS9 - on désactive temporairement
        if (version_compare(_PS_VERSION_, '9.0.0', '<')) {
            try {
                Account::injectAccount($this, $this->context);
            } catch (Exception $e) {
                // Silently fail si PS Accounts n'est pas disponible
            }
        }

        foreach ($attributes_groups as $group) {
            $product_suffixes_choices[$group['id_attribute_group']] = $group['name'];
            $product_suffixes_values[$group['id_attribute_group']] = in_array($group['id_attribute_group'], $product_suffixes === null ? [] : $product_suffixes);
        }

        if (version_compare(_PS_VERSION_, '8.0.0', '<')) {
            $this->fields_options = $this->get17Fields($attributes_groups);
        } else {
            $this->fields_options = $this->get18Fields($attributes_groups, $product_suffixes_values, $product_suffixes_choices);
        }
    }

    public function get17Fields($attributes_groups)
    {
        $available_order_states = self::getCiklikPaidOrderStates((int) Configuration::get('PS_LANG_DEFAULT'));
        $purchase_type_attributes = self::getCiklikAttributes(Ciklik::CONFIG_PURCHASE_TYPE_ATTRIBUTE_GROUP_ID);
        $frequencies_attributes = self::getCiklikAttributes(Ciklik::CONFIG_FREQUENCIES_ATTRIBUTE_GROUP_ID);

        $intervals = [
            ['engagement_interval' => 'month', 'name' => $this->trans('Mensuel', [], 'Modules.Ciklik')],
            ['engagement_interval' => 'week', 'name' => $this->trans('Hebdomadaire', [], 'Modules.Ciklik')],
            ['engagement_interval' => 'day', 'name' => $this->trans('Journalier', [], 'Modules.Ciklik')],
        ];

        $thread_statuses = [
            ['thread_status' => 'open', 'name' => $this->l('Open')],
            ['thread_status' => 'closed', 'name' => $this->l('Closed')],
        ];

        return [
            $this->module->name => [
                'title' => $this->trans('Configuration', [], 'Admin.Global'),
                'fields' => [
                    Ciklik::CONFIG_API_TOKEN => [
                        'type' => 'text',
                        'title' => $this->l('API Token'),
                        'cast' => 'strval',
                        'required' => true,
                    ],
                    Ciklik::CONFIG_MODE => [
                        'type' => 'radio',
                        'title' => $this->l('Mode'),
                        'choices' => [
                            'SANDBOX' => $this->trans('Démo', [], 'Modules.Ciklik.Admin'),
                            'LIVE' => $this->trans('Production/Dev/Test', [], 'Modules.Ciklik.Admin'),
                        ],
                        'required' => true,
                    ],
                    Ciklik::CONFIG_PURCHASE_TYPE_ATTRIBUTE_GROUP_ID => [
                        'type' => 'select',
                        'title' => $this->l('Purchase type group'),
                        'desc' => $this->l('Attribute group containing purchase type options'),
                        'cast' => 'intval',
                        'identifier' => 'id_attribute_group',
                        'list' => $attributes_groups,
                    ],
                    Ciklik::CONFIG_FREQUENCIES_ATTRIBUTE_GROUP_ID => [
                        'type' => 'select',
                        'title' => $this->l('Frequencies group'),
                        'desc' => $this->l('Attribute group containing subscription frequency options'),
                        'cast' => 'intval',
                        'identifier' => 'id_attribute_group',
                        'list' => $attributes_groups,
                    ],
                    Ciklik::CONFIG_ONEOFF_ATTRIBUTE_ID => [
                        'type' => 'select',
                        'title' => $this->l('One-time purchase attribute'),
                        'cast' => 'intval',
                        'identifier' => 'id_attribute',
                        'list' => $purchase_type_attributes,
                    ],
                    Ciklik::CONFIG_SUBSCRIPTION_ATTRIBUTE_ID => [
                        'type' => 'select',
                        'title' => $this->l('Subscription purchase attribute'),
                        'cast' => 'intval',
                        'identifier' => 'id_attribute',
                        'list' => $purchase_type_attributes,
                    ],
                    Ciklik::CONFIG_DEFAULT_SUBSCRIPTION_ATTRIBUTE_ID => [
                        'type' => 'select',
                        'title' => $this->l('Default subscription'),
                        'desc' => $this->l('Value selected when subscription purchase is activated'),
                        'cast' => 'intval',
                        'identifier' => 'id_attribute',
                        'list' => $frequencies_attributes,
                    ],
                    Ciklik::CONFIG_ORDER_STATE => [
                        'type' => 'select',
                        'title' => $this->l('Order status'),
                        'desc' => $this->l('Status for orders accepted through Ciklik'),
                        'cast' => 'intval',
                        'identifier' => 'id_order_state',
                        'list' => $available_order_states,
                    ],
                    Ciklik::CONFIG_DELEGATE_OPTIONS_DISPLAY => [
                        'type' => 'bool',
                        'title' => $this->l('Delegate options display'),
                        'desc' => $this->l('Subscription options are displayed via a checkbox'),
                        'validation' => 'isBool',
                        'cast' => 'intval',
                        'required' => false,
                    ],
                    Ciklik::CONFIG_ENABLE_ENGAGEMENT => [
                        'type' => 'bool',
                        'title' => $this->l('Enable commitment'),
                        'desc' => $this->l('This option disables unsubscription during the commitment period'),
                        'validation' => 'isBool',
                        'cast' => 'intval',
                        'required' => false,
                    ],
                    Ciklik::CONFIG_ENGAGEMENT_INTERVAL => [
                        'type' => 'select',
                        'title' => $this->l('Commitment cycle'),
                        'identifier' => 'engagement_interval',
                        'cast' => 'strval',
                        'list' => $intervals,
                    ],
                    Ciklik::CONFIG_ENGAGEMENT_INTERVAL_COUNT => [
                        'type' => 'select',
                        'title' => $this->l('Number of commitment cycles'),
                        'identifier' => 'engagement_interval_count',
                        'cast' => 'intval',
                        'list' => array_map(function ($value) {
                            return ['engagement_interval_count' => $value, 'name' => $value];
                        }, range(0, 31)),
                    ],
                    Ciklik::CONFIG_ENABLE_CHANGE_INTERVAL => [
                        'type' => 'bool',
                        'title' => $this->l('Frequency modification'),
                        'validation' => 'isBool',
                        'desc' => $this->l('This option disables subscription frequency modification, applied on the next payment date'),
                        'cast' => 'intval',
                        'required' => false,
                    ],
                    Ciklik::CONFIG_ALLOW_CHANGE_NEXT_BILLING => [
                        'type' => 'bool',
                        'title' => $this->l('Allow next payment date modification'),
                        'validation' => 'isBool',
                        'cast' => 'intval',
                        'required' => false,
                    ],
                    Ciklik::CONFIG_DEBUG_LOGS_ENABLED => [
                        'type' => 'bool',
                        'title' => $this->l('Enable debug logs'),
                        'validation' => 'isBool',
                        'cast' => 'intval',
                        'required' => false,
                    ],
                    Ciklik::CONFIG_ENABLE_CUSTOMER_GROUP_ASSIGNMENT => [
                        'type' => 'bool',
                        'title' => $this->l('Enable customer group assignment'),
                        'desc' => $this->l('This option assigns a customer group based on certain conditions'),
                        'validation' => 'isBool',
                        'cast' => 'intval',
                        'required' => false,
                    ],
                    Ciklik::CONFIG_CUSTOMER_GROUP_TO_ASSIGN => [
                        'type' => 'select',
                        'title' => $this->l('Customer group to assign'),
                        'desc' => $this->l('Select the customer group to assign when conditions are met'),
                        'cast' => 'intval',
                        'identifier' => 'id_group',
                        'list' => Group::getGroups(Context::getContext()->language->id),
                    ],
                    Ciklik::CONFIG_ENABLE_UPSELL => [
                        'type' => 'bool',
                        'title' => $this->l('Enable upsell'),
                        'validation' => 'isBool',
                        'cast' => 'intval',
                    ],
                    Ciklik::CONFIG_USE_FREQUENCY_MODE => [
                        'type' => 'bool',
                        'title' => $this->l('Use frequency mode'),
                        'desc' => $this->l('Offer subscriptions without using product combinations'),
                        'validation' => 'isBool',
                        'cast' => 'intval',
                        'required' => false,
                    ],
                    Ciklik::CONFIG_FREQUENCY_PRICE_BASE => [
                        'type' => 'radio',
                        'title' => $this->l('Frequency discount calculation base'),
                        'desc' => $this->l('Gross: the discount applies on the gross catalog price. Net: the discount applies on the price after existing price rules (promotions, discounts).'),
                        'choices' => [
                            'gross' => $this->l('Gross catalog price (default behavior)'),
                            'net' => $this->l('Price after existing price rules'),
                        ],
                        'required' => false,
                        'cast' => 'strval',
                    ],
                    Ciklik::CONFIG_FALLBACK_TO_DEFAULT_ATTRIBUTE => [
                        'type' => 'bool',
                        'title' => $this->l('Fall back to default combination if deleted'),
                        'desc' => $this->l('If a requested combination has been deleted, automatically use the product default combination'),
                        'validation' => 'isBool',
                        'cast' => 'intval',
                        'required' => false,
                    ],
                    Ciklik::CONFIG_ENABLE_ORDER_THREAD => [
                        'type' => 'bool',
                        'title' => $this->l('Create customer message thread for orders'),
                        'desc' => $this->l('This option automatically creates a customer message thread with Ciklik information for each order'),
                        'validation' => 'isBool',
                        'cast' => 'intval',
                        'required' => false,
                    ],
                    Ciklik::CONFIG_ORDER_THREAD_STATUS => [
                        'type' => 'select',
                        'title' => $this->l('Customer message thread status'),
                        'desc' => $this->l('Default status for customer message threads created for Ciklik orders'),
                        'identifier' => 'thread_status',
                        'cast' => 'strval',
                        'list' => $thread_statuses,
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                ],
            ],
        ];
    }

    public function get18Fields($attributes_groups, $product_suffixes_values, $product_suffixes_choices)
    {
        $available_order_states = self::getCiklikPaidOrderStates((int) Configuration::get('PS_LANG_DEFAULT'));
        $purchase_type_attributes = self::getCiklikAttributes(Ciklik::CONFIG_PURCHASE_TYPE_ATTRIBUTE_GROUP_ID);
        $frequencies_attributes = self::getCiklikAttributes(Ciklik::CONFIG_FREQUENCIES_ATTRIBUTE_GROUP_ID);

        $intervals = [
            ['engagement_interval' => 'month', 'name' => $this->trans('Mensuel', [], 'Modules.Ciklik')],
            ['engagement_interval' => 'week', 'name' => $this->trans('Hebdomadaire', [], 'Modules.Ciklik')],
            ['engagement_interval' => 'day', 'name' => $this->trans('Journalier', [], 'Modules.Ciklik')],
        ];

        $thread_statuses = [
            ['thread_status' => 'open', 'name' => $this->l('Open')],
            ['thread_status' => 'closed', 'name' => $this->l('Closed')],
        ];

        return [
            $this->module->name => [
                'title' => $this->trans('Configuration', [], 'Admin.Global'),
                'fields' => [
                    Ciklik::CONFIG_API_TOKEN => [
                        'type' => 'text',
                        'title' => $this->l('API Token'),
                        'cast' => 'strval',
                        'required' => true,
                    ],
                    Ciklik::CONFIG_MODE => [
                        'type' => 'radio',
                        'title' => $this->l('Mode'),
                        'choices' => [
                            'SANDBOX' => $this->trans('Test', [], 'Modules.Ciklik.Admin'),
                            'LIVE' => $this->trans('Production', [], 'Modules.Ciklik.Admin'),
                        ],
                        'required' => true,
                    ],
                    Ciklik::CONFIG_PURCHASE_TYPE_ATTRIBUTE_GROUP_ID => [
                        'type' => 'select',
                        'title' => $this->l('Purchase type group'),
                        'desc' => $this->l('Attribute group containing purchase type options'),
                        'cast' => 'intval',
                        'identifier' => 'id_attribute_group',
                        'list' => $attributes_groups,
                    ],
                    Ciklik::CONFIG_FREQUENCIES_ATTRIBUTE_GROUP_ID => [
                        'type' => 'select',
                        'title' => $this->l('Frequencies group'),
                        'desc' => $this->l('Attribute group containing subscription frequency options'),
                        'cast' => 'intval',
                        'identifier' => 'id_attribute_group',
                        'list' => $attributes_groups,
                    ],
                    Ciklik::CONFIG_ONEOFF_ATTRIBUTE_ID => [
                        'type' => 'select',
                        'title' => $this->l('One-time purchase attribute'),
                        'cast' => 'intval',
                        'identifier' => 'id_attribute',
                        'list' => $purchase_type_attributes,
                    ],
                    Ciklik::CONFIG_SUBSCRIPTION_ATTRIBUTE_ID => [
                        'type' => 'select',
                        'title' => $this->l('Subscription purchase attribute'),
                        'cast' => 'intval',
                        'identifier' => 'id_attribute',
                        'list' => $purchase_type_attributes,
                    ],
                    Ciklik::CONFIG_DEFAULT_SUBSCRIPTION_ATTRIBUTE_ID => [
                        'type' => 'select',
                        'title' => $this->l('Default subscription'),
                        'desc' => $this->l('Value selected when subscription purchase is activated'),
                        'cast' => 'intval',
                        'identifier' => 'id_attribute',
                        'list' => $frequencies_attributes,
                    ],
                    Ciklik::CONFIG_PRODUCT_NAME_SUFFIXES => [
                        'type' => 'checkbox',
                        'title' => $this->l('Product name suffix'),
                        'desc' => $this->l('Combination values added to the name sent to Ciklik'),
                        'show' => true,
                        'multiple' => true,
                        'skip_clean_html' => true,
                        'value_multiple' => $product_suffixes_values,
                        'choices' => $product_suffixes_choices,
                    ],
                    Ciklik::CONFIG_ORDER_STATE => [
                        'type' => 'select',
                        'title' => $this->l('Order status'),
                        'desc' => $this->l('Status for orders accepted through Ciklik'),
                        'cast' => 'intval',
                        'identifier' => 'id_order_state',
                        'list' => $available_order_states,
                    ],
                    Ciklik::CONFIG_DELEGATE_OPTIONS_DISPLAY => [
                        'type' => 'bool',
                        'title' => $this->l('Delegate options display'),
                        'desc' => $this->l('Subscription options are displayed via a checkbox'),
                        'validation' => 'isBool',
                        'cast' => 'intval',
                        'required' => false,
                    ],
                    Ciklik::CONFIG_ENABLE_ENGAGEMENT => [
                        'type' => 'bool',
                        'title' => $this->l('Enable commitment'),
                        'desc' => $this->l('This option disables unsubscription during the commitment period'),
                        'validation' => 'isBool',
                        'cast' => 'intval',
                        'required' => false,
                    ],
                    Ciklik::CONFIG_ENGAGEMENT_INTERVAL => [
                        'type' => 'select',
                        'title' => $this->l('Commitment cycle'),
                        'identifier' => 'engagement_interval',
                        'cast' => 'strval',
                        'list' => $intervals,
                    ],
                    Ciklik::CONFIG_ENGAGEMENT_INTERVAL_COUNT => [
                        'type' => 'select',
                        'title' => $this->l('Number of commitment cycles'),
                        'identifier' => 'engagement_interval_count',
                        'cast' => 'intval',
                        'list' => array_map(function ($value) {
                            return ['engagement_interval_count' => $value, 'name' => $value];
                        }, range(0, 31)),
                    ],
                    Ciklik::CONFIG_ENABLE_CHANGE_INTERVAL => [
                        'type' => 'bool',
                        'title' => $this->l('Frequency modification'),
                        'validation' => 'isBool',
                        'desc' => $this->l('This option disables subscription frequency modification, applied on the next payment date'),
                        'cast' => 'intval',
                        'required' => false,
                    ],
                    Ciklik::CONFIG_ALLOW_CHANGE_NEXT_BILLING => [
                        'type' => 'bool',
                        'title' => $this->l('Allow next payment date modification'),
                        'validation' => 'isBool',
                        'cast' => 'intval',
                        'required' => false,
                    ],
                    Ciklik::CONFIG_DEBUG_LOGS_ENABLED => [
                        'type' => 'bool',
                        'title' => $this->l('Enable debug logs'),
                        'validation' => 'isBool',
                        'cast' => 'intval',
                        'required' => false,
                    ],
                    Ciklik::CONFIG_ENABLE_CUSTOMER_GROUP_ASSIGNMENT => [
                        'type' => 'bool',
                        'title' => $this->l('Enable customer group assignment'),
                        'desc' => $this->l('This option assigns a customer group based on certain conditions'),
                        'validation' => 'isBool',
                        'cast' => 'intval',
                        'required' => false,
                    ],
                    Ciklik::CONFIG_CUSTOMER_GROUP_TO_ASSIGN => [
                        'type' => 'select',
                        'title' => $this->l('Customer group to assign'),
                        'desc' => $this->l('Select the customer group to assign when conditions are met'),
                        'cast' => 'intval',
                        'identifier' => 'id_group',
                        'list' => Group::getGroups(Context::getContext()->language->id),
                    ],
                    Ciklik::CONFIG_ENABLE_UPSELL => [
                        'type' => 'bool',
                        'title' => $this->l('Enable upsell'),
                        'validation' => 'isBool',
                        'cast' => 'intval',
                    ],
                    Ciklik::CONFIG_USE_FREQUENCY_MODE => [
                        'type' => 'bool',
                        'title' => $this->l('Use subscription customization'),
                        'desc' => $this->l('Subscription options are displayed via a checkbox'),
                        'validation' => 'isBool',
                        'cast' => 'intval',
                        'required' => false,
                    ],
                    Ciklik::CONFIG_FREQUENCY_PRICE_BASE => [
                        'type' => 'radio',
                        'title' => $this->l('Frequency discount calculation base'),
                        'desc' => $this->l('Gross: the discount applies on the gross catalog price. Net: the discount applies on the price after existing price rules (promotions, discounts).'),
                        'choices' => [
                            'gross' => $this->l('Gross catalog price (default behavior)'),
                            'net' => $this->l('Price after existing price rules'),
                        ],
                        'required' => false,
                        'cast' => 'strval',
                    ],
                    Ciklik::CONFIG_FALLBACK_TO_DEFAULT_ATTRIBUTE => [
                        'type' => 'bool',
                        'title' => $this->l('Fall back to default combination if deleted'),
                        'desc' => $this->l('If a requested combination has been deleted, automatically use the product default combination'),
                        'validation' => 'isBool',
                        'cast' => 'intval',
                        'required' => false,
                    ],
                    Ciklik::CONFIG_ENABLE_ORDER_THREAD => [
                        'type' => 'bool',
                        'title' => $this->l('Create customer message thread for orders'),
                        'desc' => $this->l('This option automatically creates a customer message thread with Ciklik information for each order'),
                        'validation' => 'isBool',
                        'cast' => 'intval',
                        'required' => false,
                    ],
                    Ciklik::CONFIG_ORDER_THREAD_STATUS => [
                        'type' => 'select',
                        'title' => $this->l('Customer message thread status'),
                        'desc' => $this->l('Default status for customer message threads created for Ciklik orders'),
                        'identifier' => 'thread_status',
                        'cast' => 'strval',
                        'list' => $thread_statuses,
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                ],
            ],
        ];
    }

    protected function updateOptionCiklikProductNameSuffixes($values)
    {
        // Si $values n'est pas un tableau ou est vide, on le met à vide
        if (!is_array($values) || empty($values)) {
            $values = [];
        } else {
            // Filtrer pour ne garder que les entiers valides (ex. [3, 'foo', 4] => [3, 4])
            $values = array_filter($values, function ($val) {
                return is_numeric($val) && (int) $val > 0;
            });
        }

        // Stocker le tableau JSON directement
        Configuration::updateValue(Ciklik::CONFIG_PRODUCT_NAME_SUFFIXES, json_encode($values));
    }

    /**
     * Traite les données après la soumission du formulaire
     * Met à jour la visibilité de l'onglet des fréquences si le mode fréquence change
     * et s'assure que tous les onglets sont créés
     *
     * @return void
     */
    public function postProcess()
    {
        parent::postProcess();

        // S'assurer que tous les onglets d'administration sont installés/mis à jour
        // Cette méthode est idempotente et créera les onglets s'ils n'existent pas
        $installer = new Installer();
        $installer->installAdminTabs($this->module);

        // Mettre à jour la visibilité de l'onglet des fréquences lorsque le mode fréquence change
        $installer->updateFrequenciesTabVisibility();

        // Vider le cache pour que les nouveaux onglets apparaissent immédiatement
        if (class_exists('Tools')) {
            Tools::clearSmartyCache();
            Tools::clearCache();
        }

        // Forcer la reconstruction du cache des menus si la méthode existe
        if (method_exists('Tab', 'resetStaticCache')) {
            Tab::resetStaticCache();
        }
    }

    public static function getCiklikPaidOrderStates($id_lang)
    {
        return Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS('
            SELECT *
            FROM ' . _DB_PREFIX_ . 'order_state os
            LEFT JOIN ' . _DB_PREFIX_ . 'order_state_lang osl ON (os.id_order_state = osl.id_order_state AND osl.id_lang = ' . (int) $id_lang . ')'
            . 'WHERE deleted = 0
                    AND paid = 1
                    AND invoice = 1
            ORDER BY name ASC',
        );
    }

    public static function getCiklikAttributes($attribute_group_id)
    {
        $query = new DbQuery();
        $query->select('a.id_attribute, al.name');
        $query->from('attribute', 'a');
        $query->leftJoin('attribute_lang', 'al', 'al.id_attribute = a.id_attribute');
        $query->where('a.id_attribute_group = ' . (int) Configuration::get($attribute_group_id));
        $query->where('al.id_lang = ' . (int) Configuration::get('PS_LANG_DEFAULT'));

        return Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($query);
    }

    public function beforeUpdateOptions()
    {
        if (Configuration::get(Ciklik::CONFIG_API_TOKEN) !== Tools::getValue(Ciklik::CONFIG_API_TOKEN)) {
            try {
                $ciklikShopApi = new Shop($this->context->link);

                $shopData = $ciklikShopApi->whoIAm([
                    'headers' => [
                        'Authorization' => 'Bearer ' . Tools::getValue(Ciklik::CONFIG_API_TOKEN),
                    ],
                ]);

                if ($shopData instanceof ShopData) {
                    Configuration::updateGlobalValue(Ciklik::CONFIG_HOST, $shopData->host);

                    $webservice = new WebserviceKey(Configuration::get(Ciklik::CONFIG_WEBSERVICE_ID));

                    $ciklikShopApi->metadata(
                        [
                            'prestashop_endpoint' => Tools::getShopDomainSsl(true),
                            'ciklik_encrypted_prestashop_token' => $webservice->key,
                        ],
                        [
                            'headers' => [
                                'Authorization' => 'Bearer ' . Tools::getValue(Ciklik::CONFIG_API_TOKEN),
                            ],
                        ],
                    );
                }

                $this->confirmations[] = $this->trans('Connection successful', [], 'Modules.Ciklik.Admin');
            } catch (Exception $e) {
                // Échappement XSS du message d'exception (source externe potentiellement non fiable)
                $this->errors[] = $this->trans('Connection failed', [], 'Modules.Ciklik.Admin') . ' ' . Tools::htmlentitiesUTF8($e->getMessage());
            }
        }
    }

    public function getService(string $serviceName)
    {
        if ($this->moduleContainer === null) {
            $this->moduleContainer = new PrestaShop\ModuleLibServiceContainer\DependencyInjection\ServiceContainer(
                $this->module->name,
                $this->module->getLocalPath(),
            );
        }

        return $this->moduleContainer->getService($serviceName);
    }
}
