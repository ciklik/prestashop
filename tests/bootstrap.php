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

// Fonction PrestaShop de sanitisation SQL
if (!function_exists('pSQL')) {
    function pSQL($string, $htmlOK = false)
    {
        return addslashes($string);
    }
}

/**
 * Stub DbQuery pour les tests unitaires
 */
class DbQuery
{
    public function select($fields)
    {
        return $this;
    }

    public function from($table, $alias = null)
    {
        return $this;
    }

    public function where($condition)
    {
        return $this;
    }

    public function leftJoin($table, $alias, $on)
    {
        return $this;
    }

    public function innerJoin($table, $alias, $on)
    {
        return $this;
    }

    public function orderBy($field)
    {
        return $this;
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
        return self::$mockExecuteS;
    }

    public function update($table, $data, $where)
    {
        self::$updateCalls[] = ['table' => $table, 'data' => $data, 'where' => $where];

        if (!empty(self::$mockUpdateResults)) {
            $next = array_shift(self::$mockUpdateResults);
            if ($next instanceof \Exception) {
                throw $next;
            }

            return $next;
        }

        return self::$mockUpdateDefault;
    }

    public function getValue($query)
    {
        return '0';
    }

    public function execute($query)
    {
        return true;
    }

    public function getRow($query)
    {
        return [];
    }

    public function Insert_ID()
    {
        return 0;
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

    /** @var \Exception|null Exception à lever lors du prochain appel */
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
     * @param \Exception $e
     */
    public static function setThrowException(\Exception $e)
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

    public function __construct($id = null)
    {
        $this->id = $id;
    }
}

require_once __DIR__ . '/../vendor/autoload.php';
