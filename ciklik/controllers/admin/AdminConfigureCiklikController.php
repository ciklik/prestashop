<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

use PrestaShop\Module\Ciklik\Api\Shop;
use PrestaShop\Module\Ciklik\Data\ShopData;

class AdminConfigureCiklikController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        $this->className = 'Configuration';
        $this->table = 'configuration';
        $this->page_header_toolbar_title = 'Ciklik';

        parent::__construct();

        if (empty(Currency::checkPaymentCurrencies($this->module->id))) {
            $this->warnings[] = $this->l('No currency has been set for this module.');
        }

        $attributes_groups = AttributeGroup::getAttributesGroups((int)Configuration::get('PS_LANG_DEFAULT'));
        $product_suffixes = explode(',', Configuration::get(Ciklik::CONFIG_PRODUCT_NAME_SUFFIXES));
        $product_suffixes_choices = [];
        $product_suffixes_values = [];

        foreach ($attributes_groups as $group) {
            $product_suffixes_choices[$group['id_attribute_group']] = $group['name'];
            $product_suffixes_values[$group['id_attribute_group']] = in_array($group['id_attribute_group'], $product_suffixes);
        }

        if (version_compare(_PS_VERSION_, '8.0.0', '<')) {
            $this->fields_options = $this->get17Fileds($attributes_groups);
        } else {
            $this->fields_options = $this->get18Fileds($attributes_groups, $product_suffixes_values, $product_suffixes_choices);
        }
    }

    public function get17Fileds($attributes_groups)
    {
        $available_order_states = self::getCiklikPaidOrderStates((int) Configuration::get('PS_LANG_DEFAULT'));
        $purchase_type_attributes = self::getCiklikAttributes(Ciklik::CONFIG_PURCHASE_TYPE_ATTRIBUTE_GROUP_ID);
        $frequencies_attributes = self::getCiklikAttributes(Ciklik::CONFIG_FREQUENCIES_ATTRIBUTE_GROUP_ID);

        $intervals = [
            ['engagement_interval' => 'month' , 'name' => $this->trans('Mensuel', [], 'Modules.Ciklik')],
            ['engagement_interval' => 'week', 'name' => $this->trans('Hebdomadaire', [], 'Modules.Ciklik')],
            ['engagement_interval' => 'day', 'name' => $this->trans('Journalier', [], 'Modules.Ciklik')],
        ];

        return [
            $this->module->name => [
                'title'  => $this->trans('Configuration', [], 'Admin.Global'),
                'fields' => [
                    Ciklik::CONFIG_API_TOKEN                        => [
                        'type'     => 'text',
                        'title'    => $this->l('API Token'),
                        'cast'     => 'strval',
                        'required' => true,
                    ],
                    Ciklik::CONFIG_MODE                             => [
                        'type'     => 'radio',
                        'title'    => $this->l('Mode'),
                        'choices'  => [
                            'SANDBOX' => $this->trans('Test', [], 'Modules.Ciklik.Admin'),
                            'LIVE'    => $this->trans('Production', [], 'Modules.Ciklik.Admin'),
                        ],
                        'required' => true,
                    ],
                    Ciklik::CONFIG_PURCHASE_TYPE_ATTRIBUTE_GROUP_ID => [
                        'type'       => 'select',
                        'title'      => $this->l('Groupe Achats'),
                        'desc'       => $this->l('Groupe d\'attributs contenant les options de type d\'achat'),
                        'cast'       => 'intval',
                        'identifier' => 'id_attribute_group',
                        'list'       => $attributes_groups,
                    ],
                    Ciklik::CONFIG_FREQUENCIES_ATTRIBUTE_GROUP_ID   => [
                        'type'       => 'select',
                        'title'      => $this->l('Groupe Fréquences'),
                        'desc'       => $this->l('Groupe d\'attributs contenant les options de fréquences d\'abonnement'),
                        'cast'       => 'intval',
                        'identifier' => 'id_attribute_group',
                        'list'       => $attributes_groups,
                    ],
                    Ciklik::CONFIG_ONEOFF_ATTRIBUTE_ID   => [
                        'type'       => 'select',
                        'title'      => $this->l('Attribut "Achat en une fois"'),
                        'cast'       => 'intval',
                        'identifier' => 'id_attribute',
                        'list'       => $purchase_type_attributes,
                    ],
                    Ciklik::CONFIG_SUBSCRIPTION_ATTRIBUTE_ID   => [
                        'type'       => 'select',
                        'title'      => $this->l('Attribut "Achat par abonnement"'),
                        'cast'       => 'intval',
                        'identifier' => 'id_attribute',
                        'list'       => $purchase_type_attributes,
                    ],
                    Ciklik::CONFIG_DEFAULT_SUBSCRIPTION_ATTRIBUTE_ID   => [
                        'type'       => 'select',
                        'title'      => $this->l('Abonnement par défaut'),
                        'desc'       => $this->l('Valeur sélectionnée lorsque l\'on active l\'achat par abonnement'),
                        'cast'       => 'intval',
                        'identifier' => 'id_attribute',
                        'list'       => $frequencies_attributes,
                    ],
                    Ciklik::CONFIG_ORDER_STATE   => [
                        'type'       => 'select',
                        'title'      => $this->l('Statut des commandes'),
                        'desc'       => $this->l('Statut des commandes acceptées via Ciklik'),
                        'cast'       => 'intval',
                        'identifier' => 'id_order_state',
                        'list'       => $available_order_states,
                    ],
                    Ciklik::CONFIG_DELEGATE_OPTIONS_DISPLAY => [
                        'type'       => 'bool',
                        'title'      => $this->l('Déléguer l\'affichage des options'),
                        'desc'       => $this->l('Les options d\'abonnement sont affichées via une case à cocher'),
                        'validation' => 'isBool',
                        'cast'       => 'intval',
                        'required'   => false,
                    ],
                    Ciklik::CONFIG_ENABLE_ENGAGEMENT => [
                        'type'       => 'bool',
                        'title'      => $this->l('Activer l\'engagement'),
                        'desc'       => $this->l('Cette option permet de désactiver le désabonnement pendant le cycle d’engagement'),
                        'validation' => 'isBool',
                        'cast'       => 'intval',
                        'required'   => false,
                    ],
                    Ciklik::CONFIG_ENGAGEMENT_INTERVAL => [
                        'type'       => 'select',
                        'title'      => $this->l('Cycle d’engagement'),
                        'identifier' => 'engagement_interval',
                        'cast' => 'strval',
                        'list'       => $intervals,
                    ],
                    Ciklik::CONFIG_ENGAGEMENT_INTERVAL_COUNT => [
                        'type'       => 'select',
                        'title'      => $this->l('Nombre de cycles d’engagement'),
                        'identifier' => 'engagement_interval_count',
                        'cast'       => 'intval',
                        'list'       =>  array_map(function($value) {
                            return ['engagement_interval_count' => $value, 'name'=> $value];
                        }, range(0,31)),
                    ],
                    Ciklik::CONFIG_ALLOW_CHANGE_NEXT_BILLING => [
                        'type'       => 'bool',
                        'title'      => $this->l('Autoriser la modification de la date du prochain paiement'),
                        'validation' => 'isBool',
                        'cast'       => 'intval',
                        'required'   => false,
                    ],
                    Ciklik::CONFIG_DEBUG_LOGS_ENABLED => [
                        'type'       => 'bool',
                        'title'      => $this->l('Enable debug logs'),
                        'validation' => 'isBool',
                        'cast'       => 'intval',
                        'required'   => false,
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                ],
            ],
        ];
    }

    public function get18Fileds($attributes_groups, $product_suffixes_values, $product_suffixes_choices)
    {
        $available_order_states = self::getCiklikPaidOrderStates((int) Configuration::get('PS_LANG_DEFAULT'));
        $purchase_type_attributes = self::getCiklikAttributes(Ciklik::CONFIG_PURCHASE_TYPE_ATTRIBUTE_GROUP_ID);
        $frequencies_attributes = self::getCiklikAttributes(Ciklik::CONFIG_FREQUENCIES_ATTRIBUTE_GROUP_ID);

        $intervals = [
            ['engagement_interval' => 'month' , 'name' => $this->trans('Mensuel', [], 'Modules.Ciklik')],
            ['engagement_interval' => 'week', 'name' => $this->trans('Hebdomadaire', [], 'Modules.Ciklik')],
            ['engagement_interval' => 'day', 'name' => $this->trans('Journalier', [], 'Modules.Ciklik')],
        ];

        return [
            $this->module->name => [
                'title'  => $this->trans('Configuration', [], 'Admin.Global'),
                'fields' => [
                    Ciklik::CONFIG_API_TOKEN                        => [
                        'type'     => 'text',
                        'title'    => $this->l('API Token'),
                        'cast'     => 'strval',
                        'required' => true,
                    ],
                    Ciklik::CONFIG_MODE                             => [
                        'type'     => 'radio',
                        'title'    => $this->l('Mode'),
                        'choices'  => [
                            'SANDBOX' => $this->trans('Test', [], 'Modules.Ciklik.Admin'),
                            'LIVE'    => $this->trans('Production', [], 'Modules.Ciklik.Admin'),
                        ],
                        'required' => true,
                    ],
                    Ciklik::CONFIG_PURCHASE_TYPE_ATTRIBUTE_GROUP_ID => [
                        'type'       => 'select',
                        'title'      => $this->l('Groupe Achats'),
                        'desc'       => $this->l('Groupe d\'attributs contenant les options de type d\'achat'),
                        'cast'       => 'intval',
                        'identifier' => 'id_attribute_group',
                        'list'       => $attributes_groups,
                    ],
                    Ciklik::CONFIG_FREQUENCIES_ATTRIBUTE_GROUP_ID   => [
                        'type'       => 'select',
                        'title'      => $this->l('Groupe Fréquences'),
                        'desc'       => $this->l('Groupe d\'attributs contenant les options de fréquences d\'abonnement'),
                        'cast'       => 'intval',
                        'identifier' => 'id_attribute_group',
                        'list'       => $attributes_groups,
                    ],
                    Ciklik::CONFIG_ONEOFF_ATTRIBUTE_ID   => [
                        'type'       => 'select',
                        'title'      => $this->l('Attribut "Achat en une fois"'),
                        'cast'       => 'intval',
                        'identifier' => 'id_attribute',
                        'list'       => $purchase_type_attributes,
                    ],
                    Ciklik::CONFIG_SUBSCRIPTION_ATTRIBUTE_ID   => [
                        'type'       => 'select',
                        'title'      => $this->l('Attribut "Achat par abonnement"'),
                        'cast'       => 'intval',
                        'identifier' => 'id_attribute',
                        'list'       => $purchase_type_attributes,
                    ],
                    Ciklik::CONFIG_DEFAULT_SUBSCRIPTION_ATTRIBUTE_ID   => [
                        'type'       => 'select',
                        'title'      => $this->l('Abonnement par défaut'),
                        'desc'       => $this->l('Valeur sélectionnée lorsque l\'on active l\'achat par abonnement'),
                        'cast'       => 'intval',
                        'identifier' => 'id_attribute',
                        'list'       => $frequencies_attributes,
                    ],
                    Ciklik::CONFIG_PRODUCT_NAME_SUFFIXES            => [
                        'type'            => 'checkbox',
                        'title'           => $this->l('Suffix de nom de produit'),
                        'desc'            => $this->l('Valeurs de déclinaisons ajoutées au nom transmis à Ciklik'),
                        'show'            => true,
                        'skip_clean_html' => true,
                        'cast'            => 'strval',
                        'multiple'        => true,
                        'value_multiple'  => $product_suffixes_values,
                        'choices'         => $product_suffixes_choices,
                    ],
                    Ciklik::CONFIG_ORDER_STATE   => [
                        'type'       => 'select',
                        'title'      => $this->l('Statut des commandes'),
                        'desc'       => $this->l('Statut des commandes acceptées via Ciklik'),
                        'cast'       => 'intval',
                        'identifier' => 'id_order_state',
                        'list'       => $available_order_states,
                    ],
                    Ciklik::CONFIG_DELEGATE_OPTIONS_DISPLAY => [
                        'type'       => 'bool',
                        'title'      => $this->l('Déléguer l\'affichage des options'),
                        'desc'       => $this->l('Les options d\'abonnement sont affichées via une case à cocher'),
                        'validation' => 'isBool',
                        'cast'       => 'intval',
                        'required'   => false,
                    ],
                    Ciklik::CONFIG_ENABLE_ENGAGEMENT => [
                        'type'       => 'bool',
                        'title'      => $this->l('Activer l\'engagement'),
                        'desc'       => $this->l('Cette option permet de désactiver le désabonnement pendant le cycle d’engagement'),
                        'validation' => 'isBool',
                        'cast'       => 'intval',
                        'required'   => false,
                    ],
                    Ciklik::CONFIG_ENGAGEMENT_INTERVAL => [
                        'type'       => 'select',
                        'title'      => $this->l('Cycle d’engagement'),
                        'identifier' => 'engagement_interval',
                        'cast' => 'strval',
                        'list'       => $intervals,
                    ],
                    Ciklik::CONFIG_ENGAGEMENT_INTERVAL_COUNT => [
                        'type'       => 'select',
                        'title'      => $this->l('Nombre de cycles d’engagement'),
                        'identifier' => 'engagement_interval_count',
                        'cast'       => 'intval',
                        'list'       =>  array_map(function($value) {
                            return ['engagement_interval_count' => $value, 'name'=> $value];
                        }, range(0,31)),
                    ],
                    Ciklik::CONFIG_ALLOW_CHANGE_NEXT_BILLING => [
                        'type'       => 'bool',
                        'title'      => $this->l('Autoriser la modification de la date du prochain paiement'),
                        'validation' => 'isBool',
                        'cast'       => 'intval',
                        'required'   => false,
                    ],
                    Ciklik::CONFIG_DEBUG_LOGS_ENABLED => [
                        'type'       => 'bool',
                        'title'      => $this->l('Enable debug logs'),
                        'validation' => 'isBool',
                        'cast'       => 'intval',
                        'required'   => false,
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                ],
            ],
        ];
    }

    public static function getCiklikPaidOrderStates($id_lang)
    {
        return Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS('
            SELECT *
            FROM ' . _DB_PREFIX_ . 'order_state os
            LEFT JOIN ' . _DB_PREFIX_ . 'order_state_lang osl ON (os.id_order_state = osl.id_order_state AND osl.id_lang = ' . (int)$id_lang . ')'
            . 'WHERE deleted = 0
                    AND paid = 1
                    AND pdf_invoice = 1
                    AND invoice = 1
            ORDER BY name ASC'
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

        if (isset($_POST[Ciklik::CONFIG_PRODUCT_NAME_SUFFIXES]) && is_array($_POST[Ciklik::CONFIG_PRODUCT_NAME_SUFFIXES])) {
            $_POST[Ciklik::CONFIG_PRODUCT_NAME_SUFFIXES] = implode(',', $_POST[Ciklik::CONFIG_PRODUCT_NAME_SUFFIXES] ?? []);
        } else {
            $_POST[Ciklik::CONFIG_PRODUCT_NAME_SUFFIXES] = [];
        }


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
                            'ciklik_encrypted_prestashop_token' => $webservice->key
                        ],
                        [
                            'headers' => [
                                'Authorization' => 'Bearer ' . Tools::getValue(Ciklik::CONFIG_API_TOKEN),
                            ]
                        ]
                    );
                }

                $this->confirmations[] = $this->trans('Connection successful', [], 'Modules.Ciklik.Admin');

            } catch (\Exception $e) {
                $this->errors[] = $this->trans('Connection failed', [$e->getMessage()], 'Modules.Ciklik.Admin');
            }
        }
    }
}
