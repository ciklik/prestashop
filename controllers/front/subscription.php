<?php

/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

use PrestaShop\Module\Ciklik\Api\Subscription;
use PrestaShop\Module\Ciklik\Data\CartFingerprintData;
use PrestaShop\Module\Ciklik\Data\SubscriptionData;
use PrestaShop\Module\Ciklik\Helpers\UuidHelper;
use PrestaShop\Module\Ciklik\Managers\CiklikCombination;
use PrestaShop\Module\Ciklik\Managers\CiklikFrequency;

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

    /**
     * {@inheritdoc}
     */
    public function postProcess()
    {
        $action = Tools::getValue('action');
        $isAjax = $action === 'addUpsell';

        // Vérification CSRF pour les requêtes POST (sauf AJAX qui gère différemment)
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isAjax && !$this->isTokenValid()) {
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
        }
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
            ['active' => false],
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
            ['active' => true],
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
            $date = Carbon\Carbon::parse($nextBilling);

            // Vérifier que la date est dans le futur
            if ($date->isPast()) {
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
            ['next_billing' => $date->toDateString()],
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

        $sub = SubscriptionData::create($sub['body']);
        $sub->external_fingerprint->id_address_delivery = (int) Tools::getValue('changeAddressForm');

        $result = (new Subscription($this->context->link))->update(
            $uuid,
            ['metadata' => ['prestashop_fingerprint' => $sub->external_fingerprint->encodeDatas()]],
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
        $subscriptionData = SubscriptionData::create($currentSubscription['body']);

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
                ],
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
                $updateData,
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
        $quantity = (int) Tools::getValue('quantity');

        if ($productId <= 0) {
            $this->ajaxRenderAndExit(json_encode([
                'success' => false,
                'message' => $this->module->l('Invalid product.', 'subscription'),
            ]));

            return;
        }

        // Quantité 0 = suppression de l'upsell, sinon minimum 1
        if ($quantity < 0) {
            $quantity = 1;
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
            ['upsells' => $upsell],
        );

        // Vérifie si l'appel API a réussi
        if (!isset($result['status']) || !$result['status']) {
            $errorMessage = $this->module->l('Error while adding the product to the subscription.', 'subscription');
            if (!empty($result['errors'])) {
                $firstError = is_array($result['errors'][0]) ? $result['errors'][0][0] : $result['errors'][0];
                $errorMessage = Tools::htmlentitiesUTF8($firstError);
            }
            $this->ajaxRenderAndExit(json_encode([
                'success' => false,
                'message' => $errorMessage,
            ]));

            return;
        }

        $this->ajaxRenderAndExit(json_encode([
            'success' => true,
            'message' => $this->module->l('The product has been successfully added to your subscription.', 'subscription'),
        ]));
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
        $this->ajaxRender($value, $controller, $method);
        exit;
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

            return (int) $fingerprint->id_customer === (int) $this->context->customer->id;
        } catch (Exception $e) {
            return false;
        }
    }
}
