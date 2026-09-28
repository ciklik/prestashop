<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

use PrestaShop\Module\Ciklik\Api\CiklikApiResponseHandler;
use PrestaShop\Module\Ciklik\Api\Order as CiklikOrderApi;
use PrestaShop\Module\Ciklik\Api\Subscription;
use PrestaShop\Module\Ciklik\Data\CartFingerprintData;
use PrestaShop\Module\Ciklik\Data\SubscriptionData;
use PrestaShop\Module\Ciklik\Helpers\IntervalHelper;
use PrestaShop\Module\Ciklik\Helpers\ProductIdentifier;
use PrestaShop\Module\Ciklik\Helpers\RelaySearchQuota;
use PrestaShop\Module\Ciklik\Helpers\RelaySelectionSigner;
use PrestaShop\Module\Ciklik\Helpers\ShopContextSwitch;
use PrestaShop\Module\Ciklik\Helpers\SkipCadenceResolver;
use PrestaShop\Module\Ciklik\Helpers\SubscriptionHelper;
use PrestaShop\Module\Ciklik\Helpers\SubscriptionRequestGuard;
use PrestaShop\Module\Ciklik\Helpers\UpsellEligibility;
use PrestaShop\Module\Ciklik\Helpers\UuidHelper;
use PrestaShop\Module\Ciklik\Managers\CiklikCombination;
use PrestaShop\Module\Ciklik\Managers\CiklikDeliveryOverride;
use PrestaShop\Module\Ciklik\Managers\CiklikFrequency;
use PrestaShop\Module\Ciklik\Managers\CiklikRelaySearch;
use PrestaShop\Module\Ciklik\Managers\CiklikSubscribable;
use PrestaShop\Module\Ciklik\Managers\CiklikSubscriptionRelay;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CiklikSubscriptionModuleFrontController extends ModuleFrontController
{
    /**
     * Authentification requise pour accéder aux actions sur les abonnements
     *
     * @var bool
     */
    public $auth = true;

    /**
     * Page de redirection si non authentifié
     *
     * @var string
     */
    public $authRedirection = 'my-account';

    /** Actions de la page relais dont les formulaires portent le jeton propre au module */
    const RELAY_ACTIONS = ['relay', 'saverelay'];

    /** @var array|null Corps de l'abonnement chargé par le contrôle de propriété, réutilisé par les actions relais */
    private $subscriptionBody;

    /** @var array|null Variables de la page de choix du relais, rendue par initContent() */
    private $relayPageVars;

    /** @var ShopContextSwitch Contexte boutique de l'abonnement, le temps des actions relais */
    private $shopContext;

    /**
     * Feuille de style de la page de choix du relais, seulement quand elle
     * se rend (les autres actions redirigent depuis postProcess()).
     */
    public function setMedia()
    {
        parent::setMedia();

        // setMedia() précède postProcess() dans FrontController::run() : on ne
        // peut pas s'appuyer sur relayPageVars ici, seule l'action de l'URL
        // dit si la page relais va se rendre.
        if ('relay' === Tools::getValue('action')) {
            $this->registerStylesheet(
                'ciklik-relay',
                'modules/' . $this->module->name . '/views/css/relay.css',
                ['media' => 'all', 'priority' => 200]
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function postProcess()
    {
        $action = Tools::getValue('action');
        $isAjax = SubscriptionRequestGuard::isAjaxAction($action);
        $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '';

        // Seul l'affichage de la page relais se sert en GET : toute autre
        // action modifie l'abonnement et n'est acceptée qu'en POST, un lien
        // ou une image piégés ne déclenchent plus rien. Puis jeton du client
        // exigé sur tout POST, actions AJAX comprises, que PS_TOKEN_ENABLE soit
        // activé ou non. Avant le contrôle de propriété : une requête forgée
        // ne coûte pas d'appel à l'API.
        if (!SubscriptionRequestGuard::isMethodAllowed($method, $action)
            || ('POST' === $method && !$this->isTokenValid())) {
            $this->refuseRequest(
                $isAjax,
                $this->module->l('Invalid security token. Please try again.', 'subscription')
            );

            return;
        }

        // Formulaires de la page relais : jeton propre au module en plus du
        // jeton du client, lié à la boutique de la requête.
        if ($_SERVER['REQUEST_METHOD'] === 'POST'
            && in_array($action, self::RELAY_ACTIONS, true)
            && !$this->isRelayFormTokenValid()) {
            $this->errors[] = $this->module->l('Invalid security token. Please try again.', 'subscription');
            $this->redirectWithNotifications($this->context->link->getModuleLink('ciklik', 'account'));

            return;
        }

        // Vérification de la propriété de l'abonnement
        $uuid = UuidHelper::getFromRequest('uuid');
        if ($uuid && !$this->validateSubscriptionOwnership($uuid)) {
            if ($isAjax) {
                $this->ajaxRenderAndExit(json_encode([
                    'success' => false,
                    'message' => $this->module->l('You do not have permission to access this subscription.', 'subscription'),
                ]));

                return;
            }
            $this->errors[] = $this->module->l('You do not have permission to access this subscription.', 'subscription');
            $this->redirectWithNotifications($this->context->link->getModuleLink('ciklik', 'account'));

            return;
        }

        switch ($action) {
            case 'stop':
                $this->stop();
                break;
            case 'newdate':
                $this->newdate();
                break;
            case 'skip':
                $this->skip();
                break;
            case 'updateaddress':
                $this->updateaddress();
                break;
            case 'resume':
                $this->resume();
                break;
            case 'contents':
                $this->updateContent();
                break;
            case 'addUpsell':
                $this->addUpsell();
                break;
            case 'updateProductQuantity':
                $this->updateProductQuantity();
                break;
            case 'removeProduct':
                $this->removeProduct();
                break;
            case 'addProduct':
                $this->addProduct();
                break;
            case 'relay':
            case 'saverelay':
                // Actions relais dans le contexte de la boutique de
                // l'abonnement ; celui de la requête est restauré avant le
                // rendu de la page (hooks, configuration du thème)
                $this->shopContext = new ShopContextSwitch();
                try {
                    if ('relay' === $action) {
                        $this->relay();
                    } else {
                        $this->saveRelay();
                    }
                } finally {
                    $this->shopContext->restore();
                }
                break;
        }
    }

    /**
     * {@inheritdoc}
     *
     * Seule la page de choix du point relais se rend ici ; toutes les autres
     * actions redirigent depuis postProcess(). Sans page à rendre (action
     * inconnue), retour à « Mes abonnements ».
     */
    public function initContent()
    {
        parent::initContent();

        if (null === $this->relayPageVars) {
            Tools::redirect($this->context->link->getModuleLink('ciklik', 'account'));

            return;
        }

        $this->context->smarty->assign($this->relayPageVars);
        $this->module->assignThemeVariables();
        $this->setTemplate('module:ciklik/views/templates/front/relay.tpl');
    }

    /**
     * {@inheritdoc}
     *
     * Jeton du client exigé quelle que soit la valeur de PS_TOKEN_ENABLE :
     * la version de FrontController rend toujours vrai quand la boutique l'a
     * désactivé (réglage par défaut), ce qui laissait toute action
     * d'abonnement ouverte à une requête forgée depuis un autre site.
     *
     * @return bool
     */
    public function isTokenValid()
    {
        return SubscriptionRequestGuard::isTokenValid(Tools::getToken(false), Tools::getValue('token'));
    }

    /**
     * Refus d'une requête avant toute action : réponse JSON pour les appels
     * AJAX, retour à Mes abonnements avec le message sinon.
     *
     * @param bool $isAjax
     * @param string $message
     */
    private function refuseRequest($isAjax, $message)
    {
        if ($isAjax) {
            $this->ajaxRenderAndExit(json_encode([
                'success' => false,
                'message' => $message,
            ]));

            return;
        }

        $this->errors[] = $message;
        $this->redirectWithNotifications($this->context->link->getModuleLink('ciklik', 'account'));
    }

    private function stop()
    {
        $uuid = UuidHelper::getFromRequest('uuid');
        if (null === $uuid) {
            $this->redirectWithNotifications($this->context->link->getModuleLink('ciklik', 'account'));

            return;
        }

        (new Subscription($this->context->link))->update(
            $uuid,
            ['active' => false]
        );

        $this->success[] = $this->module->l('Your subscription has been paused.', 'subscription');
        $this->redirectWithNotifications($this->context->link->getModuleLink('ciklik', 'account'));
    }

    private function resume()
    {
        $uuid = UuidHelper::getFromRequest('uuid');
        if (null === $uuid) {
            $this->redirectWithNotifications($this->context->link->getModuleLink('ciklik', 'account'));

            return;
        }

        (new Subscription($this->context->link))->update(
            $uuid,
            ['active' => true]
        );

        $this->success[] = $this->module->l('Your subscription has been resumed.', 'subscription');
        $this->redirectWithNotifications($this->context->link->getModuleLink('ciklik', 'account'));
    }

    private function newdate()
    {
        $nextBilling = Tools::getValue('next_billing');

        // Validation du format de date (YYYY-MM-DD)
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $nextBilling)) {
            $this->errors[] = $this->module->l('Invalid date format.', 'subscription');
            $this->redirectWithNotifications($this->context->link->getModuleLink('ciklik', 'account'));

            return;
        }

        try {
            $date = new DateTimeImmutable($nextBilling);

            // Vérifier que la date est dans le futur
            if ($date < new DateTimeImmutable()) {
                $this->errors[] = $this->module->l('The date must be in the future.', 'subscription');
                $this->redirectWithNotifications($this->context->link->getModuleLink('ciklik', 'account'));

                return;
            }
        } catch (Exception $e) {
            $this->errors[] = $this->module->l('Invalid date.', 'subscription');
            $this->redirectWithNotifications($this->context->link->getModuleLink('ciklik', 'account'));

            return;
        }

        $uuid = UuidHelper::getFromRequest('uuid');
        if (null === $uuid) {
            $this->errors[] = $this->module->l('Invalid subscription identifier.', 'subscription');
            $this->redirectWithNotifications($this->context->link->getModuleLink('ciklik', 'account'));

            return;
        }

        $result = (new Subscription($this->context->link))->update(
            $uuid,
            ['next_billing' => $date->format('Y-m-d')]
        );

        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $key => $error) {
                $errorMessage = is_array($error) ? $error[0] : $error;
                $this->errors[] = Tools::htmlentitiesUTF8($errorMessage);
            }
        } else {
            $this->success[] = $this->module->l('Your subscription renewal date has been updated.', 'subscription');
        }

        $this->redirectWithNotifications($this->context->link->getModuleLink('ciklik', 'account'));
    }

    /**
     * Reporte la prochaine livraison d'un cycle (« sauter une livraison »).
     *
     * Avance next_billing d'un intervalle sans résilier l'abonnement. La cadence est lue sur
     * le corps de l'abonnement (interval/interval_count au niveau racine), avec repli sur la
     * fréquence du fingerprint en mode fréquence.
     */
    private function skip()
    {
        $uuid = UuidHelper::getFromRequest('uuid');
        if (null === $uuid) {
            $this->errors[] = $this->module->l('Invalid subscription identifier.', 'subscription');
            $this->redirectWithNotifications($this->context->link->getModuleLink('ciklik', 'account'));

            return;
        }

        $subscriptionApi = new Subscription($this->context->link);
        $current = $subscriptionApi->getOne($uuid);

        try {
            $subscriptionData = SubscriptionData::create($current['body']);
        } catch (\InvalidArgumentException $e) {
            $this->errors[] = $this->module->l('Unable to read subscription data. Please try again later.', 'subscription');
            $this->redirectWithNotifications($this->context->link->getModuleLink('ciklik', 'account'));

            return;
        }

        $cadence = SkipCadenceResolver::resolve(
            $current['body'],
            $subscriptionData->external_fingerprint->frequency_id,
            $subscriptionData->contents
        );

        if (null === $cadence) {
            $this->errors[] = $this->module->l('Unable to determine the subscription frequency.', 'subscription');
            $this->redirectWithNotifications($this->context->link->getModuleLink('ciklik', 'account'));

            return;
        }

        try {
            $newDate = IntervalHelper::addIntervalToDate($subscriptionData->next_billing, $cadence['interval'], $cadence['interval_count']);
        } catch (\InvalidArgumentException $e) {
            $this->errors[] = $this->module->l('Unable to determine the subscription frequency.', 'subscription');
            $this->redirectWithNotifications($this->context->link->getModuleLink('ciklik', 'account'));

            return;
        }

        $result = $subscriptionApi->update($uuid, ['next_billing' => $newDate->format('Y-m-d')]);

        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $error) {
                $errorMessage = is_array($error) ? $error[0] : $error;
                $this->errors[] = Tools::htmlentitiesUTF8($errorMessage);
            }
        } else {
            $this->success[] = sprintf(
                $this->module->l('Your next delivery has been postponed to %s.', 'subscription'),
                $newDate->format('d/m/Y')
            );
        }

        $this->redirectWithNotifications($this->context->link->getModuleLink('ciklik', 'account'));
    }

    private function updateaddress()
    {
        $uuid = UuidHelper::getFromRequest('uuid');
        if (null === $uuid) {
            $this->errors[] = $this->module->l('Invalid subscription identifier.', 'subscription');
            $this->redirectWithNotifications($this->context->link->getModuleLink('ciklik', 'account'));

            return;
        }

        $address = new Address((int) Tools::getValue('changeAddressForm'));

        if ($this->context->customer->id !== (int) $address->id_customer) {
            throw new PrestaShop\Module\Ciklik\Exceptions\NotAllowedException();
        }

        $sub = (new Subscription($this->context->link))->getOne($uuid);

        try {
            $sub = SubscriptionData::create($sub['body']);
        } catch (\InvalidArgumentException $e) {
            $this->errors[] = $this->module->l('Unable to read subscription data. Please try again later.', 'subscription');
            $this->redirectWithNotifications($this->context->link->getModuleLink('ciklik', 'account'));

            return;
        }

        $sub->external_fingerprint->id_address_delivery = (int) Tools::getValue('changeAddressForm');

        $result = (new Subscription($this->context->link))->update(
            $uuid,
            ['metadata' => ['prestashop_fingerprint' => $sub->external_fingerprint->encodeDatas()]]
        );

        if (count($result['errors'])) {
            foreach ($result['errors'] as $key => $error) {
                $this->errors[] = Tools::htmlentitiesUTF8($error[0]);
            }
        } else {
            $this->success[] = $this->module->l('Your new address has been saved.', 'subscription');
        }

        $this->redirectWithNotifications($this->context->link->getModuleLink('ciklik', 'account'));
    }

    /**
     * Met à jour le contenu d'un abonnement avec une nouvelle combinaison de produit
     *
     * Cette méthode permet de changer la fréquence d'un abonnement en mettant à jour
     * la combinaison de produit associée. Elle vérifie que la nouvelle combinaison est valide
     * et compatible avec l'abonnement existant avant d'effectuer la mise à jour via l'API.
     *
     * Le processus :
     * 1. Récupère l'UUID de l'abonnement et l'ID de la nouvelle combinaison depuis le formulaire
     * 2. Charge les données de l'abonnement actuel via l'API
     * 3. Vérifie que la nouvelle combinaison est valide
     * 4. Met à jour le contenu en conservant les quantités mais en changeant les fréquences
     * 5. Envoie la mise à jour à l'API
     *
     * @return void
     */
    private function updateContent()
    {
        // Récupérer et valider l'UUID de l'abonnement
        $subscriptionUuid = UuidHelper::getFromRequest('uuid');
        if (null === $subscriptionUuid) {
            $this->errors[] = $this->module->l('Invalid subscription identifier.', 'subscription');
            $this->redirectWithNotifications($this->context->link->getModuleLink('ciklik', 'account'));

            return;
        }

        $useFrequencyMode = Tools::getValue('use_frequency_mode');

        // Récupérer l'abonnement actuel
        $subscriptionApi = new Subscription($this->context->link);
        $currentSubscription = $subscriptionApi->getOne($subscriptionUuid);

        try {
            $subscriptionData = SubscriptionData::create($currentSubscription['body']);
        } catch (\InvalidArgumentException $e) {
            $this->errors[] = $this->module->l('Unable to read subscription data. Please try again later.', 'subscription');
            $this->redirectWithNotifications($this->context->link->getModuleLink('ciklik', 'account'));

            return;
        }

        if ($useFrequencyMode === '1') {
            $frequencyId = (int) Tools::getValue('product_combination');
            $frequency = CiklikFrequency::getFrequencyById($frequencyId);
            $subscriptionData->external_fingerprint->frequency_id = $frequencyId;

            $result = (new Subscription($this->context->link))
            ->update(
                $subscriptionUuid,
                [
                    'metadata' => ['prestashop_fingerprint' => $subscriptionData->external_fingerprint->encodeDatas()],
                    'interval' => $frequency['interval'],
                    'interval_count' => (int) $frequency['interval_count'],
                ]
            );

            if (count($result['errors'])) {
                foreach ($result['errors'] as $key => $error) {
                    $this->errors[] = Tools::htmlentitiesUTF8($error[0]);
                }
            } else {
                $this->success[] = $this->module->l('Your new frequency has been saved.', 'subscription');
            }

            $this->redirectWithNotifications($this->context->link->getModuleLink('ciklik', 'account'));
        } else {
            $newCombinationId = (int) Tools::getValue('product_combination');
            // Récupérer les informations de la nouvelle combinaison
            $newCombination = CiklikCombination::getCombinationDetails($newCombinationId);
            if (!$newCombination) {
                $this->errors[] = $this->module->l('Invalid combination.', 'subscription');
                $this->redirectWithNotifications($this->context->link->getModuleLink('ciklik', 'account'));

                return;
            }

            // Mettre à jour le contenu de l'abonnement
            $updatedContents = [];

            foreach ($subscriptionData->contents as $content) {
                $matchingCombination = CiklikCombination::getMatchingCombinations($newCombination, $content['external_id']);
                if ($matchingCombination) {
                    $updatedContents[] = [
                        'external_id' => $matchingCombination['id_product_attribute'],
                        'quantity' => $content['quantity'],
                        'interval' => $matchingCombination['interval'],
                        'interval_count' => $matchingCombination['interval_count'],
                    ];
                }
            }

            // Préparer les données pour la mise à jour de l'API
            $updateData = [
                'content' => $updatedContents,
            ];

            // Envoyer la mise à jour à l'API
            $result = $subscriptionApi->update(
                $subscriptionUuid,
                $updateData
            );

            if (isset($result['errors']) && count($result['errors'])) {
                foreach ($result['errors'] as $error) {
                    $this->errors[] = Tools::htmlentitiesUTF8($error[0]);
                }
            } else {
                $this->success[] = $this->module->l('Your subscription content has been updated successfully.', 'subscription');
            }

            $this->redirectWithNotifications($this->context->link->getModuleLink('ciklik', 'account'));
        }
    }

    /**
     * Ajoute un produit à un abonnement existant
     *
     * Cette fonction permet d'ajouter un produit à un abonnement existant
     * en utilisant l'UUID de l'abonnement et les informations du produit.
     *
     * @return void
     */
    private function addUpsell()
    {
        $uuid = UuidHelper::getFromRequest('uuid');
        if (null === $uuid) {
            $this->ajaxRenderAndExit(json_encode([
                'success' => false,
                'message' => $this->module->l('Invalid subscription identifier.', 'subscription'),
            ]));

            return;
        }

        $productId = (int) Tools::getValue('id_product');
        $productAttributeId = (int) Tools::getValue('id_product_attribute');
        $rawQuantity = Tools::getValue('quantity');

        // Quantité 0 = retrait de l'upsell, sinon ajout, bornée comme l'API.
        // Une valeur illisible est refusée : lue comme 0, elle retirerait l'upsell.
        if (!SubscriptionRequestGuard::isValidUpsellQuantity($rawQuantity)) {
            $this->ajaxRenderAndExit(json_encode([
                'success' => false,
                'message' => $this->module->l('Invalid quantity.', 'subscription'),
            ]));

            return;
        }

        $quantity = (int) $rawQuantity;

        if ($productId <= 0 || $productAttributeId < 0) {
            $this->ajaxRenderAndExit(json_encode([
                'success' => false,
                'message' => $this->module->l('Invalid product.', 'subscription'),
            ]));

            return;
        }

        // Ajout : produit existant et déclinaison qui lui appartient, comme
        // addProduct, puis même règle que l'affichage du bouton « Ajouter à
        // l'abonnement » de la fiche produit (UpsellEligibility). Le retrait
        // reste possible pour un produit supprimé ou devenu indisponible, et
        // quand l'upsell est désactivé : Mes abonnements le propose toujours.
        if ($quantity > 0) {
            $productError = null;
            $product = $this->loadSubscriptionProduct($productId, $productAttributeId, $productError);
            if (null === $product) {
                $this->ajaxRenderAndExit(json_encode([
                    'success' => false,
                    'message' => $productError,
                ]));

                return;
            }

            if (null !== UpsellEligibility::refusal($product, (int) $this->context->customer->id)) {
                $this->ajaxRenderAndExit(json_encode([
                    'success' => false,
                    'message' => $this->module->l('This product cannot be added to your subscription.', 'subscription'),
                ]));

                return;
            }
        }

        $subscriptionApi = new Subscription($this->context->link);

        $upsell = [
            [
                'product_id' => $productId,
                'product_attribute_id' => $productAttributeId,
                'quantity' => $quantity,
            ],
        ];

        $result = $subscriptionApi->update(
            $uuid,
            ['upsells' => $upsell]
        );

        // Refus de l'API : même lecture que les autres actions produits (une
        // validation rangée par champ faisait lire $result['errors'][0] absent)
        if (!isset($result['status']) || !$result['status']) {
            $this->ajaxRenderAndExit(json_encode([
                'success' => false,
                'message' => $this->apiRefusalMessage(
                    $result,
                    $this->module->l('Error while adding the product to the subscription.', 'subscription')
                ),
            ]));

            return;
        }

        $this->ajaxRenderAndExit(json_encode([
            'success' => true,
            'message' => $this->module->l('The product has been successfully added to your subscription.', 'subscription'),
        ]));
    }

    /**
     * Message d'un refus de l'API pour le client : refus connus du champ
     * « product » traduits par le module ; texte de l'API, rédigé en
     * français, pour un client francophone seulement ; message générique
     * traduit ($fallback) sinon.
     *
     * @param array $result Réponse de l'API
     * @param string $fallback Message générique du module
     *
     * @return string
     */
    private function apiRefusalMessage($result, $fallback)
    {
        return CiklikApiResponseHandler::customerErrorMessage(
            $result,
            $fallback,
            [
                'not_attached' => $this->module->l('This product is not part of your subscription.', 'subscription'),
                'last_product' => $this->module->l('The last product of a subscription cannot be removed.', 'subscription'),
                'unknown_product' => $this->module->l('This product cannot be added to your subscription.', 'subscription'),
                'other_tenant' => $this->module->l('This product is not available for subscription.', 'subscription'),
            ],
            $this->context->language && 'fr' === strtolower((string) $this->context->language->iso_code)
        );
    }

    /**
     * La modification vise-t-elle une ligne personnalisée ? Lu sur le corps
     * de l'abonnement chargé par le contrôle de propriété, sans nouvel appel.
     *
     * @param string $externalId
     *
     * @return bool
     */
    private function targetsCustomizedLine($externalId)
    {
        $contents = is_array($this->subscriptionBody) && isset($this->subscriptionBody['content'])
            ? $this->subscriptionBody['content']
            : [];

        return SubscriptionData::targetsCustomizedLine($contents, (string) $externalId);
    }

    /**
     * Vérification commune du produit ajouté à un abonnement, par addProduct
     * et addUpsell : identifiants positifs, produit existant, déclinaison
     * appartenant au produit (sans quoi le panier de renouvellement échouerait
     * sur une déclinaison introuvable).
     *
     * @param int $productId
     * @param int $productAttributeId
     * @param string|null $error Message à renvoyer au client en cas de refus
     *
     * @return Product|null Produit chargé, null si refusé
     */
    private function loadSubscriptionProduct($productId, $productAttributeId, &$error)
    {
        $error = null;

        if ($productId <= 0 || $productAttributeId < 0) {
            $error = $this->module->l('Invalid product.', 'subscription');

            return null;
        }

        $product = new Product($productId, false, $this->context->language->id);
        if (!Validate::isLoadedObject($product)) {
            $error = $this->module->l('Product not found.', 'subscription');

            return null;
        }

        if ($productAttributeId > 0) {
            $combination = new Combination($productAttributeId);
            if (!Validate::isLoadedObject($combination) || (int) $combination->id_product !== (int) $productId) {
                $error = $this->module->l('Invalid product.', 'subscription');

                return null;
            }
        }

        return $product;
    }

    protected function ajaxRenderAndExit($value = null, $responseCode = null, $controller = null, $method = null)
    {
        $this->renderAndExit($value, $controller, $method);
    }

    protected function ajaxFailAndDie($msg = null, $statusCode = 500)
    {
        header("X-PHP-Response-Code: $statusCode", true, $statusCode);
        $json = ['error' => true, 'message' => $msg];

        $this->ajaxRenderAndExit(json_encode($json));
    }

    protected function renderAndExit($value = null, $controller = null, $method = null)
    {
        // Réponse JSON déclarée comme telle et jamais réinterprétée par le
        // navigateur : ajaxRender() ne pose aucun Content-Type, la réponse
        // partait en text/html, avec des messages repris de l'API
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        // Controller::ajaxRender existe à partir de PS 1.7.5.0 ; repli manuel en deçà.
        if (method_exists($this, 'ajaxRender')) {
            $this->ajaxRender($value, $controller, $method);
        } else {
            echo $value;
        }
        exit;
    }

    /**
     * Met à jour la quantité d'un produit dans un abonnement
     */
    private function updateProductQuantity()
    {
        $uuid = UuidHelper::getFromRequest('uuid');
        if (null === $uuid) {
            $this->ajaxRenderAndExit(json_encode([
                'success' => false,
                'message' => $this->module->l('Invalid subscription identifier.', 'subscription'),
            ]));

            return;
        }

        $externalId = Tools::getValue('external_id');
        $quantity = (int) Tools::getValue('quantity');

        if (!ProductIdentifier::isValidExternalId($externalId)) {
            $this->ajaxRenderAndExit(json_encode([
                'success' => false,
                'message' => $this->module->l('Invalid product.', 'subscription'),
            ]));

            return;
        }

        if ($this->targetsCustomizedLine($externalId)) {
            $this->ajaxRenderAndExit(json_encode([
                'success' => false,
                'message' => $this->module->l('A customized product cannot be changed from your account.', 'subscription'),
            ]));

            return;
        }

        if ($quantity < 1 || $quantity > 9999) {
            $this->ajaxRenderAndExit(json_encode([
                'success' => false,
                'message' => $this->module->l('Quantity must be at least 1.', 'subscription'),
            ]));

            return;
        }

        $result = (new Subscription($this->context->link))->updateProductQuantity($uuid, $externalId, $quantity);

        if (!isset($result['status']) || !$result['status']) {
            // Refus de l'API : refus connu traduit, sinon son premier message
            // (validation rangée par champ comprise) pour un client francophone
            $this->ajaxRenderAndExit(json_encode([
                'success' => false,
                'message' => $this->apiRefusalMessage(
                    $result,
                    $this->module->l('Error while updating the product quantity.', 'subscription')
                ),
            ]));

            return;
        }

        $this->ajaxRenderAndExit(json_encode([
            'success' => true,
            'message' => $this->module->l('Product quantity updated.', 'subscription'),
        ]));
    }

    /**
     * Supprime un produit d'un abonnement
     */
    private function removeProduct()
    {
        $uuid = UuidHelper::getFromRequest('uuid');
        if (null === $uuid) {
            $this->ajaxRenderAndExit(json_encode([
                'success' => false,
                'message' => $this->module->l('Invalid subscription identifier.', 'subscription'),
            ]));

            return;
        }

        $externalId = Tools::getValue('external_id');

        if (!ProductIdentifier::isValidExternalId($externalId)) {
            $this->ajaxRenderAndExit(json_encode([
                'success' => false,
                'message' => $this->module->l('Invalid product.', 'subscription'),
            ]));

            return;
        }

        if ($this->targetsCustomizedLine($externalId)) {
            $this->ajaxRenderAndExit(json_encode([
                'success' => false,
                'message' => $this->module->l('A customized product cannot be changed from your account.', 'subscription'),
            ]));

            return;
        }

        $result = (new Subscription($this->context->link))->removeProduct($uuid, $externalId);

        if (!isset($result['status']) || !$result['status']) {
            // Refus de l'API : refus connu traduit, sinon son premier message
            // (validation rangée par champ comprise) pour un client francophone
            $this->ajaxRenderAndExit(json_encode([
                'success' => false,
                'message' => $this->apiRefusalMessage(
                    $result,
                    $this->module->l('Error while removing the product from the subscription.', 'subscription')
                ),
            ]));

            return;
        }

        $this->ajaxRenderAndExit(json_encode([
            'success' => true,
            'message' => $this->module->l('The product has been removed from your subscription.', 'subscription'),
        ]));
    }

    /**
     * Ajoute un produit à un abonnement existant via l'API products
     */
    private function addProduct()
    {
        $uuid = UuidHelper::getFromRequest('uuid');
        if (null === $uuid) {
            $this->ajaxRenderAndExit(json_encode([
                'success' => false,
                'message' => $this->module->l('Invalid subscription identifier.', 'subscription'),
            ]));

            return;
        }

        $productId = (int) Tools::getValue('id_product');
        $productAttributeId = (int) Tools::getValue('id_product_attribute');
        $quantity = max(1, (int) Tools::getValue('quantity'));

        $productError = null;
        $product = $this->loadSubscriptionProduct($productId, $productAttributeId, $productError);
        if (null === $product) {
            $this->ajaxRenderAndExit(json_encode([
                'success' => false,
                'message' => $productError,
            ]));

            return;
        }

        // Vérification que le produit est configuré comme subscribable.
        // L'API Ciklik ne fait PAS ce check (elle crée le produit automatiquement
        // s'il n'existe pas), il doit donc être fait côté module dans les 2 modes.
        $useFrequencyMode = (bool) Configuration::get(Ciklik::CONFIG_USE_FREQUENCY_MODE);
        $isSubscribable = $useFrequencyMode
            ? SubscriptionHelper::isSubscriptionEnabled($productId)
            : CiklikSubscribable::isSubscribable($productId);

        if (!$isSubscribable) {
            $this->ajaxRenderAndExit(json_encode([
                'success' => false,
                'message' => $this->module->l('This product is not available for subscription.', 'subscription'),
            ]));

            return;
        }

        $externalId = $productId . ':' . $productAttributeId;
        $productName = $productAttributeId > 0
            ? (Product::getProductName($productId, $productAttributeId) ?: $product->name)
            : $product->name;

        $data = [
            'external_id' => $externalId,
            'name' => $productName,
            'quantity' => $quantity,
            'tax' => (float) $product->getTaxesRate() / 100,
        ];

        $result = (new Subscription($this->context->link))->addProduct($uuid, $data);

        if (!isset($result['status']) || !$result['status']) {
            // Refus de l'API : refus connu traduit, sinon son premier message
            // (validation rangée par champ comprise) pour un client francophone
            $this->ajaxRenderAndExit(json_encode([
                'success' => false,
                'message' => $this->apiRefusalMessage(
                    $result,
                    $this->module->l('Error while adding the product to the subscription.', 'subscription')
                ),
            ]));

            return;
        }

        $this->ajaxRenderAndExit(json_encode([
            'success' => true,
            'message' => $this->module->l('The product has been added to your subscription.', 'subscription'),
        ]));
    }

    /**
     * Page de choix du point relais des prochaines livraisons (GET), avec
     * recherche par adresse (POST relay_search=1 sur la même page).
     *
     * Chaque relais proposé (résultat de recherche ou relais connu) est signé
     * côté serveur : la confirmation ne fait confiance qu'à ces jetons, jamais
     * à un payload libre ni à un numéro saisi. Sans JavaScript, surchargeable
     * par le thème.
     */
    private function relay()
    {
        $relayContext = $this->loadRelayContext();
        if (null === $relayContext) {
            return;
        }

        $subscription = $relayContext['subscription'];
        $module = $relayContext['module'];
        $idCustomer = $relayContext['id_customer'];
        $countryIso = $relayContext['country_iso'];
        $signingContext = $this->relaySigningContext($idCustomer, $module, $relayContext['id_shop']);
        $searchSupported = $relayContext['search_supported'];

        // Le pays n'est pas saisi : c'est celui de l'adresse de livraison des
        // rebills, un relais d'un autre pays ne serait jamais livré
        $prefill = [
            'zipcode' => $subscription->address ? (string) $subscription->address->postcode : '',
            'city' => $subscription->address ? (string) $subscription->address->city : '',
            'country_iso' => $countryIso,
        ];

        $results = [];
        $searchError = null;

        if ($searchSupported && Tools::isSubmit('relay_search') && 'POST' === $_SERVER['REQUEST_METHOD']) {
            $prefill['zipcode'] = trim((string) Tools::getValue('zipcode'));
            $prefill['city'] = trim((string) Tools::getValue('city'));

            if ('' === $prefill['zipcode']
                || Tools::strlen($prefill['zipcode']) > 12
                || !Validate::isPostCode($prefill['zipcode'])
                || Tools::strlen($prefill['city']) > 64
                || ('' !== $prefill['city'] && !Validate::isCityName($prefill['city']))) {
                $searchError = $this->module->l('Please enter a valid zip code and city.', 'subscription');
            } elseif (RelaySearchQuota::GRANTED !== ($quota = RelaySearchQuota::consume($idCustomer, $relayContext['id_shop']))) {
                // Plafond atteint : le dire ; compteur inaccessible (journalisé
                // par le quota) : service indisponible, pas « trop de recherches »
                $searchError = RelaySearchQuota::LIMITED === $quota
                    ? $this->module->l('Too many searches for now. Please try again later or pick one of your previous pickup points.', 'subscription')
                    : $this->module->l('Pickup point search is temporarily unavailable. Please try again later.', 'subscription');
            } else {
                try {
                    $results = CiklikRelaySearch::searchRelays($module, $prefill);
                } catch (Throwable $e) {
                    $searchError = $this->module->l('Pickup point search is temporarily unavailable. Please try again later.', 'subscription');
                }

                // Seuls les relais que la confirmation acceptera sont proposés :
                // identifiant valide, même pays que l'adresse, payload conforme
                $results = CiklikSubscriptionRelay::filterSelectable($results, $module, $countryIso);

                if (null === $searchError && empty($results)) {
                    $searchError = $this->module->l('No pickup point found around this address.', 'subscription');
                }
            }
        }

        $known = $relayContext['known'];

        $linkParams = ['uuid' => $relayContext['uuid']];

        $this->relayPageVars = [
            'relay_subscription' => $subscription,
            'relay_carrier_name' => (string) $relayContext['carrier']->name,
            'relay_module' => $module,
            'relay_current' => CiklikSubscriptionRelay::getCurrent(
                $idCustomer,
                $module,
                (int) $subscription->external_fingerprint->id_address_delivery
            ),
            'relay_search_supported' => $searchSupported,
            'relay_prefill' => $prefill,
            'relay_results' => $this->signRelays($results, $signingContext),
            'relay_known' => $this->signRelays($known, $signingContext),
            'relay_search_error' => $searchError,
            'relay_search_url' => $this->context->link->getModuleLink('ciklik', 'subscription', $linkParams + ['action' => 'relay']),
            'relay_save_url' => $this->context->link->getModuleLink('ciklik', 'subscription', $linkParams + ['action' => 'saverelay']),
            'relay_account_url' => $this->context->link->getModuleLink('ciklik', 'account'),
            'relay_form_token' => $this->relayFormToken(),
            'token' => Tools::getToken(false),
        ];
    }

    /**
     * Enregistre le point relais choisi par le client (POST).
     *
     * Le payload stocké vient uniquement d'un jeton signé par le serveur au
     * rendu de la page (résultat de recherche ou relais connu) : aucun numéro
     * saisi librement n'est accepté, personne ne connaît son numéro de relais.
     */
    private function saveRelay()
    {
        $accountUrl = $this->context->link->getModuleLink('ciklik', 'account');

        if ('POST' !== $_SERVER['REQUEST_METHOD']) {
            $this->redirectWithNotifications($accountUrl);

            return;
        }

        $relayContext = $this->loadRelayContext();
        if (null === $relayContext) {
            return;
        }

        $module = $relayContext['module'];
        $idCustomer = $relayContext['id_customer'];

        // Décision pure (CiklikSubscriptionRelay::decideSelection), testée
        // hors PrestaShop : jeton, choix, pays et payload
        $decision = CiklikSubscriptionRelay::decideSelection(
            Tools::getValue('relay_choice'),
            Tools::getValue('relay_data'),
            $module,
            $relayContext['country_iso'],
            _COOKIE_KEY_,
            $this->relaySigningContext($idCustomer, $module, $relayContext['id_shop'])
        );

        // Page restée ouverte plus d'une heure : retour à la page relais,
        // relais proposés de nouveau avec des jetons neufs
        if (CiklikSubscriptionRelay::SELECTION_EXPIRED === $decision['status']) {
            $this->errors[] = $this->module->l('The list of pickup points has expired. Please choose your pickup point again.', 'subscription');
            $this->redirectWithNotifications($this->context->link->getModuleLink(
                'ciklik',
                'subscription',
                ['uuid' => $relayContext['uuid'], 'action' => 'relay']
            ));

            return;
        }

        if (CiklikSubscriptionRelay::SELECTION_OK !== $decision['status']) {
            switch ($decision['status']) {
                case CiklikSubscriptionRelay::SELECTION_MISSING:
                    $this->errors[] = $this->module->l('Please select a pickup point.', 'subscription');
                    break;
                case CiklikSubscriptionRelay::SELECTION_INVALID_ID:
                    $this->errors[] = $this->module->l('Invalid pickup point number.', 'subscription');
                    break;
                case CiklikSubscriptionRelay::SELECTION_INVALID_TOKEN:
                    $this->errors[] = $this->module->l('Please select a pickup point from the list.', 'subscription');
                    break;
                default:
                    $this->errors[] = $this->module->l('This pickup point cannot be used. Please choose another one.', 'subscription');
            }
            $this->redirectWithNotifications($accountUrl);

            return;
        }

        $relayId = $decision['relay_id'];
        $payload = $decision['payload'];

        if (!CiklikDeliveryOverride::save($idCustomer, $module, $relayId, $payload)) {
            $this->errors[] = $this->module->l('Unable to save the pickup point. Please try again later.', 'subscription');
            $this->redirectWithNotifications($accountUrl);

            return;
        }

        // Journal d'audit, même objet que le back-office : qui (client), quel
        // transporteur, quel relais, pour quel abonnement
        PrestaShopLogger::addLog(
            'Ciklik relay override save (customer account) - customer ' . (int) $idCustomer
                . ' - carrier ' . $module
                . ' - relay ' . $relayId
                . ' - subscription ' . $relayContext['uuid'],
            1,
            null,
            'CiklikDeliveryOverride',
            (int) $idCustomer,
            true
        );

        $this->success[] = $this->module->l('Your new pickup point has been saved. It will be used for your next deliveries.', 'subscription');
        $this->redirectWithNotifications($accountUrl);
    }

    /**
     * Contexte commun aux actions relais : abonnement lisible, transporteur
     * des rebills en point relais, au moins un relais à proposer (recherche
     * par adresse ou relais déjà utilisés). Redirige avec message et retourne
     * null sinon.
     *
     * @return array|null uuid, subscription, module, carrier, id_customer,
     *                    id_shop, country_iso, search_supported, known
     */
    private function loadRelayContext()
    {
        $accountUrl = $this->context->link->getModuleLink('ciklik', 'account');

        $uuid = UuidHelper::getFromRequest('uuid');
        if (null === $uuid) {
            $this->errors[] = $this->module->l('Invalid subscription identifier.', 'subscription');
            $this->redirectWithNotifications($accountUrl);

            return null;
        }

        $idCustomer = (int) $this->context->customer->id;

        // Corps chargé par le contrôle de propriété de postProcess(), jamais
        // rechargé ici : sans ce corps, l'abonnement n'a pas été vérifié comme
        // appartenant au client connecté, et aucun appel ne doit en découler
        $body = $this->subscriptionBody;
        if (!is_array($body) || $idCustomer <= 0) {
            $this->errors[] = $this->module->l('You do not have permission to access this subscription.', 'subscription');
            $this->redirectWithNotifications($accountUrl);

            return null;
        }

        try {
            $subscription = SubscriptionData::create($body);
        } catch (\InvalidArgumentException $e) {
            $subscription = null;
        }

        if (null === $subscription) {
            $this->errors[] = $this->module->l('Unable to read subscription data. Please try again later.', 'subscription');
            $this->redirectWithNotifications($accountUrl);

            return null;
        }

        // Propriété revérifiée sur le corps réellement utilisé
        if ((int) $subscription->external_fingerprint->id_customer !== $idCustomer) {
            $this->errors[] = $this->module->l('You do not have permission to access this subscription.', 'subscription');
            $this->redirectWithNotifications($accountUrl);

            return null;
        }

        $resolved = CiklikSubscriptionRelay::resolveCarrier($subscription);
        if (null === $resolved) {
            $this->errors[] = $this->module->l('This subscription is not shipped to a pickup point.', 'subscription');
            $this->redirectWithNotifications($accountUrl);

            return null;
        }

        // Boutique de la commande d'origine de l'abonnement : les credentials
        // transporteur (Configuration::get) et le quota se résolvent sur elle,
        // pas sur la boutique de la requête
        $idShop = $this->resolveSubscriptionShop($uuid, $idCustomer);
        if ($this->shopContext) {
            $this->shopContext->switchTo($idShop);
        }

        // Pays de l'adresse de livraison des rebills : impose le pays de la
        // recherche et des relais proposés
        $countryIso = CiklikSubscriptionRelay::rebillCountryIso($subscription, $idCustomer);

        $searchSupported = CiklikRelaySearch::supportsSearch($resolved['module'], $countryIso);
        // Relais déjà utilisés que la page pourra proposer (identifiant, nom,
        // pays, payload) : le test ci-dessous porte sur eux, pas sur la liste brute
        $known = CiklikSubscriptionRelay::selectableKnownRelays($idCustomer, $resolved['module'], $countryIso);

        // Sans recherche ni relais proposable, rien à proposer : le lien
        // n'est pas affiché dans Mes abonnements, l'URL directe est refusée
        if (!$searchSupported && [] === $known) {
            $this->errors[] = $this->module->l('No pickup point can be offered for this subscription yet.', 'subscription');
            $this->redirectWithNotifications($accountUrl);

            return null;
        }

        return [
            'uuid' => $uuid,
            'subscription' => $subscription,
            'module' => $resolved['module'],
            'carrier' => $resolved['carrier'],
            'id_customer' => $idCustomer,
            'id_shop' => $idShop,
            'country_iso' => $countryIso,
            'search_supported' => $searchSupported,
            'known' => $known,
        ];
    }

    /**
     * Boutique de l'abonnement : celle de sa commande d'origine (première
     * commande PrestaShop liée à l'abonnement chez Ciklik), comme le fait le
     * back-office depuis la commande consultée. À défaut, boutique
     * d'inscription du client, puis boutique de la requête.
     *
     * @param string $uuid
     * @param int $idCustomer
     *
     * @return int
     */
    private function resolveSubscriptionShop($uuid, $idCustomer)
    {
        // Boutique unique : c'est celle de la requête, sans appel à l'API
        if (!Shop::isFeatureActive()) {
            return $this->context->shop ? (int) $this->context->shop->id : 0;
        }

        $idOrder = 0;

        try {
            $response = (new CiklikOrderApi($this->context->link))->index([
                'query' => ['filter' => ['subscription_uuid' => $uuid]],
            ]);
            $orders = isset($response['status'], $response['body']) && $response['status'] && is_array($response['body'])
                ? $response['body']
                : [];
            foreach ($orders as $orderData) {
                $candidate = isset($orderData->prestashop_order_id) ? (int) $orderData->prestashop_order_id : 0;
                if ($candidate > 0 && (0 === $idOrder || $candidate < $idOrder)) {
                    $idOrder = $candidate;
                }
            }
        } catch (Throwable $e) {
            $idOrder = 0;
        }

        if ($idOrder > 0) {
            $order = new Order($idOrder);
            if (Validate::isLoadedObject($order)
                && (int) $order->id_customer === (int) $idCustomer
                && (int) $order->id_shop > 0) {
                return (int) $order->id_shop;
            }
        }

        if ((int) $this->context->customer->id_shop > 0) {
            return (int) $this->context->customer->id_shop;
        }

        return $this->context->shop ? (int) $this->context->shop->id : 0;
    }

    /**
     * Liaison des jetons de sélection : un jeton ne vaut que pour ce client,
     * ce transporteur et cette boutique.
     *
     * @param int $idCustomer
     * @param string $module
     * @param int $idShop
     *
     * @return string
     */
    private function relaySigningContext($idCustomer, $module, $idShop)
    {
        return (int) $idCustomer . '|' . $module . '|' . (int) $idShop;
    }

    /**
     * Jeton propre au module des formulaires de la page relais, lié au client
     * connecté, à la boutique de la requête et au hash de son mot de passe.
     *
     * @return string
     */
    private function relayFormToken()
    {
        $idShop = $this->context->shop ? (int) $this->context->shop->id : 0;

        return RelaySelectionSigner::formToken(
            _COOKIE_KEY_,
            RelaySelectionSigner::formContext(
                (int) $this->context->customer->id,
                $idShop,
                $this->context->customer->passwd
            )
        );
    }

    /**
     * @return bool
     */
    private function isRelayFormTokenValid()
    {
        $received = Tools::getValue('relay_token');

        return is_string($received) && hash_equals($this->relayFormToken(), $received);
    }

    /**
     * Ajoute à chaque relais (déjà filtré par filterSelectable) son jeton
     * signé, pour le formulaire de confirmation, et le libellé de distance
     * affiché (hors jeton : affichage seulement). Le jeton ne porte que
     * l'identifiant et le payload enregistrable du relais.
     *
     * @param array $relays
     * @param string $signingContext
     *
     * @return array
     */
    private function signRelays(array $relays, $signingContext)
    {
        $signed = [];
        $formatNumber = $this->localeNumberFormatter();

        foreach ($relays as $relay) {
            $data = CiklikSubscriptionRelay::selectionData($relay);
            if (null === $data) {
                continue;
            }

            $relay['token'] = RelaySelectionSigner::sign($data, _COOKIE_KEY_, $signingContext);
            $relay['distance_label'] = isset($relay['distance'])
                ? CiklikSubscriptionRelay::formatDistance($relay['distance'], $formatNumber)
                : '';
            $signed[] = $relay;
        }

        return $signed;
    }

    /**
     * Mise en forme des nombres selon la locale du client (virgule décimale
     * en français), par la Locale de PrestaShop disponible à partir de
     * 1.7.6 ; null avant, la distance gardant alors le point décimal.
     *
     * @return callable|null
     */
    private function localeNumberFormatter()
    {
        if (!method_exists($this->context, 'getCurrentLocale')) {
            return null;
        }

        try {
            $locale = $this->context->getCurrentLocale();
        } catch (Exception $e) {
            return null;
        }

        if (!is_object($locale) || !method_exists($locale, 'formatNumber')) {
            return null;
        }

        return function ($number) use ($locale) {
            try {
                return (string) $locale->formatNumber($number);
            } catch (Exception $e) {
                return '';
            }
        };
    }

    /**
     * Vérifie que l'abonnement appartient bien au client connecté
     *
     * @param string $uuid UUID de l'abonnement
     *
     * @return bool
     */
    private function validateSubscriptionOwnership($uuid)
    {
        if (!$this->context->customer || !$this->context->customer->id) {
            return false;
        }

        try {
            $subscriptionApi = new Subscription($this->context->link);
            $response = $subscriptionApi->getOne($uuid);

            if (!isset($response['body']) || !isset($response['body']['external_fingerprint'])) {
                return false;
            }

            $fingerprint = CartFingerprintData::extractDatas($response['body']['external_fingerprint']);

            if ((int) $fingerprint->id_customer !== (int) $this->context->customer->id) {
                return false;
            }

            // Conservé pour les actions qui relisent l'abonnement (page relais)
            $this->subscriptionBody = $response['body'];

            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}
