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
use PrestaShop\Module\Ciklik\Managers\CiklikItemFrequency;

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

        $orderData = (new PrestaShop\Module\Ciklik\Api\Order($this->context->link))->getOne((int) Tools::getValue('ciklik_order_id'));

        if (!$orderData instanceof OrderData) {
            $this->redirectToCheckout();
        }

        // Idempotence (cas légitime) : une commande existe déjà pour CE panier. Cela arrive
        // quand le push serveur (OrderGateway) a validé la commande avant que le navigateur
        // du client n'atteigne cette URL — fallback volontaire si l'utilisateur a fermé son
        // onglet ou subi une coupure après le paiement —, ou en cas de double soumission.
        // On ne recrée rien : on renvoie le client vers la confirmation de SA commande.
        if ($this->context->cart->orderExists()) {
            $existingOrderId = (int) Db::getInstance()->getValue(
                'SELECT id_order FROM ' . _DB_PREFIX_ . 'orders WHERE id_cart = ' . (int) $this->context->cart->id
            );
            $this->redirectToConfirmation($customer, $existingOrderId);

            return;
        }

        // Appartenance : si le client est déjà lié à un compte Ciklik, la commande validée
        // doit appartenir à ce même compte (empêche l'usage de l'identifiant de commande
        // d'un autre client). Lors d'une première souscription, aucun lien n'existe encore :
        // la garde anti-rejeu ci-dessous et le secure_key du panier assurent la sécurité.
        $existingLink = CiklikCustomer::getByIdCustomer((int) $customer->id);
        if (!empty($existingLink['ciklik_uuid']) && $existingLink['ciklik_uuid'] !== $orderData->ciklik_user_uuid) {
            $this->redirectToCheckout();
        }

        // Anti-rejeu : ce ciklik_order_id est déjà rattaché à une AUTRE commande PrestaShop
        // (le panier courant, lui, n'a pas de commande — vérifié juste au-dessus). Le
        // `ciklik_order_id` transite en clair dans l'URL de retour (aucune signature) : sans
        // cette garde, un client pourrait rejouer un identifiant de commande « completed »
        // sur un panier neuf et obtenir une commande payée sans paiement réel.
        if (null !== $orderData->prestashop_order_id) {
            $this->redirectToCheckout();
        }

        // Anti-rejeu par le panier, pour un paiement en attente : sans transaction payée, la garde par
        // order_payment ci-dessous ne joue pas. Un identifiant de commande rejoué sur un autre panier est refusé
        // même avant le push serveur. Un paiement encaissé garde les gardes d'avant (un panier PrestaShop
        // remplacé entre le tunnel et le retour ne doit pas renvoyer un client qui a payé vers le tunnel).
        if ($orderData->isPaymentPending() && !$orderData->isForCart((int) $this->context->cart->id)) {
            $this->redirectToCheckout();
        }

        // Anti-rejeu auto-suffisant : `prestashop_order_id` n'est renseigné côté Ciklik qu'au
        // moment du push serveur. Entre la création de la commande par cette voie front et ce
        // push, la transaction de paiement est déjà inscrite dans `order_payment` ; si une
        // commande porte déjà cette transaction, c'est un rejeu sur un panier neuf → on bloque.
        if (!empty($orderData->paid_transaction_id)) {
            $transactionAlreadyUsed = (int) Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'order_payment WHERE `transaction_id` = "' . pSQL($orderData->paid_transaction_id) . '"'
            );
            if ($transactionAlreadyUsed > 0) {
                $this->redirectToCheckout();
            }
        }

        $orderValidationData = OrderValidationData::create($this->context->cart, $orderData);

        // Statut différencié pour les créations d'abonnement (jamais à la place de « en attente de paiement »)
        $orderValidationData->applyCreationState();

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

        if (Configuration::get(Ciklik::CONFIG_USE_FREQUENCY_MODE)) {
            // Lier les fréquences du panier à la commande
            CiklikItemFrequency::updateOrderIdFromCart($this->context->cart->id, $this->module->currentOrder);
        }

        CiklikCustomer::save($customer->id, $orderData->ciklik_user_uuid);

        $this->addDataToOrder((int) $this->module->currentOrder, [
            'ciklik_order_id' => $orderData->ciklik_order_id,
            'order_type' => 'subscription_creation',
            'subscription_uuid' => Tools::getValue('ciklik_subscription_uuid'),
        ]);

        $this->redirectToConfirmation($customer, (int) $this->module->currentOrder);
    }

    /**
     * Redirige le client vers la page de confirmation d'une commande existante.
     *
     * @param Customer $customer Client propriétaire du panier (pour le secure_key)
     * @param int $orderId ID de la commande PrestaShop à confirmer
     */
    private function redirectToConfirmation(Customer $customer, $orderId)
    {
        Tools::redirect($this->context->link->getPageLink(
            'order-confirmation',
            true,
            (int) $this->context->language->id,
            [
                'id_cart' => (int) $this->context->cart->id,
                'id_module' => (int) $this->module->id,
                'id_order' => (int) $orderId,
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
