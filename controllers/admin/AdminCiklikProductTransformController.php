<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

use PrestaShop\Module\Ciklik\Managers\CiklikStockRealigner;
use PrestaShop\Module\Ciklik\Managers\CiklikSubscriptionTransformer;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Actions AJAX du panneau « Transformer en abonnement » de la fiche produit (mode déclinaisons).
 * Onglet caché ; POST seulement, jeton admin, droit d'édition sur ce contrôleur et sur les produits.
 */
class AdminCiklikProductTransformController extends ModuleAdminController
{
    /**
     * Méthode de traduction pour compatibilité PS9
     *
     * @param string $string Chaîne à traduire
     * @param string|null $class Nom de la classe (non utilisé, pour compatibilité)
     * @param bool $addslashes Ajouter des slashes
     * @param bool $htmlentities Encoder les entités HTML
     *
     * @return string
     */
    protected function l($string, $class = null, $addslashes = false, $htmlentities = true)
    {
        try {
            if (!$this->module) {
                return $string;
            }

            return $this->module->l($string, 'AdminCiklikProductTransformController');
        } catch (Exception $e) {
            return $string;
        }
    }

    public function __construct()
    {
        $this->bootstrap = true;

        parent::__construct();
    }

    /**
     * Point d'entrée unique : les actions ne passent que par AJAX
     */
    public function postProcess()
    {
        if (!$this->ajax) {
            $this->respond(['error' => $this->l('Invalid request.')], 400);
        }

        if (!isset($_SERVER['REQUEST_METHOD']) || 'POST' !== $_SERVER['REQUEST_METHOD']
            || !$this->access('edit')
            || !Access::isGranted('ROLE_MOD_TAB_ADMINPRODUCTS_UPDATE', $this->context->employee->id_profile)) {
            $this->respond(['error' => $this->l('You do not have permission to edit this product.')], 403);
        }

        if (Configuration::get(Ciklik::CONFIG_USE_FREQUENCY_MODE)) {
            $this->respond(['error' => $this->l('This action is only available in combination mode.')], 400);
        }

        $idProduct = (int) Tools::getValue('id_product');
        $action = (string) Tools::getValue('action');

        try {
            $this->respond($this->runAction($action, $idProduct));
        } catch (DomainException $e) {
            $this->respond(['error' => $this->refusalMessage($e->getMessage())], 400);
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('Ciklik - action ' . preg_replace('/[^a-z_]/', '', $action) . ' sur le produit ' . $idProduct . ' échouée : ' . $e->getMessage(), 3);
            $this->respond(['error' => $this->l('The operation failed and was cancelled. Nothing was changed.')], 500);
        }
    }

    /**
     * @param string $action preview, transform, realign_preview ou realign_stock
     * @param int $idProduct
     *
     * @return array Données de la réponse
     *
     * @throws DomainException Refus motivé
     */
    private function runAction($action, $idProduct)
    {
        $frequencies = array_map('intval', (array) Tools::getValue('frequencies', []));

        switch ($action) {
            case 'preview':
                return ['preview' => CiklikSubscriptionTransformer::preview($idProduct, $frequencies)];
            case 'transform':
                return ['message' => $this->transformMessage(CiklikSubscriptionTransformer::transform($idProduct, $frequencies))];
            case 'realign_preview':
                return ['realign' => CiklikStockRealigner::preview($idProduct, (int) $this->context->language->id)];
            case 'realign_stock':
                return ['message' => sprintf($this->l('Stock realigned on %d subscription combinations.'), CiklikSubscriptionTransformer::realignStock($idProduct))];
        }

        throw new DomainException('invalid_request');
    }

    /**
     * Message de fin de transformation, avec le résultat de l'envoi à Ciklik
     *
     * @param array $result Résultat de CiklikSubscriptionTransformer::transform()
     *
     * @return string
     */
    private function transformMessage(array $result)
    {
        $message = sprintf($this->l('%d subscription combinations created.'), $result['created']);

        if (null === $result['sync']) {
            return $message . ' ' . $this->l('The synchronization with Ciklik failed: save the product to retry.');
        }
        if ($result['sync']['failed'] > 0) {
            return $message . ' ' . sprintf($this->l('%d combinations could not be synchronized with Ciklik: save the product to retry.'), $result['sync']['failed']);
        }

        return $message . ' ' . $this->l('Set the subscription prices in the Combinations tab.');
    }

    /**
     * Motif de refus traduit
     *
     * @param string $code Constante de SubscriptionTransformPlan, locked ou unknown_product
     *
     * @return string
     */
    private function refusalMessage($code)
    {
        $messages = [
            'already_subscription' => $this->l('This product already has subscription combinations.'),
            'incomplete_config' => $this->l('The Ciklik attribute groups or attributes are missing: check the module settings.'),
            'pack' => $this->l('Packs cannot be transformed.'),
            'virtual' => $this->l('Virtual products cannot be transformed.'),
            'advanced_stock' => $this->l('Products using advanced stock management cannot be transformed.'),
            'no_frequency' => $this->l('Please choose at least one frequency.'),
            'unknown_frequency' => $this->l('Unknown frequency.'),
            'default_frequency_missing' => $this->l('The default frequency must be included.'),
            'frequency_out_of_bounds' => $this->l('A chosen frequency is not accepted by Ciklik (every 1 to 28 days, weeks or months).'),
            'too_many_combinations' => $this->l('This would exceed 1000 combinations for this product.'),
            'locked' => $this->l('A transformation of this product is already running.'),
            'unknown_product' => $this->l('Unknown product.'),
        ];

        return isset($messages[$code]) ? $messages[$code] : $this->l('Invalid request.');
    }

    /**
     * Réponse JSON puis fin de la requête
     *
     * @param array $data
     * @param int $status Code HTTP
     */
    private function respond(array $data, $status = 200)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data);
        exit;
    }
}
