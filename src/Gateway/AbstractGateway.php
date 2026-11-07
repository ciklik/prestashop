<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Gateway;

use PrestaShop\Module\Ciklik\Api\Order;
use PrestaShop\Module\Ciklik\Data\OrderData;

if (!defined('_PS_VERSION_')) {
    exit;
}

abstract class AbstractGateway implements EntityGateway
{
    /**
     * Erreur renvoyée à Ciklik, avec un statut 503, quand la boutique n'a pas pu le joindre
     */
    const CIKLIK_UNREACHABLE_ERROR = 'The shop could not reach Ciklik';

    /**
     * @var \Ciklik
     */
    public $module;

    public function __construct(\Ciklik $module)
    {
        $webservice = new \WebserviceKey((int) \Configuration::get(\Ciklik::CONFIG_WEBSERVICE_ID));

        if (empty($webservice->key)) {
            (new Response())->sendUnauthorized();
        }

        $expectedAuth = 'Basic ' . base64_encode($webservice->key . ':');
        $providedAuth = array_key_exists('HTTP_AUTHORIZATION', $_SERVER) ? $_SERVER['HTTP_AUTHORIZATION'] : '';

        // Comparaison à temps constant pour éviter les timing attacks
        if (!hash_equals($expectedAuth, $providedAuth)) {
            (new Response())->sendUnauthorized();
        }

        $this->module = $module;
    }

    public function handle()
    {
        switch ($_SERVER['REQUEST_METHOD']) {
            case 'GET':
                $this->get();
                break;
            case 'POST':
                $this->post();
                break;
            default:
                (new Response())->setBody(['error' => 'Method not allowed'])->sendMethodNotAllowed();
                break;
        }
    }

    public function get()
    {
        (new Response())->setBody(['error' => 'GET method doesn\'t exist'])->sendMethodNotAllowed();
    }

    public function post()
    {
        (new Response())->setBody(['error' => 'POST method doesn\'t exist'])->sendMethodNotAllowed();
    }

    public function put()
    {
        (new Response())->setBody(['error' => 'PUT method doesn\'t exist'])->sendMethodNotAllowed();
    }

    public function delete()
    {
        (new Response())->setBody(['error' => 'DELETE method doesn\'t exist'])->sendMethodNotAllowed();
    }

    /**
     * Relit une commande dans l'API Ciklik sans jamais lever d'exception
     * (public pour testabilité uniquement)
     *
     * @param int $ciklikOrderId
     * @param Order|null $api client de l'API, créé ici par défaut
     *
     * @return array [OrderData|null, \Throwable|null] la commande, null si illisible, et l'erreur quand Ciklik n'a
     *               pas répondu (connexion impossible, délai dépassé) ou que l'appel a levé une exception
     */
    public static function fetchCiklikOrder($ciklikOrderId, $api = null)
    {
        try {
            $api = $api ?: new Order(\Context::getContext()->link);
            $orderData = $api->getOne((int) $ciklikOrderId);

            return [$orderData, $api->getNetworkError()];
        } catch (\Throwable $e) {
            // ConnectException de Guzzle 7 et, en dernier recours, toute autre erreur de l'appel : jamais d'erreur fatale
            return [null, $e];
        }
    }

    /**
     * Ciklik injoignable pendant un appel venu de Ciklik : journal, puis 503 pour que Ciklik réessaie plus tard
     *
     * @param int $ciklikOrderId
     * @param \Throwable $error
     */
    protected function sendCiklikUnreachable($ciklikOrderId, \Throwable $error)
    {
        self::logCiklikUnreachable((new \ReflectionClass($this))->getShortName(), $ciklikOrderId, $error);
        (new Response())->setBody(['error' => self::CIKLIK_UNREACHABLE_ERROR])->sendServiceUnavailable();
    }

    /**
     * Journalise une commande Ciklik illisible : passerelle, classe et message de l'erreur, sans le jeton d'API ni
     * les caractères < > { } que PrestaShopLogger refuse (public pour testabilité uniquement)
     *
     * @param string $gateway
     * @param int $ciklikOrderId
     * @param \Throwable $error
     */
    public static function logCiklikUnreachable($gateway, $ciklikOrderId, \Throwable $error)
    {
        $message = sprintf(
            'Ciklik %s - Ciklik order %d could not be read from the Ciklik API, answered 503 so that Ciklik retries: %s %s',
            $gateway,
            (int) $ciklikOrderId,
            get_class($error),
            $error->getMessage()
        );

        try {
            $token = (string) \Configuration::get(\Ciklik::CONFIG_API_TOKEN);
            if ('' !== $token) {
                $message = str_replace($token, '***', $message);
            }

            \PrestaShopLogger::addLog((string) preg_replace('/[<>{}\x00-\x1F\x7F]/', ' ', $message), 3, null, null, null, true);
        } catch (\Throwable $e) {
            // Le journal ne doit jamais empêcher la réponse à Ciklik
        }
    }
}
