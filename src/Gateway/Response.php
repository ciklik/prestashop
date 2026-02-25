<?php

/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

declare(strict_types=1);

namespace PrestaShop\Module\Ciklik\Gateway;

use PrestaShop\Module\Ciklik\Environment\CiklikEnv;

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
    ) {
        // Gestion CORS avec liste d'origines autorisées depuis les variables d'environnement
        $allowedOrigin = $this->getAllowedOrigin();
        header('Access-Control-Allow-Origin: ' . $allowedOrigin);
        header('Content-Type: application/json; charset=UTF-8');
        header('Access-Control-Allow-Methods: OPTIONS,GET,POST,PUT,DELETE');
        header('Access-Control-Max-Age: 3600');
        header('Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With');
        $this->body = $body;
        $this->status = $status;
    }

    /**
     * Détermine l'origine autorisée pour les headers CORS
     *
     * Si CIKLIK_ALLOWED_ORIGINS est défini dans l'environnement, vérifie que l'origine
     * de la requête est dans la liste. Sinon, utilise '*' comme fallback.
     *
     * @return string L'origine autorisée ou '*'
     */
    private function getAllowedOrigin(): string
    {
        $allowedOrigins = CiklikEnv::getAllowedOrigins();

        // Si aucune origine configurée, autoriser tout (rétrocompatibilité)
        if (empty($allowedOrigins)) {
            return '*';
        }

        // Récupérer l'origine de la requête
        $requestOrigin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

        // Vérifier si l'origine est dans la liste autorisée
        if (in_array($requestOrigin, $allowedOrigins, true)) {
            return $requestOrigin;
        }

        // Si l'origine n'est pas autorisée, retourner la première origine de la liste
        // (le navigateur bloquera si ce n'est pas la bonne origine)
        return $allowedOrigins[0];
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

        if (!is_null($this->body)) {
            echo $this->body;
        }

        exit;
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
