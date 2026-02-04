<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Gateway;

use Ciklik;
use Configuration;
use WebserviceKey;

if (!defined('_PS_VERSION_')) {
    exit;
}

abstract class AbstractGateway implements EntityGateway
{
    /**
     * @var Ciklik
     */
    public $module;

    public function __construct(Ciklik $module)
    {
        $webservice = new WebserviceKey(Configuration::get(Ciklik::CONFIG_WEBSERVICE_ID));

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
}
