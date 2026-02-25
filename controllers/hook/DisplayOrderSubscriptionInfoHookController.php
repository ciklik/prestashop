<?php

/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

use PrestaShop\Module\Ciklik\Data\CartSubscriptionData;
use PrestaShop\Module\Ciklik\Data\SubscriptionData;

if (!defined('_PS_VERSION_')) {
    exit;
}

class DisplayOrderSubscriptionInfoHookController
{
    /** @var Ciklik */
    protected $module;

    /** @var Context */
    protected $context;

    /**
     * @param $module Ciklik
     */
    public function __construct($module)
    {
        $this->module = $module;
        $this->context = Context::getContext();
    }

    public function run($params)
    {
        if (empty($params['id_order'])) {
            return '';
        }

        $order = new Order((int) $params['id_order']);

        if (false === Validate::isLoadedObject($order)) {
            return '';
        }

        $orderData = (new PrestaShop\Module\Ciklik\Api\Order($this->context->link))->getOneByPsOrderId((int) $params['id_order']);

        $subscription = null;

        if (
            $orderData !== null
            && ($ciklikSubscriptionData = (new PrestaShop\Module\Ciklik\Api\Subscription($this->context->link))->getOne($orderData->subscription_uuid))
            && isset($ciklikSubscriptionData['body']['external_fingerprint'])
        ) {
            $subscription = SubscriptionData::create($ciklikSubscriptionData['body']);
        }

        // Récupérer les informations d'abonnement de la commande
        $subscriptionData = CartSubscriptionData::fromOrder($order);

        // Si on est sur le mode declinason, cette option n'est pas disponible
        if (!Configuration::get(Ciklik::CONFIG_USE_FREQUENCY_MODE) || !$subscriptionData->hasSubscribableItems()) {
            $subscriptionInfos = [];
        }

        if (Configuration::get(Ciklik::CONFIG_USE_FREQUENCY_MODE) && $subscriptionData->hasSubscribableItems()) {
            $subscriptionInfos = [];
            foreach ($subscriptionData->getItems() as $item) {
                if (!empty($item['frequency'])) {
                    $product = new Product($item['id_product']);
                    $subscriptionInfos[$item['id_product']] = [
                        'name' => $product->name[$this->context->language->id],
                        'frequency' => $item['frequency']['name'],
                        'interval' => $item['frequency']['interval'],
                        'quantity' => $item['quantity'],
                        'interval_count' => $item['frequency']['interval_count'],
                        'discount_percent' => $item['frequency']['discount_percent'],
                        'discount_price' => $item['frequency']['discount_price'],
                    ];
                }
            }
        }

        // Ne rien afficher si ce n'est pas une commande Ciklik
        if ($orderData === null) {
            return '';
        }

        $this->context->smarty->assign([
            'subscription_items' => $subscriptionInfos,
            'moduleLogoSrc' => $this->module->getPathUri() . 'logo.png',
            'moduleDisplayName' => $this->module->displayName,
            'subscription' => $subscription,
            'ciklik_order_url' => 'https://app.ciklik.co/app/resources/checkout-orders/' . $orderData->ciklik_order_id,
        ]);

        return $this->context->smarty->fetch('module:ciklik/views/templates/admin/order_subscription_info.tpl');
    }
}
