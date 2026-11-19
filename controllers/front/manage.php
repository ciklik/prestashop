<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

use PrestaShop\Module\Ciklik\Api\CiklikApiResponseHandler;
use PrestaShop\Module\Ciklik\Api\Subscription;
use PrestaShop\Module\Ciklik\Data\SubscriptionData;
use PrestaShop\Module\Ciklik\Helpers\RelaySearchQuota;
use PrestaShop\Module\Ciklik\Helpers\UuidHelper;
use PrestaShop\Module\Ciklik\Managers\CiklikDeliveryOverride;
use PrestaShop\Module\Ciklik\Managers\CiklikRefund;
use PrestaShop\Module\Ciklik\Managers\CiklikRelaySearch;
use PrestaShop\Module\Ciklik\Managers\CiklikSubscriptionFrequency;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CiklikManageModuleFrontController extends ModuleFrontController
{
    /**
     * Champs de payload relais acceptés : règle partagée avec l'espace
     * client, portée par CiklikDeliveryOverride. Alias conservé pour les
     * intégrations qui lisaient la constante ici.
     */
    const RELAY_PAYLOAD_RULES = CiklikDeliveryOverride::RELAY_PAYLOAD_RULES;

    /**
     * Longueurs maximales de l'identifiant relais par module : règle partagée
     * avec l'espace client, portée par CiklikDeliveryOverride. Alias conservé
     * pour les intégrations qui lisaient la constante ici.
     */
    const RELAY_ID_MAX_LENGTHS = CiklikDeliveryOverride::RELAY_ID_MAX_LENGTHS;

    /** @var Employee|null Employé BO authentifié (posé par postProcess) */
    private $employee;

    public function postProcess()
    {
        // Actions mutantes ou coûteuses : POST uniquement. Évite aussi la
        // fuite du token dans les journaux serveur / referers via des URLs GET.
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : '';
        if ('POST' !== $method) {
            $this->ajaxFailAndDie(
                $this->module->l('Invalid request method', 'manage'),
                405
            );
        }

        // Vérification de l'accès admin (même logique que le refund), en
        // conservant l'employé pour les contrôles de profil et l'audit
        $this->employee = CiklikRefund::getAuthenticatedEmployee();
        if (null === $this->employee) {
            $this->ajaxFailAndDie(
                $this->module->l('Access denied', 'manage')
            );
        }

        // Vérification du token CSRF
        $token = Tools::getValue('ajax_token');
        $expectedToken = sha1(_COOKIE_KEY_ . 'ciklik_manage');
        if (!$token || !hash_equals($expectedToken, $token)) {
            $this->ajaxFailAndDie(
                $this->module->l('Invalid security token', 'manage'),
                403
            );
        }

        $action = Tools::getValue('action');

        switch ($action) {
            case 'deactivate':
                $this->handleDeactivate();
                break;
            case 'activate':
                $this->handleActivate();
                break;
            case 'changeNextBilling':
                $this->handleChangeNextBilling();
                break;
            case 'saveRelayOverride':
                $this->handleSaveRelayOverride();
                break;
            case 'resetRelayOverride':
                $this->handleResetRelayOverride();
                break;
            case 'searchRelays':
                $this->handleSearchRelays();
                break;
            case 'changeFrequency':
                $this->handleChangeFrequency();
                break;
            default:
                $this->ajaxFailAndDie(
                    $this->module->l('Invalid action', 'manage')
                );
        }
    }

    /**
     * Désactive un abonnement
     */
    private function handleDeactivate()
    {
        $uuid = $this->getValidatedUuid();
        $response = null;

        try {
            $response = (new Subscription($this->context->link))->update($uuid, [
                'active' => false,
            ]);
        } catch (Throwable $e) {
            $this->ajaxFailAndDie(
                $this->module->l('An error occurred while updating the subscription.', 'manage')
            );
        }

        if (!$response['status']) {
            $this->ajaxFailAndDie(
                $this->module->l('An error occurred while updating the subscription.', 'manage')
            );
        }

        $this->ajaxRenderAndExit(json_encode([
            'success' => true,
            'message' => $this->module->l('Subscription has been deactivated.', 'manage'),
            'subscription' => [
                'active' => false,
            ],
        ]));
    }

    /**
     * Réactive un abonnement
     */
    private function handleActivate()
    {
        $uuid = $this->getValidatedUuid();
        $response = null;

        try {
            $response = (new Subscription($this->context->link))->update($uuid, [
                'active' => true,
            ]);
        } catch (Throwable $e) {
            $this->ajaxFailAndDie(
                $this->module->l('An error occurred while updating the subscription.', 'manage')
            );
        }

        if (!$response['status']) {
            $this->ajaxFailAndDie(
                $this->module->l('An error occurred while updating the subscription.', 'manage')
            );
        }

        $this->ajaxRenderAndExit(json_encode([
            'success' => true,
            'message' => $this->module->l('Subscription has been activated.', 'manage'),
            'subscription' => [
                'active' => true,
            ],
        ]));
    }

    /**
     * Change la date de prochain paiement
     */
    private function handleChangeNextBilling()
    {
        $uuid = $this->getValidatedUuid();
        $response = null;
        $nextBilling = Tools::getValue('next_billing');

        // Validation du format YYYY-MM-DD
        if (!$nextBilling || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $nextBilling)) {
            $this->ajaxFailAndDie(
                $this->module->l('Invalid date format', 'manage')
            );
        }

        // Validation que la date est dans le futur
        $date = DateTime::createFromFormat('Y-m-d', $nextBilling);
        if (!$date || $date->format('Y-m-d') !== $nextBilling) {
            $this->ajaxFailAndDie(
                $this->module->l('Invalid date format', 'manage')
            );
        }

        $tomorrow = new DateTime('tomorrow');
        if ($date < $tomorrow) {
            $this->ajaxFailAndDie(
                $this->module->l('The date must be in the future', 'manage')
            );
        }

        try {
            $response = (new Subscription($this->context->link))->update($uuid, [
                'next_billing' => $nextBilling,
            ]);
        } catch (Throwable $e) {
            $this->ajaxFailAndDie(
                $this->module->l('An error occurred while updating the subscription.', 'manage')
            );
        }

        if (!$response['status']) {
            $this->ajaxFailAndDie(
                $this->module->l('An error occurred while updating the subscription.', 'manage')
            );
        }

        $this->ajaxRenderAndExit(json_encode([
            'success' => true,
            'message' => $this->module->l('Next billing date has been updated.', 'manage'),
            'subscription' => [
                'next_billing' => $nextBilling,
            ],
        ]));
    }

    /**
     * Enregistre l'override de point relais du client pour un transporteur.
     * Il sera appliqué par les drivers de DeliveryModuleManager aux prochains
     * rebills, sans toucher aux commandes passées.
     */
    private function handleSaveRelayOverride()
    {
        $this->assertEmployeeCanEditOrders();

        list($idCustomer) = $this->resolveOrderContext();
        $carrierModule = strtolower((string) Tools::getValue('carrier_module'));
        $relayId = trim((string) Tools::getValue('relay_id'));

        if (!CiklikDeliveryOverride::isValidRelayId($carrierModule, $relayId)) {
            $this->ajaxFailAndDie(
                $this->module->l('Invalid pickup point data', 'manage'),
                400
            );
        }

        $payload = [];
        foreach (array_keys(CiklikDeliveryOverride::RELAY_PAYLOAD_RULES) as $field) {
            $value = Tools::getValue('relay_' . $field);
            if (false === $value) {
                continue;
            }

            // Nettoyage : balises, caractères de contrôle, retours-ligne
            $value = CiklikDeliveryOverride::cleanPayloadValue($value);
            if ('' === $value) {
                continue;
            }

            $payload[$field] = $value;
        }

        // Le code pays doit rester un ISO alpha-2 exploitable par les drivers
        if (isset($payload['country_iso']) && !preg_match('/^[a-zA-Z]{2}$/', $payload['country_iso'])) {
            unset($payload['country_iso']);
        }

        // Longueurs et validateurs PrestaShop : même règle que l'espace client
        if (!CiklikDeliveryOverride::isValidPayload($payload)) {
            $this->ajaxFailAndDie(
                $this->module->l('Invalid pickup point data', 'manage'),
                400
            );
        }

        if (!CiklikDeliveryOverride::save($idCustomer, $carrierModule, $relayId, $payload)) {
            $this->ajaxFailAndDie(
                $this->module->l('Unable to save the pickup point', 'manage')
            );
        }

        $this->logRelayAudit('save', $idCustomer, $carrierModule, $relayId);

        $this->ajaxRenderAndExit(json_encode([
            'success' => true,
            'message' => $this->module->l('Pickup point saved. It will be used for the next payments.', 'manage'),
        ]));
    }

    /**
     * Supprime l'override : retour au comportement automatique (relais de la
     * dernière commande payée) au prochain rebill.
     */
    private function handleResetRelayOverride()
    {
        $this->assertEmployeeCanEditOrders();

        list($idCustomer) = $this->resolveOrderContext();
        $carrierModule = strtolower((string) Tools::getValue('carrier_module'));

        if (!in_array($carrierModule, CiklikDeliveryOverride::SUPPORTED_MODULES, true)) {
            $this->ajaxFailAndDie(
                $this->module->l('Invalid pickup point data', 'manage'),
                400
            );
        }

        CiklikDeliveryOverride::delete($idCustomer, $carrierModule);

        $this->logRelayAudit('reset', $idCustomer, $carrierModule, '');

        $this->ajaxRenderAndExit(json_encode([
            'success' => true,
            'message' => $this->module->l('Pickup point reset to automatic mode.', 'manage'),
        ]));
    }

    /**
     * Change la fréquence de l'abonnement de la commande consultée, par la
     * logique de « Mes abonnements » (CiklikSubscriptionFrequency), même si
     * l'option client est désactivée : c'est le marchand qui agit. La date de
     * la prochaine commande ne change pas.
     */
    private function handleChangeFrequency()
    {
        $this->assertEmployeeCanEditOrders();

        list($idCustomer, $idShop) = $this->resolveOrderContext();
        $uuid = $this->getValidatedUuid();
        $value = (int) Tools::getValue('frequency');

        // Jeton d'API, mode et fréquences proposées de la boutique de la commande
        if ($idShop > 0 && Shop::isFeatureActive()) {
            Shop::setContext(Shop::CONTEXT_SHOP, $idShop);
        }

        $api = new Subscription($this->context->link);
        $subscription = null;

        try {
            $response = $api->getOne($uuid);
            if (!empty($response['status']) && !empty($response['body']['external_fingerprint'])) {
                $subscription = SubscriptionData::create($response['body']);
            }
        } catch (Throwable $e) {
            $subscription = null;
        }

        if (null === $subscription) {
            $this->ajaxFailAndDie(
                $this->module->l('An error occurred while updating the subscription.', 'manage')
            );
        }

        // L'abonnement doit être celui du client de la commande : l'UUID vient
        // de la requête, le client de la commande dont l'accès a été vérifié
        if ((int) $subscription->external_fingerprint->id_customer !== $idCustomer) {
            $this->ajaxFailAndDie($this->module->l('Access denied', 'manage'), 403);
        }

        // Mode de création de l'abonnement, comme les choix affichés
        $useFrequencyMode = $subscription->frequency_mode;

        if ($useFrequencyMode && $value === (int) $subscription->external_fingerprint->frequency_id) {
            $this->ajaxFailAndDie($this->module->l('This is already the frequency of the subscription.', 'manage'), 400);
        }

        try {
            $result = CiklikSubscriptionFrequency::change($api, $uuid, $subscription->contents, $subscription->external_fingerprint, $useFrequencyMode, $value);
        } catch (Throwable $e) {
            $result = ['success' => false, 'error' => CiklikSubscriptionFrequency::ERROR_API, 'response' => null];
        }

        if (CiklikSubscriptionFrequency::ERROR_INVALID_CHOICE === $result['error']) {
            $this->ajaxFailAndDie($this->module->l('This frequency is not available for this subscription.', 'manage'), 400);
        }

        if (CiklikSubscriptionFrequency::ERROR_NO_MATCH === $result['error']) {
            $this->ajaxFailAndDie($this->module->l('This frequency is not available for every product of the subscription.', 'manage'), 400);
        }

        if (!$result['success']) {
            // Motif du refus de Ciklik, échappé : le message s'affiche en HTML
            $apiMessage = CiklikApiResponseHandler::customerErrorMessage($result['response'], '');
            $this->ajaxFailAndDie(
                $this->module->l('An error occurred while updating the subscription.', 'manage') . ('' !== $apiMessage ? ' ' . $apiMessage : ''),
                400
            );
        }

        PrestaShopLogger::addLog(
            'Ciklik frequency change - subscription ' . $uuid . ' - '
                . ($useFrequencyMode ? 'frequency_id ' : 'id_product_attribute ') . $value,
            1,
            null,
            'CiklikSubscription',
            $idCustomer,
            true,
            (int) $this->employee->id
        );

        $body = isset($result['response']['body']) && is_array($result['response']['body']) ? $result['response']['body'] : [];

        $this->ajaxRenderAndExit(json_encode([
            'success' => true,
            'message' => $this->module->l('The frequency has been updated. The next order date is unchanged.', 'manage'),
            'subscription' => [
                'display_interval' => isset($body['display_interval']) ? (string) $body['display_interval'] : (string) $result['choice']['display_name'],
                'frequency' => $value,
            ],
        ]));
    }

    /**
     * Redérive le client et la boutique depuis la commande consultée en BO.
     *
     * Ce contrôleur est un contrôleur front : tout ce qui arrive dans la requête
     * est sous le contrôle de l'appelant. On ne lit donc ni id_customer ni
     * id_shop, sans quoi un employé pourrait poser un relais sur le client d'une
     * autre boutique. Seul id_order est accepté, et on vérifie que l'employé a
     * bien le droit sur la boutique de cette commande.
     *
     * @return array{0: int, 1: int, 2: Order} [id_customer, id_shop, commande]
     */
    private function resolveOrderContext()
    {
        $order = new Order((int) Tools::getValue('id_order'));

        if (!Validate::isLoadedObject($order)
            || !$this->employee
            || (!$this->employee->isSuperAdmin() && !$this->employee->hasAuthOnShop((int) $order->id_shop))) {
            $this->ajaxFailAndDie(
                $this->module->l('Access denied', 'manage'),
                403
            );
        }

        return [(int) $order->id_customer, (int) $order->id_shop, $order];
    }

    /**
     * Vérifie que l'employé authentifié a le droit de modifier les commandes :
     * super admin, ou droit d'édition sur l'onglet AdminOrders. Le simple fait
     * d'être connecté au BO (canRun) ne suffit pas pour rediriger des colis
     * ni pour changer la fréquence d'un abonnement.
     */
    private function assertEmployeeCanEditOrders()
    {
        if ($this->employee && $this->employee->isSuperAdmin()) {
            return;
        }

        $idTab = (int) Tab::getIdFromClassName('AdminOrders');
        $access = ($this->employee && $idTab)
            ? Profile::getProfileAccess((int) $this->employee->id_profile, $idTab)
            : null;

        if (empty($access['edit'])) {
            $this->ajaxFailAndDie(
                $this->module->l('Access denied', 'manage'),
                403
            );
        }
    }

    /**
     * Journal d'audit des changements de point relais : qui (employé), pour
     * quel client, quel transporteur, quel relais.
     *
     * @param string $operation save|reset
     * @param int $idCustomer
     * @param string $carrierModule
     * @param string $relayId
     */
    private function logRelayAudit($operation, $idCustomer, $carrierModule, $relayId)
    {
        PrestaShopLogger::addLog(
            'Ciklik relay override ' . $operation
                . ' - customer ' . (int) $idCustomer
                . ' - carrier ' . $carrierModule
                . ('' !== $relayId ? ' - relay ' . $relayId : ''),
            1,
            null,
            'CiklikDeliveryOverride',
            (int) $idCustomer,
            true,
            $this->employee ? (int) $this->employee->id : null
        );
    }

    /**
     * Recherche de points relais via l'API du transporteur (proxy serveur,
     * credentials du module transporteur voisin). Résultats normalisés pour
     * l'UI générique liste + carte.
     */
    private function handleSearchRelays()
    {
        $this->assertEmployeeCanEditOrders();

        // Les credentials transporteur sont lus via Configuration::get, résolue
        // sur la boutique de contexte. La recherche tourne côté front (ce
        // contrôleur) : on force la boutique de la commande consultée en BO pour
        // que les bons credentials soient utilisés en multiboutique. La boutique
        // vient de la commande, jamais de la requête : sinon un employé pourrait
        // faire consommer le compte transporteur d'une autre boutique.
        list(, $idShop, $order) = $this->resolveOrderContext();
        if ($idShop > 0 && Shop::isFeatureActive()) {
            Shop::setContext(Shop::CONTEXT_SHOP, $idShop);
        }

        $carrierModule = strtolower((string) Tools::getValue('carrier_module'));

        if (!in_array($carrierModule, CiklikDeliveryOverride::SUPPORTED_MODULES, true)
            || !CiklikRelaySearch::supportsSearch($carrierModule)) {
            $this->ajaxFailAndDie(
                $this->module->l('Search is not available for this carrier', 'manage'),
                400
            );
        }

        // Mêmes bornes que l'espace client avant tout appel au transporteur
        $zipcode = trim((string) Tools::getValue('zipcode'));
        $city = trim((string) Tools::getValue('city'));
        if (!CiklikRelaySearch::isValidSearchAddress($zipcode, $city)) {
            $this->ajaxFailAndDie(
                $this->module->l('Please enter a valid zip code and city.', 'manage'),
                400
            );
        }

        // Plafond d'appels au compte transporteur, compté par employé
        $quota = RelaySearchQuota::consumeForEmployee((int) $this->employee->id, $idShop);
        if (RelaySearchQuota::GRANTED !== $quota) {
            $this->ajaxFailAndDie(
                RelaySearchQuota::LIMITED === $quota
                    ? $this->module->l('Too many searches for now. Please try again later or use manual entry.', 'manage')
                    : $this->module->l('Pickup point search is temporarily unavailable. Please try again later.', 'manage'),
                RelaySearchQuota::LIMITED === $quota ? 429 : 503
            );
        }

        try {
            // Transporteur de la commande : choisit le contrat de son offre
            // quand le module transporteur en a plusieurs (Chronopost)
            $results = CiklikRelaySearch::searchRelays($carrierModule, [
                'zipcode' => $zipcode,
                'city' => $city,
                'country_iso' => (string) Tools::getValue('country_iso'),
            ], null, CiklikRelaySearch::carrierReferenceOf((int) $order->id_carrier));
        } catch (Throwable $e) {
            $this->ajaxFailAndDie(
                $this->module->l('Pickup point search failed. Please try again or use manual entry.', 'manage')
            );
        }

        $this->ajaxRenderAndExit(json_encode([
            'success' => true,
            'results' => $results,
        ]));
    }

    /**
     * Valide et retourne l'UUID de l'abonnement depuis la requête
     *
     * @return string UUID validé
     */
    private function getValidatedUuid()
    {
        $uuid = UuidHelper::getFromRequest('subscriptionUuid');

        if (!$uuid) {
            $this->ajaxFailAndDie(
                $this->module->l('Invalid subscription UUID', 'manage')
            );
        }

        return $uuid;
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
}
