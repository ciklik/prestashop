<?php

use PrestaShop\Module\Ciklik\Data\SubscriptionData;

/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

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
        }
    }

    private function stop()
    {
        (new \PrestaShop\Module\Ciklik\Api\Subscription($this->context->link))->update(
            Tools::getValue('uuid'),
            ['active' => false]
        );

        Tools::redirect($this->context->link->getModuleLink('ciklik', 'account'));
    }

    private function resume()
    {
        (new \PrestaShop\Module\Ciklik\Api\Subscription($this->context->link))->update(
            Tools::getValue('uuid'),
            ['active' => true]
        );

        Tools::redirect($this->context->link->getModuleLink('ciklik', 'account'));
    }

    private function newdate()
    {
        $date = \Carbon\Carbon::parse(Tools::getValue('next_billing'));

        $result = (new \PrestaShop\Module\Ciklik\Api\Subscription($this->context->link))->update(
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

        $address = new \Address(Tools::getValue('changeAddressForm'));

        if ($this->context->customer->id !== (int) $address->id_customer) {
            throw new PrestaShop\Module\Ciklik\Exceptions\NotAllowedException();
        }

        $sub = (new \PrestaShop\Module\Ciklik\Api\Subscription($this->context->link))->getOne(
            Tools::getValue('uuid'),
        );

        $sub = SubscriptionData::create($sub['body']);
        $sub->external_fingerprint->id_address_delivery = (int) Tools::getValue('changeAddressForm');

        $result = (new \PrestaShop\Module\Ciklik\Api\Subscription($this->context->link))->update(
            Tools::getValue('uuid'),
            ['metadata' => ['prestashop_fingerprint' => $sub->external_fingerprint->serialize()]]
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
}
