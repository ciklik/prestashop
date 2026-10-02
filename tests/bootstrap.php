<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

// Constantes PrestaShop minimales pour les tests
if (!defined('_PS_VERSION_')) {
    define('_PS_VERSION_', '1.7.8.0');
}

if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}

if (!defined('_PS_USE_SQL_SLAVE_')) {
    define('_PS_USE_SQL_SLAVE_', true);
}

if (!defined('_DB_NAME_')) {
    define('_DB_NAME_', 'prestashop_test');
}

if (!defined('__PS_BASE_URI__')) {
    define('__PS_BASE_URI__', '/');
}

// Fonction PrestaShop de nettoyage des identifiants SQL (tables, colonnes)
if (!function_exists('bqSQL')) {
    function bqSQL($string)
    {
        return str_replace('`', '\\`', pSQL($string));
    }
}

// Fonction PrestaShop de sanitisation SQL
if (!function_exists('pSQL')) {
    function pSQL($string, $htmlOK = false)
    {
        return addslashes($string);
    }
}

/**
 * Stub DbQuery pour les tests unitaires : build() assemble la requête comme
 * PrestaShop, pour la base SQLite de Db::useSqlite()
 */
class DbQuery
{
    /** @var array Parties de la requête */
    private $parts = ['select' => [], 'from' => [], 'join' => [], 'where' => [], 'order' => []];

    public function select($fields)
    {
        $this->parts['select'][] = $fields;

        return $this;
    }

    public function from($table, $alias = null)
    {
        $this->parts['from'][] = '`' . _DB_PREFIX_ . $table . '`' . ($alias ? ' ' . $alias : '');

        return $this;
    }

    public function where($condition)
    {
        $this->parts['where'][] = '(' . $condition . ')';

        return $this;
    }

    public function leftJoin($table, $alias, $on)
    {
        $this->parts['join'][] = 'LEFT JOIN `' . _DB_PREFIX_ . $table . '` ' . $alias . ' ON ' . $on;

        return $this;
    }

    public function innerJoin($table, $alias, $on)
    {
        $this->parts['join'][] = 'INNER JOIN `' . _DB_PREFIX_ . $table . '` ' . $alias . ' ON ' . $on;

        return $this;
    }

    public function orderBy($field)
    {
        $this->parts['order'][] = $field;

        return $this;
    }

    public function build()
    {
        $sql = 'SELECT ' . ($this->parts['select'] ? implode(', ', $this->parts['select']) : '*')
            . ' FROM ' . implode(', ', $this->parts['from']);

        if ($this->parts['join']) {
            $sql .= ' ' . implode(' ', $this->parts['join']);
        }
        if ($this->parts['where']) {
            $sql .= ' WHERE ' . implode(' AND ', $this->parts['where']);
        }
        if ($this->parts['order']) {
            $sql .= ' ORDER BY ' . implode(', ', $this->parts['order']);
        }

        return $sql;
    }
}

/**
 * Stub Db pour les tests unitaires
 *
 * Permet de configurer les reponses des requetes SQL
 * et d'enregistrer les appels pour assertions.
 */
class Db
{
    /** @var self|null */
    private static $instance;

    /** @var array|false Resultat de executeS() */
    private static $mockExecuteS = [];

    /** @var array File de resultats pour update() (bool ou Exception) */
    private static $mockUpdateResults = [];

    /** @var bool Resultat par defaut de update() si la file est vide */
    private static $mockUpdateDefault = true;

    /** @var array Enregistrement des appels a update() */
    public static $updateCalls = [];

    /** @var array File de resultats pour getRow() */
    private static $mockGetRowResults = [];

    /** @var array Resultat par defaut de getRow() si la file est vide */
    private static $mockGetRowDefault = [];

    /**
     * @var PDO|null Base SQLite en memoire : posee par useSqlite(), les
     *               requetes y sont reellement executees et les mocks ignores
     */
    private static $pdo;

    /** @var array Enregistrement des appels a insert() hors SQLite */
    public static $insertCalls = [];

