<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

use PrestaShop\Module\Ciklik\Data\OrderData;
use PrestaShop\Module\Ciklik\Data\OrderValidationData;
use PrestaShop\Module\Ciklik\Helpers\ThreadHelper;
use PrestaShop\Module\Ciklik\Managers\CiklikCustomer;

if (!defined('_PS_VERSION_')) {
    exit;
}
class CiklikValidationModuleFrontController extends ModuleFrontController
{
    use ThreadHelper;
    /**
     * @var PaymentModule
     */
    public $module;

    /**
     * {@inheritdoc}
     */
    public function postProcess()
    {
        if (false === $this->checkIfContextIsValid() || false === $this->checkIfPaymentOptionIsAvailable()) {
            $this->redirectToCheckout();
        }

        $customer = new Customer($this->context->cart->id_customer);

        if (false === Validate::isLoadedObject($customer)) {
            $this->redirectToCheckout();
        }

        $orderData = (new \PrestaShop\Module\Ciklik\Api\Order($this->context->link))->getOne((int) Tools::getValue('ciklik_order_id'));

        if (!$orderData instanceof OrderData) {
            $this->redirectToCheckout();
        }

        $orderValidationData = OrderValidationData::create($this->context->cart, $orderData);

        $this->module->validateOrder(
            $orderValidationData->id_cart,
            $orderValidationData->id_order_state,
            $orderValidationData->amount_paid,
            $orderValidationData->payment_method,
            $orderValidationData->message,
            $orderValidationData->extra_vars,
            $orderValidationData->currency_special,
            $orderValidationData->dont_touch_amount,
            $orderValidationData->secure_key
        );

        CiklikCustomer::save($customer->id, $orderData->ciklik_user_uuid);

        $this->addDataToOrder((int) $this->module->currentOrder, [
            'ciklik_order_id' => $orderData->ciklik_order_id,
            'order_type' => 'subscription_creation',
            'subscription_uuid' => Tools::getValue('ciklik_subscription_uuid'),
        ]);

        Tools::redirect($this->context->link->getPageLink(
            'order-confirmation',
            true,
            (int) $this->context->language->id,
            [
                'id_cart' => (int) $this->context->cart->id,
                'id_module' => (int) $this->module->id,
                'id_order' => (int) $this->module->currentOrder,
                'key' => $customer->secure_key,
            ]
        ));
    }

    private function redirectToCheckout()
    {
        Tools::redirect($this->context->link->getPageLink(
            'order',
            true,
            (int) $this->context->language->id,
            [
                'step' => 1,
            ]
        ));
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
