<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

use PrestaShop\Module\Ciklik\Data\CartSubscriptionData;
use PrestaShop\Module\Ciklik\Data\SubscriptionData;
use PrestaShop\Module\Ciklik\Managers\CiklikDeliveryOverride;
use PrestaShop\Module\Ciklik\Managers\CiklikRelaySearch;
use PrestaShop\Module\Ciklik\Managers\DeliveryModuleManager;

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
            try {
                $subscription = SubscriptionData::create($ciklikSubscriptionData['body']);
            } catch (\InvalidArgumentException $e) {
                // Fingerprint dans un ancien format (serialize) non encore migré côté app
                $subscription = null;
            }
        }

        // Récupérer les informations d'abonnement de la commande
        $subscriptionInfos = [];
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

        // Bloc « point relais des prochains prélèvements » : uniquement si le
        // transporteur des rebills (celui du fingerprint, pas celui de la
        // commande affichée) est un module relais supporté.
        $relayVars = $this->buildRelayOverrideVars($subscription, (int) $order->id_customer, (int) $order->id_shop);

        $this->context->smarty->assign(array_merge([
            'subscription_items' => $subscriptionInfos,
            'moduleLogoSrc' => $this->module->getPathUri() . 'logo.png',
            'moduleDisplayName' => $this->module->displayName,
            'subscription' => $subscription,
            'ciklik_order_url' => 'https://app.ciklik.co/app/resources/checkout-orders/' . $orderData->ciklik_order_id,
            'manageActionUrl' => $this->context->link->getModuleLink('ciklik', 'manage'),
            'manageAjaxToken' => sha1(_COOKIE_KEY_ . 'ciklik_manage'),
        ], $relayVars));

        return $this->context->smarty->fetch('module:ciklik/views/templates/admin/order_subscription_info.tpl');
    }

    /**
     * Prépare les variables Smarty du bloc de changement de point relais.
     *
     * @param SubscriptionData|null $subscription
     * @param int $idCustomer
     * @param int $idShop Boutique de la commande (contexte des credentials en multiboutique)
     *
     * @return array
     */
    private function buildRelayOverrideVars($subscription, $idCustomer, $idShop = 0)
    {
        $vars = [
            'ciklik_relay_supported' => false,
            'ciklik_relay_module' => '',
            'ciklik_relay_carrier_name' => '',
            'ciklik_relay_customer_id' => $idCustomer,
            'ciklik_relay_shop_id' => $idShop,
            'ciklik_relay_current' => null,
            'ciklik_relay_has_override' => false,
            'ciklik_relay_known' => [],
            'ciklik_relay_search_supported' => false,
            'ciklik_relay_prefill' => ['zipcode' => '', 'city' => ''],
            'ciklik_relay_assets_path' => $this->module->getPathUri(),
        ];

        if (!$subscription
            || !$subscription->external_fingerprint
            || empty($subscription->external_fingerprint->id_carrier_reference)) {
            return $vars;
        }

        $carrier = Carrier::getCarrierByReference((int) $subscription->external_fingerprint->id_carrier_reference);

        if (!$carrier || empty($carrier->external_module_name)) {
            return $vars;
        }

        $module = strtolower($carrier->external_module_name);

        if (!in_array($module, CiklikDeliveryOverride::SUPPORTED_MODULES, true)) {
            return $vars;
        }

        $override = CiklikDeliveryOverride::get($idCustomer, $module);

        if ($override) {
            $payload = $override['payload'];
            $current = [
                'source' => 'override',
                'relay_id' => $override['relay_id'],
                'label' => trim(
                    (isset($payload['name']) ? $payload['name'] : '')
                    . ' - ' . (isset($payload['city']) ? $payload['city'] : ''),
                    ' -'
                ),
            ];
        } else {
            $peek = DeliveryModuleManager::peekLegacyRelay($idCustomer, $module);
            $current = $peek ? array_merge(['source' => 'auto'], $peek) : null;
        }

        $vars['ciklik_relay_supported'] = true;
        $vars['ciklik_relay_module'] = $module;
        $vars['ciklik_relay_carrier_name'] = $carrier->name;
        $vars['ciklik_relay_current'] = $current;
        $vars['ciklik_relay_has_override'] = (bool) $override;
        $vars['ciklik_relay_known'] = DeliveryModuleManager::getKnownRelays($idCustomer, $module);
        $vars['ciklik_relay_search_supported'] = CiklikRelaySearch::supportsSearch($module);

        // Pré-remplissage de la recherche avec l'adresse de livraison de l'abonnement
        if ($subscription->address) {
            $vars['ciklik_relay_prefill'] = [
                'zipcode' => (string) $subscription->address->postcode,
                'city' => (string) $subscription->address->city,
            ];
        }

        return $vars;
    }
}
