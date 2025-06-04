<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

use PrestaShop\Module\Ciklik\Data\SubscriptionData;
use PrestaShop\Module\Ciklik\Managers\CiklikCombination;
use PrestaShop\Module\Ciklik\Managers\CiklikFrequency;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CiklikSubscriptionModuleFrontController extends ModuleFrontController
{
    /**
     * {@inheritdoc}
     */
    public function postProcess()
    {
        switch (Tools::getValue('action')) {
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
        (new PrestaShop\Module\Ciklik\Api\Subscription($this->context->link))->update(
            Tools::getValue('uuid'),
            ['active' => false]
        );

        Tools::redirect($this->context->link->getModuleLink('ciklik', 'account'));
    }

    private function resume()
    {
        (new PrestaShop\Module\Ciklik\Api\Subscription($this->context->link))->update(
            Tools::getValue('uuid'),
            ['active' => true]
        );

        Tools::redirect($this->context->link->getModuleLink('ciklik', 'account'));
    }

    private function newdate()
    {
        $date = Carbon\Carbon::parse(Tools::getValue('next_billing'));

        $result = (new PrestaShop\Module\Ciklik\Api\Subscription($this->context->link))->update(
            Tools::getValue('uuid'),
            ['next_billing' => $date->toDateString()]
        );

        if (count($result['errors'])) {
            foreach ($result['errors'] as $key => $error) {
                $this->errors[] = $error[0];
            }
        } else {
            $this->success[] = 'La date de renouvellement de votre abonnement a bien été modifiée.';
        }

        $this->redirectWithNotifications($this->context->link->getModuleLink('ciklik', 'account'));
    }

    private function updateaddress()
    {
        $address = new Address(Tools::getValue('changeAddressForm'));

        if ($this->context->customer->id !== (int) $address->id_customer) {
            throw new PrestaShop\Module\Ciklik\Exceptions\NotAllowedException();
        }

        $sub = (new PrestaShop\Module\Ciklik\Api\Subscription($this->context->link))->getOne(
            Tools::getValue('uuid')
        );

        $sub = SubscriptionData::create($sub['body']);
        $sub->external_fingerprint->id_address_delivery = (int) Tools::getValue('changeAddressForm');

        $result = (new PrestaShop\Module\Ciklik\Api\Subscription($this->context->link))->update(
            Tools::getValue('uuid'),
            ['metadata' => ['prestashop_fingerprint' => $sub->external_fingerprint->encodeDatas()]]
        );

        if (count($result['errors'])) {
            foreach ($result['errors'] as $key => $error) {
                $this->errors[] = $error[0];
            }
        } else {
            $this->success[] = 'Votre nouvelle adresse a bien été prise en compte';
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
        // Récupérer les données du formulaire
        $subscriptionUuid = Tools::getValue('uuid');

        $useFrequencyMode = Tools::getValue('use_frequency_mode');

        // Récupérer l'abonnement actuel
        $subscriptionApi = new PrestaShop\Module\Ciklik\Api\Subscription($this->context->link);
        $currentSubscription = $subscriptionApi->getOne($subscriptionUuid);
        $subscriptionData = SubscriptionData::create($currentSubscription['body']);

        if ($useFrequencyMode === '1') {
            $frequencyId = (int) Tools::getValue('product_combination');
            $frequency = CiklikFrequency::getFrequencyById($frequencyId);
            $subscriptionData->external_fingerprint->frequency_id = $frequencyId;

            $result = (new PrestaShop\Module\Ciklik\Api\Subscription($this->context->link))
            ->update(
                $subscriptionUuid,
                [
                    'metadata' => ['prestashop_fingerprint' => $subscriptionData->external_fingerprint->encodeDatas()],
                    'interval' => $frequency['interval'],
                    'interval_count' => (int) $frequency['interval_count']
                ]
            );

            if (count($result['errors'])) {
                foreach ($result['errors'] as $key => $error) {
                    $this->errors[] = $error[0];
                }
            } else {
                $this->success[] = 'Votre nouvelle adresse a bien été prise en compte';
            }

            $this->redirectWithNotifications($this->context->link->getModuleLink('ciklik', 'account'));

        } else {
            $newCombinationId = (int)Tools::getValue('product_combination');
            // Récupérer les informations de la nouvelle combinaison
            $newCombination = CiklikCombination::getCombinationDetails($newCombinationId);
            if (!$newCombination) {
                $this->errors[] = 'Combinaison invalide.';
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
                        'interval_count' => $matchingCombination['interval_count']
                    ];
                }
            }

            // Préparer les données pour la mise à jour de l'API
            $updateData = [
                'content' => $updatedContents
            ];

            // Envoyer la mise à jour à l'API
            $result = $subscriptionApi->update(
                $subscriptionUuid,
                $updateData
            );

            if (isset($result['errors']) && count($result['errors'])) {
                foreach ($result['errors'] as $error) {
                    $this->errors[] = $error[0];
                }
            } else {
                $this->success[] = 'Le contenu de votre abonnement a été mis à jour avec succès.';
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
        $productId = Tools::getValue('id_product');
        $productAttributeId = Tools::getValue('id_product_attribute');
        $quantity = Tools::getValue('quantity');
        
        $uuid = Tools::getValue('uuid');
        

        $subscriptionApi = new PrestaShop\Module\Ciklik\Api\Subscription($this->context->link);
    
        $upsell[] = [
            'product_id' => $productId,
            'product_attribute_id' => $productAttributeId,
            'quantity' => $quantity,
        ];
       

        $subscriptionApi->update(
            $uuid,
            ['upsells' => $upsell]
        );
    

        $this->ajaxRenderAndExit(json_encode([
            'success' => true,
            'message' => $this->module->l('Le produit a bien été ajouté à votre abonnement'),
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
}
