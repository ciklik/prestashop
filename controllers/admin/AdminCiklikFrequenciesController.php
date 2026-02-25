<?php

/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

use PrestaShop\Module\Ciklik\Managers\CiklikFrequency as CiklikFrequencyManager;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Contrôleur d'administration pour la gestion des fréquences Ciklik
 */
class AdminCiklikFrequenciesController extends ModuleAdminController
{
    /**
     * Méthode de traduction pour compatibilité PS9
     * En PS9, ModuleAdminController n'a plus la méthode l() directement
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
        // Utiliser try-catch pour gérer les cas où le contexte n'est pas prêt
        // (lors de l'initialisation précoce du contrôleur avant que la langue soit chargée)
        try {
            if (!$this->module) {
                return $string;
            }

            return $this->module->l($string, 'AdminCiklikFrequenciesController', $addslashes, $htmlentities);
        } catch (Exception $e) {
            return $string;
        }
    }

    /**
     * Constructeur du contrôleur
     */
    public function __construct()
    {
        $this->bootstrap = true;
        $this->table = 'ciklik_frequency';
        $this->className = '';
        $this->identifier = 'id_frequency';
        $this->lang = false;
        $this->list_no_link = false;
        $this->simple_header = false;
        $this->bulk_actions = [];

        parent::__construct();
    }

