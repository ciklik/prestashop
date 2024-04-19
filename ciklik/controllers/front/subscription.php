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
}
