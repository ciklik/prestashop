<?php
/**
 * @author    Ciklik SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */
$autoloadPath = __DIR__ . '/vendor/autoload.php';

if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}

use PrestaShop\Module\Ciklik\Addons\Account;
use PrestaShop\Module\Ciklik\Api\Shop;
use PrestaShop\Module\Ciklik\Data\PaymentMethodData;
use PrestaShop\Module\Ciklik\Data\ShopData;
use PrestaShop\Module\Ciklik\Install\Installer;
use PrestaShop\Module\Ciklik\Managers\CiklikAttribute;
use PrestaShop\Module\Ciklik\Managers\CiklikCombination;
use PrestaShop\Module\Ciklik\Managers\CiklikFrequency;
use PrestaShop\Module\Ciklik\Managers\CiklikRefund;
use PrestaShop\Module\Ciklik\Managers\CiklikSubscribable;
use PrestaShop\PrestaShop\Core\Payment\PaymentOption;
use PrestaShop\Module\Ciklik\Api\Subscription;
use PrestaShop\Module\Ciklik\Managers\CiklikCustomer;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Ciklik extends PaymentModule
{
    use Account;

    const VERSION = '1.6.5';
    const CONFIG_API_TOKEN = 'CIKLIK_API_TOKEN';
    const CONFIG_MODE = 'CIKLIK_MODE';
    const CONFIG_HOST = 'CIKLIK_HOST';
    const CONFIG_PURCHASE_TYPE_ATTRIBUTE_GROUP_ID = 'CIKLIK_PURCHASE_TYPE_ATTRIBUTE_GROUP_ID';
    const CONFIG_FREQUENCIES_ATTRIBUTE_GROUP_ID = 'CIKLIK_FREQUENCIES_ATTRIBUTE_GROUP_ID';
    const CONFIG_ONEOFF_ATTRIBUTE_ID = 'CIKLIK_ONEOFF_ATTRIBUTE_ID';
    const CONFIG_SUBSCRIPTION_ATTRIBUTE_ID = 'CIKLIK_SUBSCRIPTION_ATTRIBUTE_ID';
    const CONFIG_DEFAULT_SUBSCRIPTION_ATTRIBUTE_ID = 'CIKLIK_DEFAULT_SUBSCRIPTION_ATTRIBUTE_ID';
    const CONFIG_PRODUCT_NAME_SUFFIXES = 'CIKLIK_PRODUCT_NAME_SUFFIXES';
    const CONFIG_WEBSERVICE_ID = 'CIKLIK_WEBSERVICE_ID';
    const CONFIG_DELEGATE_OPTIONS_DISPLAY = 'CIKLIK_DELEGATE_OPTIONS_DISPLAY';
    const CONFIG_DEBUG_LOGS_ENABLED = 'CIKLIK_DEBUG_LOGS_ENABLED';
    const MODULE_ADMIN_CONTROLLER = 'AdminConfigureCiklik';
    const CONFIG_ORDER_STATE = 'CIKLIK_ORDER_STATE';
    const CONFIG_ENABLE_ENGAGEMENT = 'CIKLIK_ENABLE_ENGAGEMENT';
    const CONFIG_ENGAGEMENT_INTERVAL = 'CIKLIK_ENGAGEMENT_INTERVAL';
    const CONFIG_ENGAGEMENT_INTERVAL_COUNT = 'CIKLIK_ENGAGEMENT_INTERVAL_COUNT';
    const CONFIG_ALLOW_CHANGE_NEXT_BILLING = 'CIKLIK_ALLOW_CHANGE_NEXT_BILLING';
    const CONFIG_ENABLE_CUSTOMER_GROUP_ASSIGNMENT = 'CIKLIK_ENABLE_CUSTOMER_GROUP_ASSIGNMENT';
    const CONFIG_CUSTOMER_GROUP_TO_ASSIGN = 'CIKLIK_CUSTOMER_GROUP_TO_ASSIGN';
    const CONFIG_ENABLE_CHANGE_INTERVAL = 'CIKLIK_ENABLE_CHANGE_INTERVAL';
    const CONFIG_ENABLE_UPSELL = 'CIKLIK_ENABLE_UPSELL';
    /**
     * @var \Monolog\Logger
     */
    private $logger;
    private $container;

    public function __construct()
    {
        $this->name = 'ciklik';
        $this->tab = 'payments_gateways';
        $this->version = '1.6.5';
        $this->author = 'Ciklik';
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->ps_versions_compliancy = [
            'min' => '1.7.7',
            'max' => '8.99.99',
        ];
        $this->controllers = [
            'account',
            'cancel',
            'external',
            'validation',
        ];
        $this->module_key = 'ad787a7f180e5ed32bf2effd4ca36520';

        parent::__construct();

        $this->displayName = $this->l('Ciklik');
        $this->description = $this->l('Description');
        if ($this->container === null) {
            $this->container = new PrestaShop\ModuleLibServiceContainer\DependencyInjection\ServiceContainer(
                $this->name,
                $this->getLocalPath()
            );
        }
    }

    /**
     * @return bool
     */
    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        $this->getService('prestashop.module.ciklik.ps_accounts_installer')->install();

        return (new Installer())->install($this);
    }

    /**
     * @return bool
     */
    public function uninstall()
    {
        $installer = new Installer();

        return $installer->uninstall() && parent::uninstall();
    }

    /**
     * Module configuration page
     */
    public function getContent()
    {
        if (self::isCiklikAddonsBuild()) {
            // Load dependencies manager
            $mboInstaller = new \Prestashop\ModuleLibMboInstaller\DependencyBuilder($this);

            if (!$mboInstaller->areDependenciesMet()) {
                $dependencies = $mboInstaller->handleDependencies();

                $this->smarty->assign('dependencies', $dependencies);

                return $this->display(__FILE__, 'views/templates/admin/dependency_builder.tpl');
            }
        }
        Tools::redirectAdmin($this->context->link->getAdminLink(static::MODULE_ADMIN_CONTROLLER));
    }

    /**
     * This hook called after a new Attribute is created
     *
     * @param array $params
     */
    public function hookActionAttributeSave(array $params)
    {
        if (CiklikAttribute::isFrequencyAttribute((int) $params['id_attribute'])) {
            CiklikFrequency::save(
                (int) $params['id_attribute'],
                Tools::getValue('interval'),
                (int) Tools::getValue('interval_count'),
                (int) Tools::getValue('id_frequency', 0)
            );
        }
    }

    /**
     * This hook called after an Attribute is deleted
     *
     * @param array $params
     */
    public function hookActionAttributeDelete(array $params)
    {
        CiklikFrequency::deleteByIdAttribute((int) $params['id_attribute']);
    }

    /**
     * This hook called after a new Shop is created
     *
     * @param array $params
     */
    public function hookActionObjectShopAddAfter(array $params)
    {
        if (empty($params['object'])) {
            return;
        }

        /** @var Shop $shop */
        $shop = $params['object'];

        if (false === Validate::isLoadedObject($shop)) {
            return;
        }

        $this->addCheckboxCarrierRestrictionsForModule([(int) $shop->id]);
        $this->addCheckboxCountryRestrictionsForModule([(int) $shop->id]);

        if ($this->currencies_mode === 'checkbox') {
            $this->addCheckboxCurrencyRestrictionsForModule([(int) $shop->id]);
        } elseif ($this->currencies_mode === 'radio') {
            $this->addRadioCurrencyRestrictionsForModule([(int) $shop->id]);
        }
    }

    /**
     * @param array $params
     */
    public function hookDisplayAttributeForm(array $params)
    {
        if (CiklikAttribute::isFrequencyAttribute((int) $params['id_attribute'])) {
            $translator = $this->getTranslator();

            $this->context->smarty->assign([
                'frequency' => CiklikFrequency::getByIdAttribute((int) $params['id_attribute']),
                'intervals' => [
                    [
                        'label' => $translator->trans('Mensuel', [], 'Modules.Ciklik'),
                        'value' => 'month',
                    ],
                    [
                        'label' => $translator->trans('Hebdomadaire', [], 'Modules.Ciklik'),
                        'value' => 'week',
                    ],
                    [
                        'label' => $translator->trans('Journalier', [], 'Modules.Ciklik'),
                        'value' => 'day',
                    ],
                ],

                'interval_counts' => range(1, 12),
            ]);

            return $this->context->smarty->fetch('module:ciklik/views/templates/hook/displayAttributeForm.tpl');
        }
    }

    /*
    * Hook for 1.8+
    */
    public function hookActionAfterUpdateProductFormHandler(array $params): void
    {
        CiklikSubscribable::handle((int) $params['form_data']['id']);
    }

    /*
     * Hook for 1.7.7+
     */
    public function hookActionObjectProductUpdateAfter(array $params): void
    {
        CiklikSubscribable::handle((int) $params['object']->id);
    }

    public function hookActionObjectProductAddAfter(array $params): void
    {
        CiklikSubscribable::handle((int) $params['object']->id);
    }

    public function hookActionProductDelete(array $params): void
    {
        if (isset($params['id_product'])) {
            CiklikSubscribable::deleteByIdProduct((int) $params['id_product']);
        } else {
            CiklikSubscribable::handle((int) $params['object']->id);
        }
    }

    public function hookActionPresentPaymentOptions(array &$params)
    {
        /** @var Cart $cart */
        $cart = $params['cart'];

        if (CiklikSubscribable::cartHasSubscribable($cart)) {
            foreach ($params['paymentOptions'] as $module => $paymentOption) {
                if ($this->name !== $module) {
                    unset($params['paymentOptions'][$module]);
                }
            }
        }
    }

    /**
     * @param array $params
     *
     * @return array Should always return an array
     */
    public function hookPaymentOptions(array $params)
    {
        /** @var Cart $cart */
        $cart = $params['cart'];

        if (false === Validate::isLoadedObject($cart) || false === $this->checkCurrency($cart)) {
            return [];
        }

        $paymentOptions = [];

        if (CiklikSubscribable::cartHasSubscribable($cart)) {
            $shopData = (new Shop($this->context->link))->whoIAm();

            if ($shopData instanceof ShopData && count($shopData->paymentMethods)) {
                $language = new Language($this->context->cart->id_lang);

                foreach ($shopData->paymentMethods as $method) {
                    $paymentOptions[] = $this->mountPaymentOption($method, $language);
                }
            }
        }

        return $paymentOptions;
    }

    private function mountPaymentOption(PaymentMethodData $method, Language $language)
    {
        $paymentOption = new PaymentOption();
        $paymentOption->setModuleName($this->name);
        $paymentOption->setCallToActionText(
            array_key_exists($language->iso_code, $method->name)
                ? $method->name[$language->iso_code]
                : $method->name[current(array_keys($method->name))]
        );
        $paymentOption->setAction($this->context->link->getModuleLink($this->name, 'external', [], true));

        $paymentOption->setInputs([
            'token' => [
                'name' => 'class_key',
                'type' => 'hidden',
                'value' => $method->class_key,
            ],
        ]);

        $paymentOption->setAdditionalInformation($this->context->smarty->fetch("module:ciklik/views/templates/front/paymentOption{$method->class_key}.tpl"));
        $paymentOption->setLogo(Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . "/views/img/option/{$method->class_key}.png"));

        return $paymentOption;
    }

    /**
     * This hook is used to display additional information on BO Order View, under Payment block
     *
     * @since PrestaShop 1.7.7 This hook replace displayAdminOrderLeft on migrated BO Order View
     *
     * @param array $params
     *
     * @return string
     */
    public function hookDisplayAdminOrderMainBottom(array $params)
    {
        if (empty($params['id_order'])) {
            return '';
        }

        $order = new Order((int) $params['id_order']);

        if (false === Validate::isLoadedObject($order) || $order->module !== $this->name) {
            return '';
        }

        require_once dirname(__FILE__) . '/controllers/hook/DisplayRefundsHookController.php';

        $controller = new DisplayRefundsHookController($this);

        if (CiklikRefund::canRun()) {
            return $controller->run($params);
        } else {
            return null;
        }
    }

    /**
     * This hook is used to display information in customer account
     *
     * @param array $params
     *
     * @return string
     */
    public function hookDisplayCustomerAccount(array $params)
    {
        $this->context->smarty->assign([
            'transactionsLink' => $this->context->link->getModuleLink(
                $this->name,
                'account'
            ),
        ]);

        return $this->context->smarty->fetch('module:ciklik/views/templates/hook/displayCustomerAccount.tpl');
    }

    /**
     * This hook is used to display additional information on order confirmation page
     *
     * @param array $params
     *
     * @return string
     */
    public function hookDisplayOrderConfirmation(array $params)
    {
        if (empty($params['order'])) {
            return '';
        }

        /** @var Order $order */
        $order = $params['order'];

        if (false === Validate::isLoadedObject($order) || $order->module !== $this->name) {
            return '';
        }

        $transaction = '';

        if ($order->getOrderPaymentCollection()->count()) {
            /** @var OrderPayment $orderPayment */
            $orderPayment = $order->getOrderPaymentCollection()->getFirst();
            $transaction = $orderPayment->transaction_id;
        }

        $this->context->smarty->assign([
            'moduleName' => $this->name,
            'transaction' => $transaction,
        ]);

        return $this->context->smarty->fetch('module:ciklik/views/templates/hook/displayOrderConfirmation.tpl');
    }

    /**
     * This hook is used to display additional information on FO (Guest Tracking and Account Orders)
     *
     * @param array $params
     *
     * @return string
     */
    public function hookDisplayOrderDetail(array $params)
    {
        if (empty($params['order'])) {
            return '';
        }

        /** @var Order $order */
        $order = $params['order'];

        if (false === Validate::isLoadedObject($order) || $order->module !== $this->name) {
            return '';
        }

        $transaction = '';

        if ($order->getOrderPaymentCollection()->count()) {
            /** @var OrderPayment $orderPayment */
            $orderPayment = $order->getOrderPaymentCollection()->getFirst();
            $transaction = $orderPayment->transaction_id;
        }

        $this->context->smarty->assign([
            'moduleName' => $this->name,
            'transaction' => $transaction,
        ]);

        return $this->context->smarty->fetch('module:ciklik/views/templates/hook/displayOrderDetail.tpl');
    }

    /**
     * This hook is used to display additional information on bottom of order confirmation page
     *
     * @param array $params
     *
     * @return string
     */
    public function hookDisplayPaymentReturn(array $params)
    {
        if (empty($params['order'])) {
            return '';
        }

        /** @var Order $order */
        $order = $params['order'];

        if (false === Validate::isLoadedObject($order) || $order->module !== $this->name) {
            return '';
        }

        $transaction = '';

        if ($order->getOrderPaymentCollection()->count()) {
            /** @var OrderPayment $orderPayment */
            $orderPayment = $order->getOrderPaymentCollection()->getFirst();
            $transaction = $orderPayment->transaction_id;
        }

        $this->context->smarty->assign([
            'moduleName' => $this->name,
            'transaction' => $transaction,
            'transactionsLink' => $this->context->link->getModuleLink(
                $this->name,
                'account'
            ),
        ]);

        return $this->context->smarty->fetch('module:ciklik/views/templates/hook/displayPaymentReturn.tpl');
    }

    /**
     * This hook is used to display additional information on Invoice PDF
     *
     * @param array $params
     *
     * @return string
     */
    public function hookDisplayPDFInvoice(array $params)
    {
        if (empty($params['object'])) {
            return '';
        }

        /** @var OrderInvoice $orderInvoice */
        $orderInvoice = $params['object'];

        if (false === Validate::isLoadedObject($orderInvoice)) {
            return '';
        }

        $order = $orderInvoice->getOrder();

        if (false === Validate::isLoadedObject($order) || $order->module !== $this->name) {
            return '';
        }

        $transaction = '';

        if ($order->getOrderPaymentCollection()->count()) {
            /** @var OrderPayment $orderPayment */
            $orderPayment = $order->getOrderPaymentCollection()->getFirst();
            $transaction = $orderPayment->transaction_id;
        }

        $this->context->smarty->assign([
            'moduleName' => $this->name,
            'transaction' => $transaction,
        ]);

        return $this->context->smarty->fetch('module:ciklik/views/templates/hook/displayPDFInvoice.tpl');
    }

    public function hookModuleRoutes()
    {
        return [
            'module-ciklik-action' => [
                'rule' => 'ciklik/gateway/{request}',
                'keywords' => [
                    'request' => [
                        'regexp' => '.*',
                        'param' => 'request',
                    ],
                ],
                'controller' => 'gateway',
                'params' => [
                    'fc' => 'module',
                    'module' => $this->name,
                ],
            ],

            'module-ciklik-subscription' => [
                'rule' => 'ciklik/subscription/{uuid}/{action}',
                'keywords' => [
                    'uuid' => [
                        'regexp' => '.*',
                        'param' => 'uuid',
                    ],
                    'action' => [
                        'regexp' => '.*',
                        'param' => 'action',
                    ],
                ],
                'controller' => 'subscription',
                'params' => [
                    'fc' => 'module',
                    'module' => $this->name,
                ],
            ],

            'module-ciklik-rebill' => [
                'rule' => 'ciklik/rebill/{ciklik_order_id}',
                'keywords' => [
                    'ciklik_order_id' => [
                        'regexp' => '[0-9]*',
                        'param' => 'ciklik_order_id',
                    ],
                ],
                'controller' => 'rebill',
                'params' => [
                    'fc' => 'module',
                    'module' => $this->name,
                ],
            ],

            'module-ciklik-refund' => [
                'rule' => 'ciklik/refund',
                'controller' => 'refund',
                'params' => [
                    'fc' => 'module',
                    'module' => $this->name,
                ],
            ],
        ];
    }

    public function hookActionGetProductPropertiesBefore(array $params)
    {
        if (array_key_exists('quantity_wanted', $params['product'])) {
            $ciklik_attributes = static::getCiklikProductAttributes($params['product'], $this->context);

            if (count($ciklik_attributes) && isset($ciklik_attributes['current_id_product_attribute'])) {
                $params['product']['id_product_attribute'] = $ciklik_attributes['current_id_product_attribute'];
                $params['product']['ciklik'] = $ciklik_attributes;
            }
        }

        // Vérifie si la fonctionnalité d'upsell est activée dans la configuration
        // et si l'utilisateur est connecté (pas un employé)
        if ((bool) Configuration::get(self::CONFIG_ENABLE_UPSELL)
            && $this->context->controller instanceof ProductController // On ignore si nous sommes sur une page de catégorie
            && $this->context->customer !== null 
            && $this->context->customer->isLogged()) {
            // Récupère les informations du client Ciklik à partir de l'ID client PrestaShop
            $ciklik_customer = CiklikCustomer::getByIdCustomer((int) $this->context->customer->id);
            $subscriptions = [];

            // Si le client a un UUID Ciklik valide
            if (array_key_exists('ciklik_uuid', $ciklik_customer)
                && !is_null($ciklik_customer['ciklik_uuid'])) {
                // Récupère tous les abonnements actifs du client
                $subscriptionsData = (new Subscription($this->context->link))
                    ->getAll(['query' => ['filter' => ['customer_id' => $ciklik_customer['ciklik_uuid']]]]);
                
                // Si des abonnements sont trouvés, on les stocke
                if (!empty($subscriptionsData)) {
                    $subscriptions = $subscriptionsData;
                }
            }

            // Ajoute les informations d'upsell aux paramètres du produit :
            // - la liste des abonnements disponibles
            // - active la fonctionnalité d'upsell
            // - l'URL de base pour les actions sur l'abonnement
            $params['product']['available_subscriptions'] = $subscriptions;
            $params['product']['upsell'] = count($subscriptions) > 0 ? true : false;
            $params['product']['subcription_base_link'] = Tools::getShopDomainSsl(true) . '/ciklik/subscription';
        }
    }

    public function hookDisplayProductActions(array $params)
    {
        // Vérifie si le produit peut être proposé à l'upsell
        if (!empty($params['product']['upsell']) && $params['product']['upsell'] === true && !Pack::isPack($params['product']['id_product'])) {
            // Affiche le template pour les actions du produit pour proposer l'upsell
            return $this->context->smarty->fetch('module:ciklik/views/templates/hook/displayProductActions.tpl', ['product' => $params['product']]);
        }
    }

    public static function getCiklikProductAttributes($product, Context $context)
    {
        $query = new DbQuery();
        $query->select('pac.id_attribute, a.id_attribute_group');
        $query->from('product_attribute_combination', 'pac');
        $query->leftJoin('attribute', 'a', 'a.id_attribute = pac.id_attribute');
        $query->where('pac.id_product_attribute = ' . (int) $product['id_product_attribute']);
        $attributes = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($query);

        $ciklik_attributes = [
            'id_product' => $product['id_product'],
        ];

        $purchase_type_attribute = null;
        $frequency_attribute = null;
        $constraint_attributes_ids = [];

        if (count($attributes)) {
            foreach ($attributes as $attribute) {
                switch ($attribute['id_attribute_group']) {
                    case (int) Configuration::get(self::CONFIG_PURCHASE_TYPE_ATTRIBUTE_GROUP_ID):
                        $purchase_type_attribute = $attribute;
                        break;
                    case (int) Configuration::get(self::CONFIG_FREQUENCIES_ATTRIBUTE_GROUP_ID):
                        $frequency_attribute = $attribute;
                        break;
                    default:
                        $constraint_attributes_ids[] = (int) $attribute['id_attribute'];
                }
            }

            if (null !== $purchase_type_attribute) {
                if ((int) $purchase_type_attribute['id_attribute'] === (int) Configuration::get(self::CONFIG_ONEOFF_ATTRIBUTE_ID)) {
                    $ciklik_attributes['enabled'] = true;
                    $ciklik_attributes['selected'] = false;
                    $ciklik_attributes['reference_id_product_attribute'] = (int) $product['id_product_attribute'];
                    $ciklik_attributes['current_id_product_attribute'] = $ciklik_attributes['reference_id_product_attribute'];
                    $ciklik_attributes['subscription_reference_price'] = Product::getPriceStatic((int) $product['id_product'], true, (int) $product['id_product_attribute']);
                    $ciklik_attributes['frequency_id_attribute'] = (int) Configuration::get(self::CONFIG_DEFAULT_SUBSCRIPTION_ATTRIBUTE_ID);

                    $attribute = CiklikCombination::getOne(
                        (int) $product['id_product'],
                        $ciklik_attributes['frequency_id_attribute'],
                        $constraint_attributes_ids
                    );

                    if (!is_array($attribute) || !(int) $attribute['id_product_attribute']) {
                        return [];
                    }

                    $ciklik_attributes['id_product_attribute'] = (int) $attribute['id_product_attribute'];

                    if (Tools::getValue('action') === 'refresh') {
                        if ((bool) Configuration::get(self::CONFIG_DELEGATE_OPTIONS_DISPLAY) && Tools::getValue('ciklik')) {
                            $ciklik_attributes['selected'] = true;
                            $ciklik_attributes['current_id_product_attribute'] = $ciklik_attributes['id_product_attribute'];
                        } else {
                            $ciklik_attributes['selected'] = false;
                            $ciklik_attributes['current_id_product_attribute'] = $ciklik_attributes['reference_id_product_attribute'];
                        }
                    }
                } elseif ((int) $purchase_type_attribute['id_attribute'] === (int) Configuration::get(self::CONFIG_SUBSCRIPTION_ATTRIBUTE_ID)) {
                    $ciklik_attributes['frequency_id_attribute'] = (int) $frequency_attribute['id_attribute'];
                    $ciklik_attributes['enabled'] = true;
                    $ciklik_attributes['selected'] = true;
                    $ciklik_attributes['id_product_attribute'] = (int) $product['id_product_attribute'];
                    $ciklik_attributes['current_id_product_attribute'] = $ciklik_attributes['id_product_attribute'];

                    $attribute = CiklikCombination::getOne(
                        (int) $product['id_product'],
                        (int) Configuration::get(self::CONFIG_ONEOFF_ATTRIBUTE_ID),
                        $constraint_attributes_ids
                    );

                    if (!is_array($attribute) || !(int) $attribute['id_product_attribute']) {
                        return [];
                    }

                    $ciklik_attributes['reference_id_product_attribute'] = (int) $attribute['id_product_attribute'];
                    $ciklik_attributes['subscription_reference_price'] = Product::getPriceStatic((int) $product['id_product'], true, $ciklik_attributes['reference_id_product_attribute']);

                    if (Tools::getValue('action') === 'refresh') {
                        if ((bool) Configuration::get(self::CONFIG_DELEGATE_OPTIONS_DISPLAY) && !Tools::getValue('ciklik')) {
                            $ciklik_attributes['selected'] = false;
                            $ciklik_attributes['current_id_product_attribute'] = $ciklik_attributes['reference_id_product_attribute'];
                        } else {
                            $ciklik_attributes['selected'] = true;
                            $ciklik_attributes['current_id_product_attribute'] = $ciklik_attributes['id_product_attribute'];
                        }
                    }
                } else {
                    return [];
                }

                $ciklik_attributes['subscription_price'] = Product::getPriceStatic((int) $product['id_product'], true, $ciklik_attributes['id_product_attribute']);
                $ciklik_attributes['discount_percentage'] = floor((($ciklik_attributes['subscription_reference_price'] - $ciklik_attributes['subscription_price']) / $ciklik_attributes['subscription_reference_price']) * 100);
            }
        }

        return $ciklik_attributes;
    }

    /**
     * Check if currency is allowed in Payment Preferences
     *
     * @param Cart $cart
     *
     * @return bool
     */
    private function checkCurrency(Cart $cart)
    {
        $currency_order = new Currency($cart->id_currency);
        /** @var array $currencies_module */
        $currencies_module = $this->getCurrency($cart->id_currency);

        if (empty($currencies_module)) {
            return false;
        }

        foreach ($currencies_module as $currency_module) {
            if ($currency_order->id == $currency_module['id_currency']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Monolog\Logger
     */
    public function getLogger()
    {
        if (null !== $this->logger) {
            return $this->logger;
        }

        $this->logger = PrestaShop\Module\Ciklik\Factory\CiklikLogger::create();

        return $this->logger;
    }

    public function getService($serviceName)
    {
        return $this->container->getService($serviceName);
    }
}