    public static function getInstance($slave = false)
    {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Reinitialise tous les mocks
     */
    public static function resetMocks()
    {
        self::$mockExecuteS = [];
        self::$mockUpdateResults = [];
        self::$mockUpdateDefault = true;
        self::$updateCalls = [];
        self::$mockGetRowResults = [];
        self::$mockGetRowDefault = [];
        self::$mockGetValueDefault = '0';
        self::$mockGetValueResults = [];
        self::$executeCalls = [];
        self::$mockExecuteResult = true;
        self::$queryLog = [];
        self::$pdo = null;
        self::$insertCalls = [];
    }

    /**
     * Execute desormais les requetes sur une base SQLite (tests du SQL des
     * drivers transporteurs) ; resetMocks() revient aux mocks
     */
    public static function useSqlite(PDO $pdo)
    {
        self::$pdo = $pdo;
    }

    /**
     * Requete executee sur SQLite. Les deux requetes propres a MySQL des
     * drivers (information_schema.TABLES et SHOW COLUMNS) sont traduites ;
     * getRow et getValue ajoutent LIMIT 1 comme PrestaShop.
     *
     * @param string|DbQuery $query
     * @param bool $limitOne
     *
     * @return array
     */
    private static function sqliteFetchAll($query, $limitOne = false)
    {
        $sql = $query instanceof DbQuery ? $query->build() : (string) $query;

        if (preg_match('/information_schema`?\.`?TABLES.*`TABLE_NAME`\s*=\s*\'([^\']+)\'/is', $sql, $m)) {
            $stmt = self::$pdo->prepare("SELECT COUNT(*) AS n FROM sqlite_master WHERE type = 'table' AND name = ?");
            $stmt->execute([$m[1]]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if (preg_match('/^\s*SHOW COLUMNS FROM `([^`]+)`(?:\s+LIKE\s+\'([^\']*)\')?/i', $sql, $m)) {
            $rows = [];
            foreach (self::$pdo->query('PRAGMA table_info(`' . $m[1] . '`)')->fetchAll(PDO::FETCH_ASSOC) as $column) {
                if (empty($m[2]) || $column['name'] === $m[2]) {
                    $rows[] = ['Field' => $column['name']];
                }
            }

            return $rows;
        }

        if ($limitOne && !preg_match('/\blimit\b/i', $sql)) {
            $sql .= ' LIMIT 1';
        }

        return self::$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Comme Db::insert de PrestaShop : null devient '' (sans $nullValues).
     * Les valeurs, deja passees par pSQL, sont liees telles quelles : les
     * donnees de test ne contiennent pas de caracteres echappes.
     */
    public function insert($table, $data, $nullValues = false, $useCache = true, $type = 1, $addPrefix = true)
    {
        if (!self::$pdo) {
            self::$insertCalls[] = ['table' => $table, 'data' => $data];

            return true;
        }

        $columns = array_keys($data);
        $values = [];
        foreach ($data as $value) {
            $values[] = null === $value && !$nullValues ? '' : $value;
        }

        $stmt = self::$pdo->prepare(
            'INSERT INTO `' . ($addPrefix ? _DB_PREFIX_ : '') . $table . '` (`' . implode('`, `', $columns) . '`)'
            . ' VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')'
        );

        return $stmt->execute($values);
    }

    /**
     * Configure le resultat par defaut de getRow()
     *
     * @param array|false $result
     */
    public static function setMockGetRow($result)
    {
        self::$mockGetRowDefault = $result;
    }

    /**
     * Configure une file de resultats pour getRow()
     * (utile quand plusieurs getRow successifs sont attendus)
     *
     * @param array $results
     */
    public static function setMockGetRowResults(array $results)
    {
        self::$mockGetRowResults = $results;
    }

    /**
     * Configure le resultat de executeS()
     *
     * @param array|false $result
     */
    public static function setMockExecuteS($result)
    {
        self::$mockExecuteS = $result;
    }

    /**
     * Configure une file de resultats pour update()
     * Chaque element peut etre un bool ou une Exception
     *
     * @param array $results
     */
    public static function setMockUpdateResults(array $results)
    {
        self::$mockUpdateResults = $results;
    }

    public function executeS($query)
    {
        self::$queryLog[] = is_object($query) ? get_class($query) : (string) $query;

        if (self::$pdo) {
            return self::sqliteFetchAll($query);
        }

        return self::$mockExecuteS;
    }

    public function update($table, $data, $where)
    {
        self::$updateCalls[] = ['table' => $table, 'data' => $data, 'where' => $where];

        if (!empty(self::$mockUpdateResults)) {
            $next = array_shift(self::$mockUpdateResults);
            if ($next instanceof Exception) {
                throw $next;
            }

            return $next;
        }

        return self::$mockUpdateDefault;
    }

    /** @var mixed Resultat par defaut de getValue() */
    private static $mockGetValueDefault = '0';

    /** @var array File de resultats pour getValue() */
    private static $mockGetValueResults = [];

    /** @var array Enregistrement des requetes passees a execute() */
    public static $executeCalls = [];

    /** @var bool Resultat de execute() */
    private static $mockExecuteResult = true;

    /** @var array Requetes passees a getValue(), getRow() et execute(), dans l'ordre */
    public static $queryLog = [];

    /**
     * Configure le resultat par defaut de getValue()
     *
     * @param mixed $result
     */
    public static function setMockGetValue($result)
    {
        self::$mockGetValueDefault = $result;
    }

    /**
     * Configure une file de resultats pour getValue()
     *
     * @param array $results
     */
    public static function setMockGetValueResults(array $results)
    {
        self::$mockGetValueResults = $results;
    }

    /**
     * Configure le resultat de execute()
     *
     * @param bool $result
     */
    public static function setMockExecuteResult($result)
    {
        self::$mockExecuteResult = (bool) $result;
    }

    /**
     * Une Exception placee dans la file de getValue() est levee a son tour
     */
    public function getValue($query, $useCache = true)
    {
        self::$queryLog[] = is_object($query) ? get_class($query) : (string) $query;

        if (self::$pdo) {
            $rows = self::sqliteFetchAll($query, true);
            if (!$rows) {
                return false;
            }
            $row = $rows[0];

            return reset($row);
        }

        if (!empty(self::$mockGetValueResults)) {
            $next = array_shift(self::$mockGetValueResults);
            if ($next instanceof Throwable) {
                throw $next;
            }

            return $next;
        }

        return self::$mockGetValueDefault;
    }

    public function execute($query, $useCache = true)
    {
        self::$executeCalls[] = $query;
        self::$queryLog[] = is_object($query) ? get_class($query) : (string) $query;

        if (self::$pdo) {
            return false !== self::$pdo->exec($query instanceof DbQuery ? $query->build() : (string) $query);
        }

        return self::$mockExecuteResult;
    }

    public function getRow($query, $useCache = true)
    {
        self::$queryLog[] = is_object($query) ? get_class($query) : (string) $query;

        if (self::$pdo) {
            $rows = self::sqliteFetchAll($query, true);

            return $rows ? $rows[0] : false;
        }

        if (!empty(self::$mockGetRowResults)) {
            return array_shift(self::$mockGetRowResults);
        }

        return self::$mockGetRowDefault;
    }

    public function Insert_ID()
    {
        return self::$pdo ? (int) self::$pdo->lastInsertId() : 0;
    }
}

/**
 * Stub PrestaShopLogger pour les tests unitaires
 */
class PrestaShopLogger
{
    /** @var array Enregistrement des appels pour assertions */
    public static $logs = [];

    public static function addLog($message, $severity = 1, $errorCode = null, $objectType = null, $objectId = null, $allowDuplicate = false)
    {
        self::$logs[] = [
            'message' => $message,
            'severity' => $severity,
            'objectType' => $objectType,
            'objectId' => $objectId,
        ];
    }

    public static function resetLogs()
    {
        self::$logs = [];
    }
}

/**
 * Stub Hook pour les tests unitaires
 *
 * Enregistre les appels et permet de simuler des erreurs modules tiers.
 */
class Hook
{
    /** @var array Enregistrement des appels pour assertions */
    public static $calls = [];

    /** @var Exception|null Exception à lever lors du prochain appel */
    private static $throwException;

    /**
     * @param string $hookName Nom du hook
     * @param array $params Paramètres du hook
     */
    public static function exec($hookName, $params = [], $id_module = null, $array_return = false, $check_exceptions = true, $use_push = false, $id_shop = null, $chain = false)
    {
        self::$calls[] = ['hookName' => $hookName, 'params' => $params];

        if (self::$throwException) {
            $e = self::$throwException;
            self::$throwException = null;

            throw $e;
        }

        return '';
    }

    /**
     * Configure une exception à lever au prochain appel
     *
     * @param Exception $e
     */
    public static function setThrowException(Exception $e)
    {
        self::$throwException = $e;
    }

    public static function resetMocks()
    {
        self::$calls = [];
        self::$throwException = null;
    }
}

/**
 * Stub Cart minimal pour les tests unitaires
 */
class Cart
{
    public $id;
    public $id_customer;
    public $id_address_delivery;
    public $id_address_invoice;
    public $id_lang;
    public $id_currency;
    public $id_carrier;
    public $secure_key;

    public function __construct($id = null)
    {
        $this->id = $id;
    }
}

/**
 * Stub Carrier pour les tests unitaires : transporteurs declares par
 * setMockCarrier, inconnus sinon (id null, comme un ObjectModel non charge)
 */
class Carrier
{
    public $id;
    public $id_reference;
    public $name = '';
    public $external_module_name = '';

    /** @var array Transporteurs mockes [id => champs] */
    private static $mockCarriers = [];

    public function __construct($id = null, $idLang = null)
    {
        if (null !== $id && isset(self::$mockCarriers[(int) $id])) {
            $this->id = (int) $id;
            foreach (self::$mockCarriers[(int) $id] as $field => $value) {
                $this->$field = $value;
            }
        }
    }

    public static function setMockCarrier($id, array $fields)
    {
        self::$mockCarriers[(int) $id] = $fields + ['id_reference' => (int) $id];
    }

    public static function getCarrierByReference($idReference, $idLang = null)
    {
        foreach (self::$mockCarriers as $id => $fields) {
            if ((int) $fields['id_reference'] === (int) $idReference) {
                return new self($id);
            }
        }

        return false;
    }

    public static function resetMocks()
    {
        self::$mockCarriers = [];
    }
}

/**
 * Stub Configuration pour les tests unitaires
 */
class Configuration
{
    /** @var array Valeurs mockées */
    private static $values = [];

    public static function get($key, $idLang = null, $idShopGroup = null, $idShop = null)
    {
        if (isset(self::$values[$key])) {
            return self::$values[$key];
        }

        return false;
    }

    public static function updateValue($key, $value)
    {
        self::$values[$key] = $value;

        return true;
    }

    public static function resetMocks()
    {
        self::$values = [];
    }
}

/**
 * Stub StockAvailable pour les tests unitaires
 */
class StockAvailable
{
    /** @var array Stock mocké [id_product:id_product_attribute => quantity] */
    private static $stocks = [];

    public static function getQuantityAvailableByProduct($idProduct, $idProductAttribute = 0)
    {
        $key = $idProduct . ':' . $idProductAttribute;

        return isset(self::$stocks[$key]) ? self::$stocks[$key] : 0;
    }

    public static function setMockStock($idProduct, $idProductAttribute, $quantity)
    {
        self::$stocks[$idProduct . ':' . $idProductAttribute] = $quantity;
    }

    public static function resetMocks()
    {
        self::$stocks = [];
    }
}

/**
 * Stub Product pour les tests unitaires
 */
class Product
{
    public $id;
    public $name;
    public $active = true;
    public $visibility = 'both';
    public $available_for_order = true;

    /** @var array Noms mockés [id => name] */
    private static $mockNames = [];

    /** @var bool Résultat de isAssociatedToShop() */
    public static $mockAssociatedToShop = true;

    /** @var bool Résultat de checkAccess() */
    public static $mockAccess = true;

    /** @var array Clients passés à checkAccess() */
    public static $checkAccessCalls = [];

    public function __construct($id = null, $full = false, $idLang = null)
    {
        $this->id = $id;
        $this->name = null !== $id && isset(self::$mockNames[$id]) ? self::$mockNames[$id] : '';
    }

    public function isAssociatedToShop($idShop = null)
    {
        return self::$mockAssociatedToShop;
    }

    public function checkAccess($idCustomer)
    {
        self::$checkAccessCalls[] = $idCustomer;

        return self::$mockAccess;
    }

    public static function setMockName($id, $name)
    {
        self::$mockNames[$id] = $name;
    }

    public static function resetMocks()
    {
        self::$mockNames = [];
        self::$mockAssociatedToShop = true;
        self::$mockAccess = true;
        self::$checkAccessCalls = [];
    }
}

/**
 * Stub Pack pour les tests unitaires
 */
class Pack
{
    /** @var array Identifiants des produits qui sont des packs */
    public static $packs = [];

    public static function isPack($idProduct)
    {
        return in_array((int) $idProduct, self::$packs, true);
    }
}

/**
 * Stub Combination pour les tests unitaires
 */
class Combination
{
    public $id;

    /** @var array Attributs mockés [id => [['name' => '...']]] */
    private static $mockAttributes = [];

    public function __construct($id = null)
    {
        $this->id = $id;
    }

    public function getAttributesName($idLang)
    {
        return isset(self::$mockAttributes[$this->id]) ? self::$mockAttributes[$this->id] : [];
    }

    public static function setMockAttributes($id, $attributes)
    {
        self::$mockAttributes[$id] = $attributes;
    }

    public static function resetMocks()
    {
        self::$mockAttributes = [];
    }
}

/**
 * Stub Ciklik (constantes du module) pour les tests unitaires
 */
class Ciklik
{
    public const VERSION = '1.17.1';
    public const CONFIG_API_TOKEN = 'CIKLIK_API_TOKEN';
    public const CONFIG_MODE = 'CIKLIK_MODE';
    public const CONFIG_HOST = 'CIKLIK_HOST';
    public const CONFIG_USE_FREQUENCY_MODE = 'CIKLIK_FREQUENCY_MODE';
    public const CONFIG_FREQUENCIES_ATTRIBUTE_GROUP_ID = 'CIKLIK_FREQUENCIES_ATTRIBUTE_GROUP_ID';
    public const CONFIG_DEBUG_LOGS_ENABLED = 'CIKLIK_DEBUG_LOGS_ENABLED';
    public const CONFIG_ENABLE_ENGAGEMENT = 'CIKLIK_ENABLE_ENGAGEMENT';
    public const CONFIG_ENGAGEMENT_INTERVAL = 'CIKLIK_ENGAGEMENT_INTERVAL';
    public const CONFIG_ENGAGEMENT_INTERVAL_COUNT = 'CIKLIK_ENGAGEMENT_INTERVAL_COUNT';
    public const CONFIG_ORDER_STATE = 'CIKLIK_ORDER_STATE';
    public const CONFIG_ENABLE_CREATION_ORDER_STATE = 'CIKLIK_ENABLE_CREATION_ORDER_STATE';
    public const CONFIG_CREATION_ORDER_STATE = 'CIKLIK_CREATION_ORDER_STATE';
    public const CONFIG_ENABLE_UPSELL = 'CIKLIK_ENABLE_UPSELL';
}

/**
 * Stub Validate pour les tests unitaires : memes expressions que
 * classes/Validate.php de PrestaShop 1.7 pour les champs de payload relais
 */
class Validate
{
    public static function isLoadedObject($object)
    {
        return is_object($object) && !empty($object->id);
    }

    public static function isGenericName($name)
    {
        return empty($name) || preg_match('/^[^<>={}]*$/u', $name);
    }

    public static function isAddress($address)
    {
        return empty($address) || preg_match('/^[^!<>?=+@{}_$%]*$/u', $address);
    }

    public static function isPostCode($postcode)
    {
        return empty($postcode) || preg_match('/^[a-zA-Z 0-9-]+$/', $postcode);
    }

    public static function isCityName($city)
    {
        return preg_match('/^[^!<>;?=+@#"°{}_$%]*$/u', $city);
    }

    public static function isPhoneNumber($number)
    {
        return preg_match('/^[+0-9. ()\/-]*$/', $number);
    }
}

/**
 * Stub Shop pour les tests unitaires : contexte multiboutique
 */
class Shop
{
    const CONTEXT_SHOP = 1;
    const CONTEXT_GROUP = 2;
    const CONTEXT_ALL = 4;

    /** @var bool */
    public static $featureActive = false;

    /** @var int */
    public static $context = self::CONTEXT_SHOP;

    /** @var int|null */
    public static $contextShopId = 1;

    /** @var int|null */
    public static $contextGroupId = 1;

    /** @var array Appels a setContext() [type, id] */
    public static $setContextCalls = [];

    public static function isFeatureActive()
    {
        return self::$featureActive;
    }

    public static function getContext()
    {
        return self::$context;
    }

    public static function getContextShopID($nullValueWithoutMultishop = false)
    {
        return self::$contextShopId;
    }

    public static function getContextShopGroupID($nullValueWithoutMultishop = false)
    {
        return self::$contextGroupId;
    }

    public static function setContext($type, $id = null)
    {
        self::$setContextCalls[] = [$type, $id];
        self::$context = $type;
        self::$contextShopId = self::CONTEXT_SHOP === $type ? $id : null;
        self::$contextGroupId = self::CONTEXT_GROUP === $type ? $id : null;
    }

    public static function resetMocks()
    {
        self::$featureActive = false;
        self::$context = self::CONTEXT_SHOP;
        self::$contextShopId = 1;
        self::$contextGroupId = 1;
        self::$setContextCalls = [];
    }
}

/**
 * Stub Tools pour les tests unitaires : seulement ce que le code teste appelle
 */
class Tools
{
    /**
     * Version reduite de Tools::replaceAccentedChars (lettres accentuees
     * courantes du francais)
     */
    public static function replaceAccentedChars($str)
    {
        return strtr((string) $str, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ÿ' => 'y',
            'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Ç' => 'C', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Î' => 'I', 'Ï' => 'I', 'Ô' => 'O', 'Ö' => 'O', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
        ]);
    }

    public static function substr($str, $start, $length = false, $encoding = 'utf-8')
    {
        return false === $length
            ? mb_substr((string) $str, (int) $start, null, $encoding)
            : mb_substr((string) $str, (int) $start, (int) $length, $encoding);
    }

    public static function strlen($str, $encoding = 'UTF-8')
    {
        return mb_strlen((string) $str, $encoding);
    }

    /** @var array Paramètres de requête lus par getValue() */
    public static $requestValues = [];

    /** @var array Appels à redirect() : url et en-têtes */
    public static $redirects = [];

    public static function getValue($key, $defaultValue = false)
    {
        return isset(self::$requestValues[$key]) ? self::$requestValues[$key] : $defaultValue;
    }

    /**
     * Comme PrestaShop, une redirection termine la requête : ici par une exception
     */
    public static function redirect($url, $baseUri = __PS_BASE_URI__, $link = null, $headers = null)
    {
        self::$redirects[] = ['url' => $url, 'headers' => $headers];

        throw new RuntimeException('redirect');
    }
}

/**
 * Stub ModuleFrontController pour les tests des contrôleurs front
 */
class ModuleFrontController
{
    public $auth = false;
    public $module;
    public $context;
}

/**
 * Stub Link pour les tests unitaires
 */
class Link
{
    public function getModuleLink($module, $controller = 'default', $params = [], $ssl = null, $idLang = null, $idShop = null, $relativeProtocol = false)
    {
        return 'https://shop.test/module/' . $module . '/' . $controller;
    }
}

require_once __DIR__ . '/../vendor/autoload.php';
