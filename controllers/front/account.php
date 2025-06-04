<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

use PrestaShop\Module\Ciklik\Api\Subscription;
use PrestaShop\Module\Ciklik\Managers\CiklikCustomer;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CiklikAccountModuleFrontController extends ModuleFrontController
{
    /**
     * {@inheritdoc}
     */
    public $auth = true;

    /**
     * {@inheritdoc}
     */
    public $authRedirection = 'my-account';

    /**
     * {@inheritdoc}
     */
    public function initContent()
    {
        parent::initContent();

        $ciklik_customer = CiklikCustomer::getByIdCustomer((int) $this->context->customer->id);

        if (array_key_exists('ciklik_uuid', $ciklik_customer)
            && !is_null($ciklik_customer['ciklik_uuid'])) {
            $subscriptionsData = (new Subscription($this->context->link))
                ->getAll(['query' => ['filter' => ['customer_id' => $ciklik_customer['ciklik_uuid']]]]);
        }

        $this->context->smarty->assign([
            'subscriptions' => $subscriptionsData ?? [],
            'subcription_base_link' => Tools::getShopDomainSsl(true) . '/ciklik/subscription',
            'enable_engagement' => Configuration::get(Ciklik::CONFIG_ENABLE_ENGAGEMENT),
            'allow_change_next_billing' => Configuration::get(Ciklik::CONFIG_ALLOW_CHANGE_NEXT_BILLING),
            'engagement_interval' => Configuration::get(Ciklik::CONFIG_ENGAGEMENT_INTERVAL),
            'engagement_interval_count' => (int) Configuration::get(Ciklik::CONFIG_ENGAGEMENT_INTERVAL_COUNT),
            'addresses' => $this->context->customer->getAddresses($this->context->language->id),
            'enable_change_interval' => Configuration::get(Ciklik::CONFIG_ENABLE_CHANGE_INTERVAL),
            'use_frequency_mode' => Configuration::get(Ciklik::CONFIG_USE_FREQUENCY_MODE),
        ]);

        $this->setTemplate('module:ciklik/views/templates/front/account.tpl');
    }
}
