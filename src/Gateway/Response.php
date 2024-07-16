<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Gateway;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Response
{
    /**
     * @var string
     */
    private $body;
    /**
     * @var string
     */
    private $status;

    public function __construct(
        string $body = '{}',
        string $status = 'HTTP/1.1 200 OK'
    )
    {
        header("Access-Control-Allow-Origin: *");
        header("Content-Type: application/json; charset=UTF-8");
        header("Access-Control-Allow-Methods: OPTIONS,GET,POST,PUT,DELETE");
        header("Access-Control-Max-Age: 3600");
        header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
        $this->body = $body;
        $this->status = $status;
    }

    /**
     * @param array $body
     */
    public function setBody(array $data = []): self
    {
        $this->body = json_encode($data, JSON_PRETTY_PRINT);

        return $this;
    }

    /**
     * @param string $status
     */
    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function send(): void
    {
        header($this->status);

        if (! is_null($this->body)) {
            echo $this->body;
        }

        exit();
    }

    public function sendCreated()
    {
        $this->setStatus('HTTP/1.0 201 Created');
        $this->send();
    }

    public function sendBadRequest()
    {
        $this->setStatus('HTTP/1.0 400 Bad Request');
        $this->send();
    }

    public function sendUnauthorized()
    {
        $this->setStatus('HTTP/1.0 401 Unauthorized');
        $this->send();
    }

    public function sendNotFound()
    {
        $this->setStatus('HTTP/1.1 404 Not Found');
        $this->send();
    }

    public function sendMethodNotAllowed()
    {
        $this->setStatus('HTTP/1.0 405 Method Not Allowed');
        $this->send();
    }

    public function sendNotAcceptable()
    {
        $this->setStatus('HTTP/1.1 406 Not Acceptable');
        $this->send();
    }

    public function sendConflict()
    {
        $this->setStatus('HTTP/1.1 409 Conflict');
        $this->send();
    }
}
