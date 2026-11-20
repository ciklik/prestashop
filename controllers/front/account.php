<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

use PrestaShop\Module\Ciklik\Api\CiklikApiResponseHandler;
use PrestaShop\Module\Ciklik\Api\Subscription;
use PrestaShop\Module\Ciklik\Data\PendingPaymentData;
use PrestaShop\Module\Ciklik\Data\SubscriptionData;
use PrestaShop\Module\Ciklik\Helpers\UuidHelper;
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

    /** @var string|null Message de la liste illisible, posé par fetchSubscriptions() */
    private $unavailableMessage;

    /**
     * {@inheritdoc}
     */
    public function initContent()
    {
        parent::initContent();

        $ciklik_customer = CiklikCustomer::getByIdCustomer((int) $this->context->customer->id);
        $ciklikUuid = (string) ($ciklik_customer['ciklik_uuid'] ?? '');
        $pendingPayments = [];
        $subscriptionsUnavailable = false;

        // UUID vérifié : un filtre vide renverrait les abonnements de toute la boutique
        if (UuidHelper::isValid($ciklikUuid)) {
            $body = $this->fetchSubscriptions($ciklikUuid);

            if (null === $body) {
                $subscriptionsUnavailable = true;
            } else {
                $subscriptionsData = SubscriptionData::collection($body);
                // Paiements en attente de ses abonnements actifs, dans la même réponse
                $pendingPayments = PendingPaymentData::collection($body, $ciklikUuid);
            }
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

        // Comme au panier : une erreur de mise en forme (devise absente de la locale) ne bloque pas la page
        try {
            $presentedPendingPayments = $this->module->presentPendingPayments($pendingPayments);
        } catch (Throwable $e) {
            $presentedPendingPayments = [];
        }

        $this->context->smarty->assign([
            'subscriptions' => $subscriptionsData ?? [],
            'subscriptions_unavailable' => $subscriptionsUnavailable,
            'pending_payments' => $presentedPendingPayments,
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

    /**
     * Client de l'API des abonnements (remplacé dans les tests)
     *
     * @return Subscription
     */
    protected function subscriptionApi()
    {
        return new Subscription($this->context->link);
    }

    /**
     * Abonnements du client chez Ciklik (corps brut de la réponse). Liste
     * illisible (refus, réponse illisible, Ciklik injoignable : Guzzle 7 lève
     * ConnectException hors du client) : message traduit au lieu d'une page
     * d'erreur ou de « aucun abonnement », cause journalisée sans le texte de
     * l'API ni celui de l'exception.
     *
     * @param string $ciklikUuid
     *
     * @return array|null null si Ciklik n'a pas rendu la liste
     */
    protected function fetchSubscriptions($ciklikUuid)
    {
        $cause = null;

        try {
            $response = $this->subscriptionApi()->indexRaw(['query' => ['filter' => ['customer_id' => $ciklikUuid]]]);
        } catch (Throwable $e) {
            $response = null;
            $cause = 'exception ' . get_class($e);
        }

        if (is_array($response) && !empty($response['status']) && isset($response['body']) && is_array($response['body'])) {
            return $response['body'];
        }

        PrestaShopLogger::addLog(
            'Ciklik subscriptions list failed (customer account) - '
                . (null !== $cause ? $cause : CiklikApiResponseHandler::failureSummary($response)),
            2,
            null,
            'CiklikSubscription',
            null,
            true
        );
        $this->unavailableMessage = $this->module->l('Your subscriptions are temporarily unavailable. Please try again later.', 'account');
        $this->errors[] = $this->unavailableMessage;

        return null;
    }

    /**
     * {@inheritdoc}
     *
     * Ciklik arrêté : l'action qui renvoie ici a déjà annoncé son échec, la
     * liste illisible ne s'annonce pas en plus. Seule, elle garde son message.
     */
    protected function prepareNotifications()
    {
        $notifications = parent::prepareNotifications();

        if (null !== $this->unavailableMessage && count($notifications['error']) > 1) {
            $notifications['error'] = array_values(array_diff($notifications['error'], [$this->unavailableMessage]));
        }

        return $notifications;
    }
}
