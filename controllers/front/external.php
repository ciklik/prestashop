<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */
use PrestaShop\Module\Ciklik\Helpers\CheckoutUrlSigner;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CiklikExternalModuleFrontController extends ModuleFrontController
{
    /**
     * {@inheritdoc}
     */
    public function postProcess()
    {
        if (false === $this->checkIfContextIsValid() || false === $this->checkIfPaymentOptionIsAvailable()) {
            Tools::redirect($this->context->link->getPageLink(
                'order',
                true,
                (int) $this->context->language->id,
                [
                    'step' => 1,
                ]
            ));
        }

        $customer = new Customer($this->context->cart->id_customer);

        if (false === Validate::isLoadedObject($customer)) {
            Tools::redirect($this->context->link->getPageLink(
                'order',
                true,
                (int) $this->context->language->id,
                [
                    'step' => 1,
                ]
            ));
        }
    }

    /**
     * {@inheritdoc}
     */
    public function initContent()
    {
        parent::initContent();

        $host = Configuration::get(Ciklik::CONFIG_HOST);
        $psp = Tools::getValue('class_key');

        // Validation du paramètre class_key pour éviter les injections de chemin
        if (!$psp || !preg_match('/^[a-zA-Z0-9_-]+$/', $psp)) {
            Tools::redirect($this->context->link->getPageLink(
                'order',
                true,
                (int) $this->context->language->id,
                ['step' => 1]
            ));
        }

        $idCustomer = (int) $this->context->cart->id_customer;
        $idCart = (int) $this->context->cart->id;
        $url = "https://{$host}/vendors/prestashop/customers/{$idCustomer}/carts/{$idCart}/psp/{$psp}";

        // Lien signé avec la clé du webservice Ciklik : Ciklik refuse un lien qui ne vient pas de la boutique
        $webservice = new WebserviceKey((int) Configuration::get(Ciklik::CONFIG_WEBSERVICE_ID));
        if (!empty($webservice->key)) {
            $url .= '?' . http_build_query(CheckoutUrlSigner::sign($idCustomer, $idCart, $psp, (string) $webservice->key));
        }

        Tools::redirect($url);
    }

    /**
     * Check if the context is valid
     *
     * @return bool
     */
    private function checkIfContextIsValid()
    {
        return true === Validate::isLoadedObject($this->context->cart)
            && true === Validate::isUnsignedInt($this->context->cart->id_customer)
            && true === Validate::isUnsignedInt($this->context->cart->id_address_delivery)
            && true === Validate::isUnsignedInt($this->context->cart->id_address_invoice);
    }

    /**
     * Check that this payment option is still available in case the customer changed
     * his address just before the end of the checkout process
     *
     * @return bool
     */
    private function checkIfPaymentOptionIsAvailable()
    {
        if (!Configuration::get(Ciklik::CONFIG_HOST)) {
            return false;
        }

        $modules = Module::getPaymentModules();

        if (empty($modules)) {
            return false;
        }

        foreach ($modules as $module) {
            if (isset($module['name']) && $this->module->name === $module['name']) {
                return true;
            }
        }

        return false;
    }
}
