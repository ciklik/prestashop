<?php

/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

use PrestaShop\Module\Ciklik\Api\Subscription as ApiSubscription;
use PrestaShop\Module\Ciklik\Helpers\StockForecastAggregator;

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminCiklikStockForecastController extends ModuleAdminController
{
    /**
     * Nombre maximal de pages API à parcourir
     */
    public const MAX_API_PAGES = 100;

    /**
     * Seuil de stock bas (pour affichage "Low")
     */
    public const LOW_STOCK_THRESHOLD = 5;

    /**
     * Instance du module Ciklik
     *
     * @var Module
     */
    public $module;

    /**
     * Méthode de traduction pour compatibilité PS9
     *
     * @param string $string Chaîne à traduire
     * @param string|null $class Nom de la classe
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

            return $this->module->l($string, 'AdminCiklikStockForecastController', $addslashes, $htmlentities);
        } catch (Exception $e) {
            return $string;
        }
    }

    public function __construct()
    {
        $this->bootstrap = true;
        $this->module = Module::getInstanceByName('ciklik');

        parent::__construct();
    }

    public function init()
    {
        parent::init();

        $this->page_header_toolbar_title = $this->l('Stock Forecast');

        if (!Configuration::get(Ciklik::CONFIG_API_TOKEN)) {
            $this->warnings[] = $this->l('The Ciklik API token is not configured. Please configure it in the module settings.');
        }
    }

    /**
     * Initialise le contenu de la page
     */
    public function initContent()
    {
        parent::initContent();

        // Dates par défaut : aujourd'hui → +30 jours
        $dateFrom = Tools::getValue('date_from', date('Y-m-d'));
        $dateTo = Tools::getValue('date_to', date('Y-m-d', strtotime('+30 days')));

        // Validation des dates
        if (!Validate::isDate($dateFrom)) {
            $dateFrom = date('Y-m-d');
        }
        if (!Validate::isDate($dateTo)) {
            $dateTo = date('Y-m-d', strtotime('+30 days'));
        }
        if ($dateFrom > $dateTo) {
            $tmp = $dateFrom;
            $dateFrom = $dateTo;
            $dateTo = $tmp;
        }

        $forecast = [];
        $errors = [];
        $stats = [
            'total_subscriptions' => 0,
            'filtered_subscriptions' => 0,
            'total_products' => 0,
            'alerts' => 0,
        ];

        if (Configuration::get(Ciklik::CONFIG_API_TOKEN)) {
            try {
                // Récupérer tous les abonnements actifs depuis l'API
                $allSubscriptions = $this->fetchAllActiveSubscriptions();
                $stats['total_subscriptions'] = count($allSubscriptions);

                // Filtrer par plage de dates
                $filtered = StockForecastAggregator::filterByDateRange($allSubscriptions, $dateFrom, $dateTo);
                $stats['filtered_subscriptions'] = count($filtered);

                // Agréger les quantités par produit
                $isFrequencyMode = (bool) Configuration::get(Ciklik::CONFIG_USE_FREQUENCY_MODE);
                $needs = StockForecastAggregator::aggregateFromSubscriptions($filtered, $isFrequencyMode);

                // Enrichir avec les données de stock PrestaShop
                $forecast = StockForecastAggregator::enrichWithStockData($needs);

                // Trier : alertes en premier, puis par stock_after croissant
                uasort($forecast, function ($a, $b) {
                    if ($a['alert'] !== $b['alert']) {
                        return $b['alert'] - $a['alert'];
                    }

                    return $a['stock_after'] - $b['stock_after'];
                });

                $stats['total_products'] = count($forecast);
                $stats['alerts'] = count(array_filter($forecast, function ($item) {
                    return $item['alert'];
                }));
            } catch (Exception $e) {
                $errors[] = $this->l('API Error: ') . Tools::htmlentitiesUTF8($e->getMessage());
            }
        }

        $this->context->smarty->assign([
            'forecast' => $forecast,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'stats' => $stats,
            'errors' => $errors,
            'moduleLogoSrc' => $this->module->getPathUri() . 'logo.png',
            'low_stock_threshold' => self::LOW_STOCK_THRESHOLD,
            'link' => $this->context->link,
            'token' => $this->token,
        ]);

        $this->setTemplate('stock_forecast/index.tpl');
    }

    /**
     * Récupère tous les abonnements actifs via l'API (toutes les pages)
     *
     * @return array Tableau de données brutes d'abonnements
     */
    private function fetchAllActiveSubscriptions()
    {
        $apiClient = new ApiSubscription($this->context->link);
        $allSubscriptions = [];
        $page = 1;

        do {
            $response = $apiClient->indexRaw([
                'query' => [
                    'filter' => ['activated' => 1],
                    'page' => $page,
                ],
            ]);

            if (!$response || !isset($response['status']) || !$response['status']) {
                break;
            }

            if (isset($response['body']) && is_array($response['body'])) {
                foreach ($response['body'] as $subscription) {
                    $allSubscriptions[] = $subscription;
                }
            }

            // Vérifier s'il y a plus de pages
            $lastPage = isset($response['meta']['last_page']) ? (int) $response['meta']['last_page'] : 1;
            ++$page;
        } while ($page <= $lastPage && $page <= self::MAX_API_PAGES);

        return $allSubscriptions;
    }
}
