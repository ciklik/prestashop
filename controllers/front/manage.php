<?php

/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

use PrestaShop\Module\Ciklik\Api\Subscription;
use PrestaShop\Module\Ciklik\Helpers\UuidHelper;
use PrestaShop\Module\Ciklik\Managers\CiklikRefund;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CiklikManageModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        // Vérification de l'accès admin (même logique que le refund)
        if (!CiklikRefund::canRun()) {
            $this->ajaxFailAndDie(
                $this->module->l('Access denied', 'manage'),
            );
        }

        // Vérification du token CSRF
        $token = Tools::getValue('ajax_token');
        $expectedToken = sha1(_COOKIE_KEY_ . 'ciklik_manage');
        if (!$token || !hash_equals($expectedToken, $token)) {
            $this->ajaxFailAndDie(
                $this->module->l('Invalid security token', 'manage'),
                403,
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
            default:
                $this->ajaxFailAndDie(
                    $this->module->l('Invalid action', 'manage'),
                );
        }
    }

    /**
     * Désactive un abonnement
     */
    private function handleDeactivate()
    {
        $uuid = $this->getValidatedUuid();

        try {
            $response = (new Subscription($this->context->link))->update($uuid, [
                'active' => false,
            ]);
        } catch (Exception $e) {
            $this->ajaxFailAndDie(
                $this->module->l('An error occurred while updating the subscription.', 'manage'),
            );
        }

        if (!$response['status']) {
            $this->ajaxFailAndDie(
                $this->module->l('An error occurred while updating the subscription.', 'manage'),
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

        try {
            $response = (new Subscription($this->context->link))->update($uuid, [
                'active' => true,
            ]);
        } catch (Exception $e) {
            $this->ajaxFailAndDie(
                $this->module->l('An error occurred while updating the subscription.', 'manage'),
            );
        }

        if (!$response['status']) {
            $this->ajaxFailAndDie(
                $this->module->l('An error occurred while updating the subscription.', 'manage'),
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
        $nextBilling = Tools::getValue('next_billing');

        // Validation du format YYYY-MM-DD
        if (!$nextBilling || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $nextBilling)) {
            $this->ajaxFailAndDie(
                $this->module->l('Invalid date format', 'manage'),
            );
        }

        // Validation que la date est dans le futur
        $date = DateTime::createFromFormat('Y-m-d', $nextBilling);
        if (!$date || $date->format('Y-m-d') !== $nextBilling) {
            $this->ajaxFailAndDie(
                $this->module->l('Invalid date format', 'manage'),
            );
        }

        $tomorrow = new DateTime('tomorrow');
        if ($date < $tomorrow) {
            $this->ajaxFailAndDie(
                $this->module->l('The date must be in the future', 'manage'),
            );
        }

        try {
            $response = (new Subscription($this->context->link))->update($uuid, [
                'next_billing' => $nextBilling,
            ]);
        } catch (Exception $e) {
            $this->ajaxFailAndDie(
                $this->module->l('An error occurred while updating the subscription.', 'manage'),
            );
        }

        if (!$response['status']) {
            $this->ajaxFailAndDie(
                $this->module->l('An error occurred while updating the subscription.', 'manage'),
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
     * Valide et retourne l'UUID de l'abonnement depuis la requête
     *
     * @return string UUID validé
     */
    private function getValidatedUuid()
    {
        $uuid = UuidHelper::getFromRequest('subscriptionUuid');

        if (!$uuid) {
            $this->ajaxFailAndDie(
                $this->module->l('Invalid subscription UUID', 'manage'),
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
        $this->ajaxRender($value, $controller, $method);
        exit;
    }
}
