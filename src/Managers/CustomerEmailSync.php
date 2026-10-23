<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Managers;

use PrestaShop\Module\Ciklik\Api\Customer as CustomerApi;
use PrestaShop\Module\Ciklik\Helpers\ShopContextSwitch;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Changement d'email d'un client dans la boutique, signalé tout de suite à Ciklik (PUT customers/email).
 * Seul l'identifiant part : Ciklik relit lui-même le client, l'adresse ne transite jamais.
 *
 * Branché sur actionObjectCustomerUpdateBefore/After (ObjectModel::update(), PrestaShop 1.7 à 9 : espace client,
 * back-office, import, webservice). Avant : l'adresse en base est mémorisée pour les seuls clients connus de Ciklik
 * (ligne ciklik_customers ou commande Ciklik). Après : appel si l'adresse a changé, avec la clé de la boutique de la
 * dernière commande Ciklik, à défaut celle de la boutique du client.
 *
 * Jamais bloquant : délais courts, un échec est journalisé (sans l'adresse) et coupe les appels suivants vers la même
 * boutique ; au-delà de BUDGET secondes d'appels cumulés dans la requête, plus aucun appel.
 */
class CustomerEmailSync
{
    /** Délai total de l'appel, en secondes */
    const TIMEOUT = 2;

    /** Délai de connexion, en secondes */
    const CONNECT_TIMEOUT = 1;

    /** Durée cumulée des appels d'une même requête (import en masse), en secondes */
    const BUDGET = 6;

    /** @var array id_customer => ['email' => adresse en base avant l'enregistrement, 'id_shop' => boutique Ciklik ou 0] */
    private $previous = [];

    /** @var array id_shop => true : un appel vers cette boutique a échoué dans cette requête, plus aucun autre */
    private $unavailableShops = [];

    /** @var float Durée cumulée des appels, en secondes ; INF une fois le dépassement journalisé */
    private $spent = 0.0;

    /** @var float */
    private $budget;

    /** @var callable|null Envoi à Ciklik (tests), fn(int $idCustomer): array ; null pour l'API Ciklik */
    private $sender;

    public function __construct($sender = null, float $budget = self::BUDGET)
    {
        $this->sender = $sender;
        $this->budget = $budget;
    }

    /**
     * Avant l'enregistrement : mémorise l'adresse en base si le client est connu de Ciklik. Une adresse déjà
     * mémorisée est gardée (enregistrement imbriqué par un autre module).
     */
    public function rememberPreviousEmail($customer)
    {
        $idCustomer = (int) $customer->id;

        if ($idCustomer <= 0 || isset($this->previous[$idCustomer])) {
            return;
        }

        try {
            // Sans cache : l'adresse doit être celle de la base à cet instant
            $row = \Db::getInstance()->getRow(
                'SELECT c.`email`,'
                . ' (SELECT 1 FROM `' . _DB_PREFIX_ . 'ciklik_customers` cc WHERE cc.`id_customer` = c.`id_customer` LIMIT 1) AS `known`,'
                . ' (SELECT o.`id_shop` FROM `' . _DB_PREFIX_ . 'orders` o WHERE o.`id_customer` = c.`id_customer`'
                . ' AND o.`module` = \'ciklik\' ORDER BY o.`id_order` DESC LIMIT 1) AS `ciklik_shop`'
                . ' FROM `' . _DB_PREFIX_ . 'customer` c WHERE c.`id_customer` = ' . $idCustomer,
                false
            );
        } catch (\Throwable $e) {
            $this->log($idCustomer, get_class($e));

            return;
        }

        if (!is_array($row) || !isset($row['email']) || '' === (string) $row['email']
            || (empty($row['known']) && empty($row['ciklik_shop']))) {
            return;
        }

        $this->previous[$idCustomer] = [
            'email' => (string) $row['email'],
            'id_shop' => (int) $row['ciklik_shop'],
        ];
    }

    /**
     * Après l'enregistrement : signale à Ciklik le changement d'adresse.
     *
     * @return bool Ciklik a accepté l'appel
     */
    public function notifyIfChanged($customer)
    {
        $idCustomer = (int) $customer->id;

        if (!isset($this->previous[$idCustomer])) {
            return false;
        }

        $previous = $this->previous[$idCustomer];
        unset($this->previous[$idCustomer]);

        $email = trim((string) $customer->email);

        // Même règle que Ciklik : la casse et les espaces ne changent pas l'adresse
        if ('' === $email || mb_strtolower($email, 'UTF-8') === mb_strtolower(trim($previous['email']), 'UTF-8')) {
            return false;
        }

        $idShop = $previous['id_shop'] > 0 ? $previous['id_shop'] : (int) $customer->id_shop;

        if (isset($this->unavailableShops[$idShop])) {
            return false;
        }

        if ($this->spent >= $this->budget) {
            if (INF !== $this->spent) {
                $this->spent = INF;
                $this->log($idCustomer, $this->budget . ' s d\'appels cumulés, plus aucun signal dans cette requête');
            }

            return false;
        }

        $shopContext = new ShopContextSwitch();
        $start = microtime(true);

        try {
            // Jeton et URL de l'API de cette boutique (multiboutique), pas de celle du contexte (back-office)
            $shopContext->switchTo($idShop);

            // Boutique non reliée à Ciklik : rien à prévenir, rien à journaliser
            if (empty(\Configuration::get(\Ciklik::CONFIG_API_TOKEN))) {
                return false;
            }

            $response = $this->send($idCustomer);
        } catch (\Throwable $e) {
            // Connexion impossible : Guzzle 7 lève une exception
            $this->fail($idShop, $idCustomer, get_class($e));

            return false;
        } finally {
            $shopContext->restore();
            $this->spent += microtime(true) - $start;
        }

        if (!is_array($response) || empty($response['status'])) {
            $this->fail($idShop, $idCustomer, 'HTTP ' . (is_array($response) && isset($response['httpCode']) ? (int) $response['httpCode'] : 0));

            return false;
        }

        return true;
    }

    /** @return array Réponse formatée par CiklikApiResponseHandler */
    private function send(int $idCustomer)
    {
        if (null !== $this->sender) {
            return call_user_func($this->sender, $idCustomer);
        }

        return (new CustomerApi(\Context::getContext()->link ?: new \Link()))->notifyEmailChanged($idCustomer, [
            'timeout' => self::TIMEOUT,
            'connect_timeout' => self::CONNECT_TIMEOUT,
        ]);
    }

    private function fail(int $idShop, int $idCustomer, string $reason)
    {
        $this->unavailableShops[$idShop] = true;
        $this->log($idCustomer, $reason . ', plus aucun signal vers la boutique ' . $idShop . ' dans cette requête');
    }

    /** Journal de la boutique, sans l'adresse du client */
    private function log(int $idCustomer, string $reason)
    {
        \PrestaShopLogger::addLog(
            'Ciklik : changement d\'email du client non transmis (' . $reason . '), repris à sa prochaine commande',
            2,
            null,
            'Customer',
            $idCustomer,
            true
        );
    }
}
