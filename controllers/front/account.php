<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

use PrestaShop\Module\Ciklik\Api\Subscription;
use PrestaShop\Module\Ciklik\Managers\CiklikCustomer;
use PrestaShop\Module\Ciklik\Managers\CiklikSubscriptionRelay;

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

        // Un seul lien de changement par abonnement : « Changer de point
        // relais » pour un abonnement livré en relais quand un relais peut
        // être proposé (recherche par adresse ou relais déjà utilisés
        // proposables) ; « Changer l'adresse » sinon, et pour la livraison à
        // domicile, comme avant 1.24.0
        //
        // Et, pour un abonnement en relais, le point relais que le prochain
        // renouvellement utilisera (surcharge), affiché à la place de l'adresse
        // de l'empreinte quand il en diffère. Lecture en base, sans appel à
        // l'API.
        $relaySubscriptions = [];
        $nextDeliveryRelays = [];
        $idCustomer = (int) $this->context->customer->id;
        $choicesByModule = [];
        foreach ($subscriptionsData ?? [] as $subscription) {
            $resolved = CiklikSubscriptionRelay::resolveCarrier($subscription);

            if (null !== $resolved) {
                $nextRelay = CiklikSubscriptionRelay::nextDeliveryRelay($idCustomer, $resolved['module'], $subscription->address);
                if (null !== $nextRelay) {
                    $nextDeliveryRelays[$subscription->uuid] = $nextRelay;
                }
            }

            $linkType = CiklikSubscriptionRelay::changeLinkType(
                null !== $resolved,
                function () use ($subscription, $resolved, $idCustomer, &$choicesByModule) {
                    // La recherche dépend aussi du pays de livraison (DPD : France seulement)
                    $countryIso = CiklikSubscriptionRelay::rebillCountryIso($subscription, $idCustomer);
                    $choiceKey = $resolved['module'] . '|' . $countryIso;
                    if (!isset($choicesByModule[$choiceKey])) {
                        $choicesByModule[$choiceKey] = CiklikSubscriptionRelay::hasChoices($idCustomer, $resolved['module'], $countryIso);
                    }

                    return $choicesByModule[$choiceKey];
                }
            );

            if (CiklikSubscriptionRelay::CHANGE_RELAY === $linkType) {
                $relaySubscriptions[$subscription->uuid] = $this->context->link->getModuleLink(
                    'ciklik',
                    'subscription',
                    ['uuid' => $subscription->uuid, 'action' => 'relay']
                );
            }
        }

        $this->context->smarty->assign([
            'subscriptions' => $subscriptionsData ?? [],
            'relay_subscriptions' => $relaySubscriptions,
            'next_delivery_relays' => $nextDeliveryRelays,
            'subcription_base_link' => Tools::getShopDomainSsl(true) . '/ciklik/subscription',
            'enable_engagement' => Configuration::get(Ciklik::CONFIG_ENABLE_ENGAGEMENT),
            'allow_change_next_billing' => Configuration::get(Ciklik::CONFIG_ALLOW_CHANGE_NEXT_BILLING),
            'enable_skip_next_delivery' => Configuration::get(Ciklik::CONFIG_ENABLE_SKIP_NEXT_DELIVERY),
            'engagement_interval' => Configuration::get(Ciklik::CONFIG_ENGAGEMENT_INTERVAL),
            'engagement_interval_count' => (int) Configuration::get(Ciklik::CONFIG_ENGAGEMENT_INTERVAL_COUNT),
            'addresses' => $this->context->customer->getAddresses($this->context->language->id),
            'enable_change_interval' => Configuration::get(Ciklik::CONFIG_ENABLE_CHANGE_INTERVAL),
            'use_frequency_mode' => Configuration::get(Ciklik::CONFIG_USE_FREQUENCY_MODE),
            'token' => Tools::getToken(false),
            'next_billing_min' => date('Y-m-d', strtotime('+1 day')),
            'next_billing_max' => date('Y-m-d', strtotime('+6 months')),
        ]);
        $this->module->assignThemeVariables();

        $this->setTemplate('module:ciklik/views/templates/front/account.tpl');
    }
}
