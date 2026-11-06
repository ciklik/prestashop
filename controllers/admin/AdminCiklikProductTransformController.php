<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

use PrestaShop\Module\Ciklik\Managers\CiklikSubscriptionTransformer;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Actions AJAX du panneau « Transformer en abonnement » de la fiche produit (mode déclinaisons).
 * Onglet caché ; POST seulement, jeton admin, droit de modification des produits.
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
     * Droits de l'onglet Produits plutôt que ceux de cet onglet caché, que PrestaShop n'accorde qu'au
     * super-administrateur : PrestaShop 9 vérifie la lecture de l'onglet avant postProcess()
     *
     * @return string
     */
    public function getTabSlug()
    {
        return 'ROLE_MOD_TAB_ADMINPRODUCTS_';
    }

    /**
     * Point d'entrée unique : les actions ne passent que par AJAX
     */
    public function postProcess()
    {
        if (!$this->ajax) {
            $this->respond(['error' => $this->l('Invalid request.')], 400);
        }

        // Jeton vérifié par le contrôleur parent avant postProcess ; le droit exigé est celui de
        // modifier les produits, que les profils non super-administrateurs reçoivent déjà
        if (!isset($_SERVER['REQUEST_METHOD']) || 'POST' !== $_SERVER['REQUEST_METHOD']
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
            $this->respond(['error' => $this->refusalMessage($e->getMessage()), 'code' => preg_replace('/[^a-z_]/', '', $e->getMessage())], 400);
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('Ciklik - action ' . preg_replace('/[^a-z_]/', '', $action) . ' sur le produit ' . $idProduct . ' échouée : ' . $e->getMessage(), 3);
            $messages = [
                'step' => $this->l('This step failed and was undone; the previous steps are kept.'),
                'cancel' => $this->l('The cancellation stopped before the end: cancel the transformation again.'),
                'realign_stock' => $this->l('The realignment stopped before the end: realign the stock again.'),
                'sync' => $this->l('Ciklik could not be reached: try again later.'),
            ];
            $this->respond(['error' => isset($messages[$action]) ? $messages[$action] : $this->l('The operation failed and was cancelled. Nothing was changed.')], 500);
        }
    }

    /**
     * @param string $action preview, start, step, cancel, realign_preview, realign_stock ou sync
     * @param int $idProduct
     *
     * @return array Données de la réponse
     *
     * @throws DomainException Refus motivé
     */
    private function runAction($action, $idProduct)
    {
        $frequencies = array_map('intval', (array) Tools::getValue('frequencies', []));
        CiklikSubscriptionTransformer::requireSingleShop();

        switch ($action) {
            case 'preview':
                return ['preview' => CiklikSubscriptionTransformer::preview($idProduct, $frequencies)];
            case 'start':
                return ['progress' => CiklikSubscriptionTransformer::start($idProduct, $frequencies)];
            case 'step':
                $progress = CiklikSubscriptionTransformer::step($idProduct);

                return ['progress' => $progress] + ($progress['finished'] ? ['message' => $this->transformMessage($progress)] : []);
            case 'cancel':
                $cancel = CiklikSubscriptionTransformer::cancel($idProduct);

                return ['cancel' => $cancel] + ($cancel['finished'] ? ['message' => $this->l('The transformation was cancelled: the created combinations were removed.')] : []);
            case 'realign_preview':
                return ['realign' => CiklikSubscriptionTransformer::realignPreview($idProduct, (int) $this->context->language->id)];
            case 'sync':
                $sync = CiklikSubscriptionTransformer::resync($idProduct);
                if (null === $sync) {
                    throw new DomainException('no_subscription');
                }
                if ($sync['failed'] > 0) {
                    $this->respond(['error' => sprintf($this->l('Ciklik refused %d combinations: check the module logs, then try again.'), $sync['failed'])], 502);
                }

                return ['message' => sprintf($this->l('%d combinations sent to Ciklik.'), $sync['sent'])];
            case 'realign_stock':
                return ['message' => sprintf($this->l('Stock realigned on %d subscription combinations.'), CiklikSubscriptionTransformer::realignStock($idProduct))];
        }

        throw new DomainException('invalid_request');
    }

    /**
     * Message de fin de transformation, avec le résultat de l'envoi à Ciklik
     *
     * @param array $result Dernière étape de CiklikSubscriptionTransformer::step()
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
     * @param string $code Constante de SubscriptionTransformPlan, locked, unknown_product, no_subscription ou invalid_request
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
            'job_running' => $this->l('A transformation of this product is in progress: resume or cancel it first.'),
            'no_job' => $this->l('No transformation in progress for this product.'),
            'unknown_product' => $this->l('Unknown product.'),
            'shop_context' => $this->l('Select a single shop (not all shops or a group) before using these actions.'),
            'other_shop' => $this->l('This transformation was started in another shop: select that shop to resume or cancel it.'),
            'no_subscription' => $this->l('This product has no subscription combinations to send.'),
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
