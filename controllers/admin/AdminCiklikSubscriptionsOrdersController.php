<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */





use PrestaShop\Module\Ciklik\Api\Order as ApiOrder;
use PrestaShop\Module\Ciklik\Api\Subscription as ApiSubscription;
use PrestaShop\Module\Ciklik\Managers\CiklikCustomer;


if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminCiklikSubscriptionsOrdersController extends ModuleAdminController
{
    /**
     * Onglet actuel affiché ('subscriptions' ou 'orders')
     * 
     * @var string
     */
    protected $currentTab = 'subscriptions';
    
    /**
     * Instance du module Ciklik
     * 
     * @var Module
     */
    public $module;

    /**
     * Constructeur du contrôleur
     */
    public function __construct()
    {
        $this->bootstrap = true;
        $this->module = Module::getInstanceByName('ciklik');

        parent::__construct();

        // Définir le titre de la page après le constructeur parent (le traducteur est initialisé)
        $this->page_header_toolbar_title = $this->l('Abonnements et Commandes');

        // Vérifier si le token API est configuré
        if (!Configuration::get(Ciklik::CONFIG_API_TOKEN)) {
            $this->warnings[] = $this->l('Le token API Ciklik n\'est pas configuré. Veuillez le configurer dans les paramètres du module.');
        }

        // Déterminer l'onglet actuel depuis la requête
        $this->currentTab = Tools::getValue('tab', 'subscriptions');
        if (!in_array($this->currentTab, ['subscriptions', 'orders'])) {
            $this->currentTab = 'subscriptions';
        }
    }

    /**
     * Initialise le contenu de la page
     * 
     * @return void
     */
    public function initContent()
    {
        parent::initContent();

        $subscriptionsContent = $this->renderSubscriptionsList();
        $ordersContent = $this->renderOrdersList();

        $this->context->smarty->assign([
            'current_tab' => $this->currentTab,
            'subscriptions_content' => $subscriptionsContent,
            'orders_content' => $ordersContent,
            'link' => $this->context->link,
            'token' => $this->token,
        ]);

        // Pour ModuleAdminController, PrestaShop cherche automatiquement dans le répertoire views/templates/admin/ du module
        // Le chemin est relatif à views/templates/admin/
        $this->setTemplate('subscriptions_orders/index.tpl');
    }

    protected function renderSubscriptionsList()
    {
        $subscriptions = [];
        $pagination = $this->getDefaultPagination();
        $filters = $this->getSubscriptionFilters();
        $errors = [];

        try {
            $apiClient = new ApiSubscription($this->context->link);
            $options = $this->buildApiOptions($filters, 'subscriptions');
            $options['query']['sort'] = '-created_at';
            $response = $apiClient->index($options);
            
            // La réponse doit être au format standard : ['status' => true, 'body' => [...], 'meta' => [...]]
            if ($response && isset($response['status'])) {
                if ($response['status']) {
                    // body contient des objets SubscriptionData, les convertir en tableaux pour le template
                    $subscriptions = [];
                    if (isset($response['body']) && is_array($response['body'])) {
                        foreach ($response['body'] as $subscription) {
                            // Convertir l'objet SubscriptionData en tableau
                            $subscriptionData = [
                                'uuid' => $subscription->uuid ?? '',
                                'active' => $subscription->active ?? false,
                                'display_content' => $subscription->display_content ?? '',
                                'display_interval' => $subscription->display_interval ?? '',
                                'next_billing' => $subscription->next_billing ? $subscription->next_billing->format('Y-m-d') : '',
                                'created_at' => $subscription->created_at ? $subscription->created_at->format('Y-m-d H:i:s') : '',
                                'end_date' => $subscription->end_date ? $subscription->end_date->format('Y-m-d') : '',
                            ];
                            
                            // Extraire l'ID client depuis external_fingerprint
                            if (isset($subscription->external_fingerprint) && 
                                is_object($subscription->external_fingerprint) && 
                                isset($subscription->external_fingerprint->id_customer) && 
                                $subscription->external_fingerprint->id_customer > 0) {
                                
                                $customer = new \Customer((int)$subscription->external_fingerprint->id_customer);
                                if (\Validate::isLoadedObject($customer)) {
                                    $subscriptionData['customer_email'] = $customer->email;
                                    $subscriptionData['customer_id'] = $customer->id;
                                    $subscriptionData['customer_link'] = $this->context->link->getAdminLink(
                                        'AdminCustomers',
                                        true,
                                        [],
                                        [
                                            'id_customer' => (int)$customer->id,
                                            'viewcustomer' => 1,
                                        ]
                                    );
                                }
                            }
                            
                            $subscriptions[] = $subscriptionData;
                        }
                    }
                    $pagination = $this->extractPagination($response);
                } else {
                    $errors[] = $this->l('Erreur lors de la récupération des abonnements: ') . 
                        $this->formatApiErrors($response);
                }
            } else {
                $errors[] = $this->l('Format de réponse API inattendu lors de la récupération des abonnements.');
            }
        } catch (\Exception $e) {
            $errors[] = $this->l('Erreur API: ') . $e->getMessage();
        }

        $this->context->smarty->assign([
            'subscriptions' => $subscriptions,
            'filters' => $filters,
            'pagination' => $pagination,
            'pagination_links' => $this->buildPaginationLinks($filters, $pagination, 'subscriptions'),
            'errors' => $errors,
            'link' => $this->context->link,
            'token' => $this->token,
        ]);

        return $this->context->smarty->fetch(
            _PS_MODULE_DIR_ . 'ciklik/views/templates/admin/subscriptions_orders/subscriptions.tpl'
        );
    }

    /**
     * Affiche la liste des commandes
     * 
     * @return string HTML de la liste des commandes
     */
    protected function renderOrdersList()
    {
        $orders = [];
        $pagination = $this->getDefaultPagination();
        $filters = $this->getOrderFilters();
        $errors = [];

        try {
            $apiClient = new ApiOrder($this->context->link);
            $options = $this->buildApiOptions($filters, 'orders');
            // Ajouter le tri par updated_at descendant pour toujours avoir les dernières mises à jour en haut
           
            $options['query']['sort'] = '-updated_at';

        
            $response = $apiClient->index($options);

            if ($response && isset($response['status']) && $response['status']) {
                // body contient des objets OrderData, les convertir en tableaux pour le template
                $orders = [];
                if (isset($response['body']) && is_array($response['body'])) {
                    foreach ($response['body'] as $order) {
                        // Convertir l'objet OrderData en tableau
                        $orders[] = [
                            'order_id' => $order->ciklik_order_id ?? '',
                            'status' => $order->status ?? '',
                            'total_paid' => $order->total_paid ?? '',
                            'created_at' => $order->created_at ? $order->created_at->format('Y-m-d H:i:s') : '',
                            'subscription_uuid' => $order->subscription_uuid ?? '',
                            'user_uuid' => $order->ciklik_user_uuid ?? '',
                            'prestashop_order_id' => $order->prestashop_order_id ?? null,
                        ];
                    }
                }
                $pagination = $this->extractPagination($response);
            } else {
                $errors[] = $this->l('Erreur lors de la récupération des commandes: ') . 
                    $this->formatApiErrors($response);
            }
        } catch (\Exception $e) {
            $errors[] = $this->l('Erreur API: ') . $e->getMessage();
        }

        // Construire les liens vers les commandes PrestaShop et récupérer les informations clients
        $ordersWithLinks = [];
        foreach ($orders as $order) {
            $orderWithLink = $order;
            
            // Lien vers la commande PrestaShop
            if (isset($order['prestashop_order_id']) && !empty($order['prestashop_order_id'])) {
                $orderWithLink['prestashop_order_link'] = $this->context->link->getAdminLink(
                    'AdminOrders',
                    true,
                    [],
                    [
                        'vieworder' => 1,
                        'id_order' => (int)$order['prestashop_order_id'],
                    ]
                );
            }
            
            // Récupérer les informations du client PrestaShop à partir de l'UUID Ciklik
            if (isset($order['user_uuid']) && !empty($order['user_uuid'])) {
                $ciklikCustomer = CiklikCustomer::getByCiklikUuid($order['user_uuid']);
                if ($ciklikCustomer && isset($ciklikCustomer['id_customer']) && $ciklikCustomer['id_customer'] > 0) {
                    $customer = new Customer((int)$ciklikCustomer['id_customer']);
                    if (Validate::isLoadedObject($customer)) {
                        $orderWithLink['customer_email'] = $customer->email;
                        $orderWithLink['customer_id'] = $customer->id;
                        $orderWithLink['customer_link'] = $this->context->link->getAdminLink(
                            'AdminCustomers',
                            true,
                            [],
                            [
                                'id_customer' => (int)$customer->id,
                                'viewcustomer' => 1,
                            ]
                        );
                    }
                }
            }
            
            $ordersWithLinks[] = $orderWithLink;
        }

        $this->context->smarty->assign([
            'orders' => $ordersWithLinks,
            'filters' => $filters,
            'pagination' => $pagination,
            'pagination_links' => $this->buildPaginationLinks($filters, $pagination, 'orders'),
            'errors' => $errors,
            'link' => $this->context->link,
            'token' => $this->token,
        ]);

        return $this->context->smarty->fetch(
            _PS_MODULE_DIR_ . 'ciklik/views/templates/admin/subscriptions_orders/orders.tpl'
        );
    }

    /**
     * Récupère les filtres pour les abonnements depuis la requête
     * 
     * @return array Tableau des filtres
     */
    protected function getSubscriptionFilters()
    {
        // Récupérer et nettoyer les filtres pour éviter les injections
        return [
            'filter_activated' => Tools::getValue('filter_activated', ''),
            'filter_canceled' => Tools::getValue('filter_canceled', ''),
            'filter_expired' => Tools::getValue('filter_expired', ''),
            'filter_subscriptions_by_email' => Tools::substr(trim((string)Tools::getValue('filter_subscriptions_by_email', '')), 0, 255),
            'filter_customer_id' => (int)Tools::getValue('filter_customer_id', 0),
            'filter_created_at_before' => Tools::substr(trim((string)Tools::getValue('filter_created_at_before', '')), 0, 50),
            'filter_created_at_after' => Tools::substr(trim((string)Tools::getValue('filter_created_at_after', '')), 0, 50),
            'filter_canceled_at_before' => Tools::substr(trim((string)Tools::getValue('filter_canceled_at_before', '')), 0, 50),
            'filter_canceled_at_after' => Tools::substr(trim((string)Tools::getValue('filter_canceled_at_after', '')), 0, 50),
            'subscriptions_page' => max(1, (int)Tools::getValue('subscriptions_page', 1)),
            'subscriptions_per_page' => max(1, min(100, (int)Tools::getValue('subscriptions_per_page', 20))),
        ];
    }

    /**
     * Récupère les filtres pour les commandes depuis la requête
     * 
     * @return array Tableau des filtres
     */
    protected function getOrderFilters()
    {
        // Récupérer et nettoyer les filtres pour éviter les injections
        return [
            'filter_status' => Tools::substr(trim((string)Tools::getValue('filter_status', '')), 0, 50),
            'filter_subscription_uuid' => Tools::substr(trim((string)Tools::getValue('filter_subscription_uuid', '')), 0, 255),
            'filter_user_id' => Tools::substr(trim((string)Tools::getValue('filter_user_id', '')), 0, 255),
            'filter_total_paid' => Tools::substr(trim((string)Tools::getValue('filter_total_paid', '')), 0, 50),
            'filter_source' => Tools::substr(trim((string)Tools::getValue('filter_source', '')), 0, 50),
            'filter_by_customer_uuid' => Tools::substr(trim((string)Tools::getValue('filter_by_customer_uuid', '')), 0, 255),
            'filter_prestashop_order_id' => (int)Tools::getValue('filter_prestashop_order_id', 0),
            'filter_created_at_before' => Tools::substr(trim((string)Tools::getValue('filter_created_at_before', '')), 0, 50),
            'filter_created_at_after' => Tools::substr(trim((string)Tools::getValue('filter_created_at_after', '')), 0, 50),
            'filter_updated_at_before' => Tools::substr(trim((string)Tools::getValue('filter_updated_at_before', '')), 0, 50),
            'filter_updated_at_after' => Tools::substr(trim((string)Tools::getValue('filter_updated_at_after', '')), 0, 50),
            'orders_page' => max(1, (int)Tools::getValue('orders_page', 1)),
            'orders_per_page' => max(1, min(100, (int)Tools::getValue('orders_per_page', 20))),
        ];
    }

    /**
     * Construit les options API à partir des filtres
     * 
     * @param array $filters Tableau des filtres
     * @param string $type Type de données ('subscriptions' ou 'orders')
     * @return array Options formatées pour l'API
     */
    protected function buildApiOptions($filters, $type)
    {
        $query = [];
        $filterArray = [];

        // Construire le tableau de filtres
        foreach ($filters as $key => $value) {
            // Gérer les clés de pagination
            if ($key === 'subscriptions_page' || $key === 'subscriptions_per_page' || 
                $key === 'orders_page' || $key === 'orders_per_page') {
                if ($key === 'subscriptions_page' || $key === 'orders_page') {
                    $query['page'] = $value;
                } elseif ($key === 'subscriptions_per_page' || $key === 'orders_per_page') {
                    $query['per_page'] = $value;
                }
                continue;
            }

            if (!empty($value)) {
                // Supprimer le préfixe 'filter_' pour l'API
                $apiKey = str_replace('filter_', '', $key);
                
                // Valider et échapper la valeur avant de l'envoyer à l'API
                $sanitizedValue = is_string($value) ? Tools::substr(trim($value), 0, 255) : $value;
                
                // Gérer les filtres de date
                if (strpos($apiKey, '_before') !== false || strpos($apiKey, '_after') !== false) {
                    $filterKey = str_replace(['_before', '_after'], '', $apiKey);
                    if (strpos($apiKey, '_before') !== false) {
                        $filterArray[$filterKey . '[before]'] = $sanitizedValue;
                    } else {
                        $filterArray[$filterKey . '[after]'] = $sanitizedValue;
                    }
                } else {
                    $filterArray[$apiKey] = $sanitizedValue;
                }
            }
        }

        if (!empty($filterArray)) {
            $query['filter'] = $filterArray;
        }

        return ['query' => $query];
    }

    protected function getDefaultPagination()
    {
        return [
            'current_page' => 1,
            'per_page' => 20,
            'total' => 0,
            'last_page' => 1,
            'from' => 0,
            'to' => 0,
            'path' => '',
            'links' => [],
            'total_pages' => 1,
        ];
    }

    /**
     * Extrait les informations de pagination de la réponse API
     * 
     * @param array $response Réponse de l'API
     * @return array Tableau avec les informations de pagination
     */
    protected function extractPagination($response)
    {
        // Commencer avec les valeurs de pagination par défaut
        $pagination = $this->getDefaultPagination();

        // Extract pagination from API response meta object
        // Meta is at the root level of the response (from CiklikApiResponseHandler)
        $meta = null;
        if (isset($response['meta']) && is_array($response['meta'])) {
            $meta = $response['meta'];
        }

        if ($meta) {
            if (isset($meta['current_page'])) {
                $pagination['current_page'] = (int)$meta['current_page'];
            }
            if (isset($meta['per_page'])) {
                $pagination['per_page'] = (int)$meta['per_page'];
            }
            if (isset($meta['total'])) {
                $pagination['total'] = (int)$meta['total'];
            }
            if (isset($meta['last_page'])) {
                $pagination['last_page'] = (int)$meta['last_page'];
            }
            if (isset($meta['from'])) {
                $pagination['from'] = (int)$meta['from'];
            }
            if (isset($meta['to'])) {
                $pagination['to'] = (int)$meta['to'];
            }
            if (isset($meta['path'])) {
                $pagination['path'] = $meta['path'];
            }
            if (isset($meta['links']) && is_array($meta['links'])) {
                $pagination['links'] = $meta['links'];
            }
        }

        // Toujours calculer total_pages en fonction des données disponibles
        if (isset($pagination['last_page']) && $pagination['last_page'] > 0) {
            $pagination['total_pages'] = $pagination['last_page'];
        } elseif ($pagination['per_page'] > 0 && $pagination['total'] > 0) {
            $pagination['total_pages'] = (int)ceil($pagination['total'] / $pagination['per_page']);
            if (!isset($pagination['last_page']) || $pagination['last_page'] < 1) {
                $pagination['last_page'] = $pagination['total_pages'];
            }
        } else {
            // S'assurer que les valeurs par défaut sont définies
            $pagination['total_pages'] = 1;
            if (!isset($pagination['last_page']) || $pagination['last_page'] < 1) {
                $pagination['last_page'] = 1;
            }
        }

        return $pagination;
    }

    /**
     * Formate les erreurs de l'API en chaîne de caractères
     * Gère différents formats d'erreurs (tableau simple, tableau associatif, etc.)
     * 
     * @param array $response Réponse de l'API
     * @return string Message d'erreur formaté
     */
    protected function formatApiErrors($response)
    {
        if (isset($response['errors']) && is_array($response['errors']) && !empty($response['errors'])) {
            $errorMessages = [];
            
            foreach ($response['errors'] as $key => $error) {
                if (is_array($error)) {
                    // Si c'est un tableau associatif (ex: {"sort": ["message1", "message2"]})
                    if (is_numeric($key)) {
                        // Tableau indexé numériquement
                        foreach ($error as $subError) {
                            if (is_string($subError)) {
                                $errorMessages[] = $subError;
                            } elseif (is_array($subError) && isset($subError['message'])) {
                                $errorMessages[] = $subError['message'];
                            }
                        }
                    } else {
                        // Clé nommée, peut contenir un tableau de messages
                        if (is_array($error)) {
                            foreach ($error as $msg) {
                                if (is_string($msg)) {
                                    $errorMessages[] = $key . ': ' . $msg;
                                }
                            }
                        } elseif (is_string($error)) {
                            $errorMessages[] = $key . ': ' . $error;
                        }
                    }
                } elseif (is_string($error)) {
                    // Erreur simple sous forme de chaîne
                    $errorMessages[] = $error;
                }
            }
            
            if (!empty($errorMessages)) {
                return implode(', ', $errorMessages);
            }
        }
        
        // Fallback sur le message si disponible
        if (isset($response['message']) && !empty($response['message'])) {
            return $response['message'];
        }
        
        return $this->l('Erreur inconnue');
    }

    /**
     * Construit les liens de pagination en préservant les filtres actuels
     * 
     * @param array $filters Filtres actuels
     * @param array $pagination Informations de pagination
     * @param string $tab Onglet actif ('subscriptions' ou 'orders')
     * @return array Tableau avec les liens de pagination (prev, next, pages)
     */
    protected function buildPaginationLinks($filters, $pagination, $tab)
    {
        $currentPage = isset($pagination['current_page']) ? (int)$pagination['current_page'] : 1;
        $totalPages = isset($pagination['total_pages']) ? (int)$pagination['total_pages'] : 1;
        
        // Construire les paramètres de base pour les liens
        $baseParams = [
            'controller' => 'AdminCiklikSubscriptionsOrders',
            'token' => $this->token,
            'tab' => $tab,
        ];
        
        // Ajouter tous les filtres non vides avec validation et échappement
        foreach ($filters as $key => $value) {
            if (!empty($value) && $key !== 'subscriptions_page' && $key !== 'orders_page' && 
                $key !== 'subscriptions_per_page' && $key !== 'orders_per_page') {
                // Valider et échapper les valeurs pour éviter les injections dans les URLs
                if (is_string($value)) {
                    // Limiter la longueur et échapper les caractères spéciaux
                    $value = Tools::substr($value, 0, 255);
                    $baseParams[$key] = Tools::safeOutput($value);
                } elseif (is_numeric($value)) {
                    $baseParams[$key] = (int)$value;
                } else {
                    $baseParams[$key] = Tools::safeOutput((string)$value);
                }
            }
        }
        
        // Ajouter le paramètre per_page si défini
        if ($tab === 'subscriptions' && isset($filters['subscriptions_per_page'])) {
            $baseParams['subscriptions_per_page'] = $filters['subscriptions_per_page'];
        } elseif ($tab === 'orders' && isset($filters['orders_per_page'])) {
            $baseParams['orders_per_page'] = $filters['orders_per_page'];
        }
        
        $links = [
            'prev' => null,
            'next' => null,
            'first' => null,
            'last' => null,
            'pages' => [],
        ];
        
        // Lien précédent
        if ($currentPage > 1) {
            $prevParams = $baseParams;
            if ($tab === 'subscriptions') {
                $prevParams['subscriptions_page'] = $currentPage - 1;
            } else {
                $prevParams['orders_page'] = $currentPage - 1;
            }
            $links['prev'] = $this->context->link->getAdminLink('AdminCiklikSubscriptionsOrders', true, [], $prevParams);
        }
        
        // Lien suivant
        if ($currentPage < $totalPages) {
            $nextParams = $baseParams;
            if ($tab === 'subscriptions') {
                $nextParams['subscriptions_page'] = $currentPage + 1;
            } else {
                $nextParams['orders_page'] = $currentPage + 1;
            }
            $links['next'] = $this->context->link->getAdminLink('AdminCiklikSubscriptionsOrders', true, [], $nextParams);
        }
        
        // Lien première page
        if ($currentPage > 1) {
            $firstParams = $baseParams;
            if ($tab === 'subscriptions') {
                $firstParams['subscriptions_page'] = 1;
            } else {
                $firstParams['orders_page'] = 1;
            }
            $links['first'] = $this->context->link->getAdminLink('AdminCiklikSubscriptionsOrders', true, [], $firstParams);
        }
        
        // Lien dernière page
        if ($currentPage < $totalPages) {
            $lastParams = $baseParams;
            if ($tab === 'subscriptions') {
                $lastParams['subscriptions_page'] = $totalPages;
            } else {
                $lastParams['orders_page'] = $totalPages;
            }
            $links['last'] = $this->context->link->getAdminLink('AdminCiklikSubscriptionsOrders', true, [], $lastParams);
        }
        
        // Générer les liens pour les pages (maximum 5 pages autour de la page actuelle)
        $startPage = max(1, $currentPage - 2);
        $endPage = min($totalPages, $currentPage + 2);
        
        for ($page = $startPage; $page <= $endPage; $page++) {
            $pageParams = $baseParams;
            if ($tab === 'subscriptions') {
                $pageParams['subscriptions_page'] = $page;
            } else {
                $pageParams['orders_page'] = $page;
            }
            $links['pages'][$page] = [
                'number' => $page,
                'url' => $this->context->link->getAdminLink('AdminCiklikSubscriptionsOrders', true, [], $pageParams),
                'current' => ($page === $currentPage),
            ];
        }
        
        return $links;
    }
}