    /**
     * Initialise le contrôleur
     * Les champs de formulaire sont définis ici car le contexte langue
     * est correctement initialisé après parent::init()
     */
    public function init()
    {
        parent::init();

        $this->page_header_toolbar_title = $this->l('Gestion des Fréquences');

        // Vérifier si le mode fréquence est activé
        if (!Configuration::get(Ciklik::CONFIG_USE_FREQUENCY_MODE)) {
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminConfigureCiklik'));
        }

        // Définir le mode d'affichage (PrestaShop ne le fait pas automatiquement car className est vide)
        if (Tools::getValue('add') !== false) {
            $this->display = 'add';
        } elseif (Tools::getValue('update' . $this->table) !== false || Tools::getValue('updateciklik_frequency') !== false) {
            $this->display = 'edit';
        }

        // Nettoyer les paramètres de filtre de l'URL pour éviter leur affichage dans le breadcrumb
        $this->cleanFilterParams();

        // Définition des colonnes de la liste
        $this->fields_list = [
            'id_frequency' => [
                'title' => $this->l('ID'),
                'width' => 50,
                'type' => 'text',
                'align' => 'center',
                'search' => false,
                'filter_key' => 'id_frequency',
            ],
            'name' => [
                'title' => $this->l('Nom'),
                'width' => 200,
                'type' => 'text',
                'search' => false,
                'filter_key' => 'name',
            ],
            'interval_count' => [
                'title' => $this->l('Nombre d\'intervalles'),
                'width' => 100,
                'type' => 'int',
                'align' => 'center',
                'search' => false,
                'filter_key' => 'interval_count',
            ],
            'interval' => [
                'title' => $this->l('Intervalle'),
                'width' => 100,
                'type' => 'select',
                'list' => [
                    'day' => $this->l('Journalier'),
                    'week' => $this->l('Hebdomadaire'),
                    'month' => $this->l('Mensuel'),
                ],
                'search' => false,
                'filter_key' => 'interval',
            ],
            'discount_percent' => [
                'title' => $this->l('Remise (%)'),
                'width' => 100,
                'type' => 'percent',
                'align' => 'right',
                'search' => false,
                'filter_key' => 'discount_percent',
            ],
            'discount_price' => [
                'title' => $this->l('Remise (montant)'),
                'width' => 100,
                'type' => 'price',
                'align' => 'right',
                'search' => false,
                'filter_key' => 'discount_price',
            ],
        ];

        // Définition des champs du formulaire
        $this->fields_form = [
            'legend' => [
                'title' => $this->l('Fréquence'),
                'icon' => 'icon-cogs',
            ],
            'input' => [
                [
                    'type' => 'text',
                    'label' => $this->l('Nom'),
                    'name' => 'name',
                    'required' => true,
                    'maxlength' => 255,
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Nombre d\'intervalles'),
                    'name' => 'interval_count',
                    'required' => true,
                    'class' => 'fixed-width-sm',
                ],
                [
                    'type' => 'select',
                    'label' => $this->l('Intervalle'),
                    'name' => 'interval',
                    'required' => true,
                    'options' => [
                        'query' => [
                            ['id' => 'day', 'name' => $this->l('Journalier')],
                            ['id' => 'week', 'name' => $this->l('Hebdomadaire')],
                            ['id' => 'month', 'name' => $this->l('Mensuel')],
                        ],
                        'id' => 'id',
                        'name' => 'name',
                    ],
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Remise (%)'),
                    'name' => 'discount_percent',
                    'class' => 'fixed-width-sm',
                    'suffix' => '%',
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Remise (montant)'),
                    'name' => 'discount_price',
                    'class' => 'fixed-width-sm',
                    'suffix' => $this->context->currency->sign,
                ],
            ],
            'submit' => [
                'title' => $this->l('Enregistrer'),
            ],
        ];
    }

    /**
     * Initialise la toolbar de la page
     * Le bouton "Ajouter" est défini ici (après parent) pour ne pas être écrasé
     * par le parent::initPageHeaderToolbar() qui peut réinitialiser les boutons
     */
    public function initPageHeaderToolbar()
    {
        parent::initPageHeaderToolbar();

        if ($this->display !== 'add' && $this->display !== 'edit') {
            $this->page_header_toolbar_btn['new'] = [
                'href' => self::$currentIndex . '&add' . $this->table . '&token=' . $this->token,
                'desc' => $this->l('Add a frequency'),
            ];
        }
    }

    /**
     * Affiche le formulaire d'édition/création
     *
     * @return string HTML du formulaire
     */
    public function renderForm()
    {
        $this->loadObject(true);

        return parent::renderForm();
    }

    /**
     * Traite l'ajout d'une nouvelle fréquence
     *
     * @return bool True si l'ajout a réussi, false sinon
     */
    public function processAdd()
    {
        if (!$this->validateFormData()) {
            return false;
        }

        $frequencyData = $this->prepareFrequencyData();

        try {
            $id = CiklikFrequencyManager::saveFrequency($frequencyData);
            if ($id) {
                $this->confirmations[] = $this->l('Fréquence créée avec succès.');
                Tools::redirectAdmin($this->context->link->getAdminLink('AdminCiklikFrequencies'));
            } else {
                $this->errors[] = $this->l('Erreur lors de la création de la fréquence.');
            }
        } catch (Exception $e) {
            // Échappement XSS du message d'exception (source externe potentiellement non fiable)
            $this->errors[] = $this->l('Erreur: ') . Tools::htmlentitiesUTF8($e->getMessage());
        }

        return false;
    }

    /**
     * Traite la mise à jour d'une fréquence existante
     *
     * @return bool True si la mise à jour a réussi, false sinon
     */
    public function processUpdate()
    {
        $id_frequency = (int) Tools::getValue('id_frequency');
        if (!$id_frequency) {
            $this->errors[] = $this->l('ID de fréquence invalide.');

            return false;
        }

        if (!$this->validateFormData()) {
            return false;
        }

        $frequencyData = $this->prepareFrequencyData();
        $frequencyData['id_frequency'] = $id_frequency;

        try {
            $id = CiklikFrequencyManager::saveFrequency($frequencyData);
            if ($id) {
                $this->confirmations[] = $this->l('Fréquence modifiée avec succès.');
                Tools::redirectAdmin($this->context->link->getAdminLink('AdminCiklikFrequencies'));
            } else {
                $this->errors[] = $this->l('Erreur lors de la modification de la fréquence.');
            }
        } catch (Exception $e) {
            // Échappement XSS du message d'exception (source externe potentiellement non fiable)
            $this->errors[] = $this->l('Erreur: ') . Tools::htmlentitiesUTF8($e->getMessage());
        }

        return false;
    }

    /**
     * Désactive la suppression des fréquences
     *
     * @return bool Toujours false car la suppression est désactivée
     */
    public function processDelete()
    {
        $this->errors[] = $this->l('La suppression des fréquences n\'est pas autorisée. Vous pouvez uniquement les modifier.');
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminCiklikFrequencies'));
    }

    /**
     * Affiche la liste des fréquences
     *
     * @return string HTML de la liste
     */
    public function renderList()
    {
        $this->addRowAction('edit');

        $this->_select = '*';
        $this->_where = '1';
        $this->_orderBy = 'id_frequency';
        $this->_orderWay = 'ASC';

        return parent::renderList();
    }

    /**
     * Récupère la liste des fréquences depuis la base de données
     *
     * @param int $id_lang
     * @param string|null $order_by
     * @param string|null $order_way
     * @param int $start
     * @param int|null $limit
     * @param bool $id_lang_shop
     *
     * @return array Liste des fréquences
     */
    public function getList($id_lang, $order_by = null, $order_way = null, $start = 0, $limit = null, $id_lang_shop = false)
    {
        $sql = 'SELECT * FROM `' . _DB_PREFIX_ . $this->table . '` ORDER BY `id_frequency` ASC';

        $countSql = 'SELECT COUNT(*) as total FROM `' . _DB_PREFIX_ . $this->table . '`';
        $this->_listTotal = (int) Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue($countSql);

        if ($limit) {
            $sql .= ' LIMIT ' . (int) $start . ', ' . (int) $limit;
        }

        $this->_list = Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($sql);

        return $this->_list;
    }

    /**
     * Charge un objet depuis la base de données
     * Retourne toujours un objet (nouveau ou existant) pour que PrestaShop puisse afficher le formulaire
     *
     * @param bool $opt
     *
     * @return object Objet chargé ou nouvel objet vide
     */
    protected function loadObject($opt = false)
    {
        $id = (int) Tools::getValue('id_frequency');
        if ($id && Validate::isUnsignedInt($id)) {
            $frequency = CiklikFrequencyManager::getFrequencyById($id);
            if ($frequency) {
                $this->object = new stdClass();
                foreach ($frequency as $key => $value) {
                    $this->object->$key = $value;
                }
                if (isset($this->object->id_frequency)) {
                    $this->object->id = $this->object->id_frequency;
                }

                return $this->object;
            }
        }

        // En mode ajout, retourner un objet vide
        $this->object = $this->getEmptyObject();

        return $this->object;
    }

    /**
     * Retourne un objet vide pour le formulaire d'ajout
     *
     * @return stdClass Objet vide avec valeurs par défaut
     */
    protected function getEmptyObject()
    {
        $object = new stdClass();
        $object->id = null;
        $object->id_frequency = null;
        $object->name = '';
        $object->interval_count = 1;
        $object->interval = 'week';
        $object->discount_percent = null;
        $object->discount_price = null;

        return $object;
    }

    /**
     * Valide les données du formulaire
     *
     * @return bool True si les données sont valides, false sinon
     */
    protected function validateFormData()
    {
        // Récupérer et nettoyer les valeurs du formulaire
        $name = trim((string) Tools::getValue('name', ''));
        $interval_count = Tools::getValue('interval_count');
        $interval = trim((string) Tools::getValue('interval', ''));
        $discount_percent = Tools::getValue('discount_percent');
        $discount_price = Tools::getValue('discount_price');

        // Validation du nom
        if (empty($name) || !Validate::isString($name) || strlen($name) > 255) {
            $this->errors[] = $this->l('Le nom doit être une chaîne non vide de maximum 255 caractères.');
        }

        // Validation du nombre d'intervalles
        if (!Validate::isUnsignedInt($interval_count) || (int) $interval_count <= 0) {
            $this->errors[] = $this->l('Le nombre d\'intervalles doit être un entier positif.');
        }

        // Validation de l'intervalle
        $allowedIntervals = ['day', 'week', 'month'];
        if (!in_array($interval, $allowedIntervals, true)) {
            $this->errors[] = $this->l('L\'intervalle doit être l\'un des suivants: jour, semaine, mois.');
        }

        // Validation des champs de remise
        $discountValidation = $this->validateDiscountFields($discount_percent, $discount_price);
        foreach ($discountValidation['errors'] as $error) {
            $this->errors[] = $error;
        }

        return count($this->errors) === 0;
    }

    /**
     * Prépare les données de fréquence pour la sauvegarde
     *
     * @return array Données formatées pour la sauvegarde
     */
    protected function prepareFrequencyData()
    {
        $discountValidation = $this->validateDiscountFields(
            Tools::getValue('discount_percent'),
            Tools::getValue('discount_price'),
        );

        // Échapper et nettoyer les données avant sauvegarde
        $name = Tools::getValue('name');
        $interval = Tools::getValue('interval');

        return [
            'name' => Validate::isString($name) ? trim($name) : '',
            'interval_count' => (int) Tools::getValue('interval_count'),
            'interval' => Validate::isString($interval) ? $interval : 'week',
            'discount_percent' => $discountValidation['discount_percent'],
            'discount_price' => $discountValidation['discount_price'],
        ];
    }

    /**
     * Valide les champs de remise (pourcentage et montant)
     * Traite les valeurs vides, "0" et "0.00" comme null
     *
     * @param string $discount_percent Valeur du pourcentage de remise
     * @param string $discount_price Valeur du montant de remise
     *
     * @return array Tableau avec 'discount_percent', 'discount_price' (float|null) et 'errors' (array)
     */
    protected function validateDiscountFields($discount_percent, $discount_price)
    {
        $errors = [];
        $discount_percent_value = null;
        $discount_price_value = null;

        // Validation du pourcentage de remise
        $discount_percent_trimmed = trim((string) $discount_percent);
        if ($discount_percent_trimmed !== '' && $discount_percent_trimmed !== '0' && $discount_percent_trimmed !== '0.00') {
            $discount_percent_value = (float) $discount_percent;
            if (!Validate::isFloat($discount_percent) || $discount_percent_value < 0 || $discount_percent_value > 100) {
                $errors[] = $this->l('Le pourcentage de remise doit être entre 0 et 100.');
            }
        }

        // Validation du montant de remise
        $discount_price_trimmed = trim((string) $discount_price);
        if ($discount_price_trimmed !== '' && $discount_price_trimmed !== '0' && $discount_price_trimmed !== '0.00') {
            $discount_price_value = (float) $discount_price;
            if (!Validate::isFloat($discount_price) || $discount_price_value < 0) {
                $errors[] = $this->l('Le montant de remise doit être un nombre positif.');
            }
        }

        return [
            'discount_percent' => $discount_percent_value,
            'discount_price' => $discount_price_value,
            'errors' => $errors,
        ];
    }

    /**
     * Nettoie les paramètres de filtre de l'URL et les cookies
     */
    protected function cleanFilterParams()
    {
        $filterKeys = ['filter_key', 'filter_id_frequency', 'filter_name', 'filter_interval_count',
            'filter_interval', 'filter_discount_percent', 'filter_discount_price'];

        $hasFilterParams = false;
        foreach ($filterKeys as $key) {
            if (Tools::getIsset($key) && Tools::getValue($key) !== false && Tools::getValue($key) !== '') {
                $hasFilterParams = true;
                break;
            }
        }

        // Nettoyer les cookies de filtres PrestaShop
        if (isset($this->list_id) && !empty($this->list_id)) {
            $cookiePrefix = $this->context->cookie->getName() . $this->list_id . 'Filter_';
            foreach ($filterKeys as $key) {
                $cookieName = $cookiePrefix . $key;
                if (isset($this->context->cookie->{$cookieName})) {
                    unset($this->context->cookie->{$cookieName});
                }
            }
        }

        // Rediriger vers une URL propre si des paramètres de filtre sont présents
        if ($hasFilterParams && $this->display !== 'add' && $this->display !== 'edit') {
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminCiklikFrequencies'));
        }

        // Désactiver l'affichage des filtres dans le breadcrumb
        $this->filter = false;
    }
}
