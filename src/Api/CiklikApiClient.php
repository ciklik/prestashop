<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Api;

use Ciklik;
use Configuration;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Response;
use Link;
use Module;
use PrestaShop\Module\Ciklik\Environment\CiklikEnv;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CiklikApiClient
{
    /**
     * Guzzle Client
     *
     * @var Client
     */
    protected $client;

    /**
     * Class Link in order to generate module link
     *
     * @var Link
     */
    protected $link;

    /**
     * Set how long guzzle will wait a response before end it up
     *
     * @var int
     */
    protected $timeout = 10;

    /**
     * Api route
     *
     * @var string
     */
    protected $route;

    protected $throwGuzzleExceptions = true;

    public function __construct(Link $link, ?Client $client = null)
    {
        $this->setLink($link);

        if (null === $client) {
            $client = new Client([
                'base_uri' => (new CiklikEnv())->getCiklikApiUrl(),
                'base_url' => (new CiklikEnv())->getCiklikApiUrl(),
                'timeout' => $this->timeout,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . Configuration::get(Ciklik::CONFIG_API_TOKEN),
                    // 'Hook-Url' => $this->link->getModuleLink('ciklik', 'DispatchWebHook', [], true),
                    'Module-Version' => Ciklik::VERSION, // version of the module
                    'Prestashop-Version' => _PS_VERSION_, // prestashop version
                    'User-Agent' => 'Ciklik-Prestashop/' . Ciklik::VERSION,
                ],
                'defaults' => [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                        'Authorization' => 'Bearer ' . Configuration::get(Ciklik::CONFIG_API_TOKEN),
                        // 'Hook-Url' => $this->link->getModuleLink('ciklik', 'DispatchWebHook', [], true),
                        'Module-Version' => Ciklik::VERSION, // version of the module
                        'Prestashop-Version' => _PS_VERSION_, // prestashop version
                        'User-Agent' => 'Ciklik-Prestashop/' . Ciklik::VERSION,
                    ],
                ],
                'http_errors' => $this->throwGuzzleExceptions,
            ]);
        }

        $this->setClient($client);
    }

    /**
     * Wrapper of method get from guzzle client
     *
     * @param array $options payload
     *
     * @return array return response or false if no response
     */
    protected function get(array $options = [])
    {
        try {
            $response = $this->getClient()->get($this->getRoute(), $options);
            
        } catch (RequestException $e) {
            $response = $e->getResponse();
            // If no response (network error, timeout, etc.), create a mock response
            if (null === $response) {
                $response = new Response(500, [], json_encode(['errors' => ['message' => $e->getMessage()]]));
            }
        }
        return $this->handleResponse(
            $response,
            $options
        );
    }

    /**
     * Wrapper of method post from guzzle client
     *
     * @param array $options payload
     *
     * @return array return response or false if no response
     */
    protected function post(array $options = []): array
    {
        try {
            $response = $this->getClient()->post($this->getRoute(), $options);
        } catch (RequestException $e) {
            $response = $e->getResponse();
            // If no response (network error, timeout, etc.), create a mock response
            if (null === $response) {
                $response = new Response(500, [], json_encode(['errors' => ['message' => $e->getMessage()]]));
            }
        }
        return $this->handleResponse(
            $response,
            $options
        );
    }

    /**
     * Wrapper of method put from guzzle client
     *
     * @param array $options payload
     *
     * @return array return response or false if no response
     */
    protected function put(array $options = []): array
    {
        try {
            $response = $this->getClient()->put($this->getRoute(), $options);
        } catch (RequestException $e) {
            $response = $e->getResponse();
            // If no response (network error, timeout, etc.), create a mock response
            if (null === $response) {
                $response = new Response(500, [], json_encode(['errors' => ['message' => $e->getMessage()]]));
            }
        }

        return $this->handleResponse(
            $response,
            $options
        );
    }

    private function handleResponse($response, array $options = []): array
    {
        $responseHandler = new CiklikApiResponseHandler();

        $response = $responseHandler->handleResponse($response);

        if (Configuration::get(Ciklik::CONFIG_DEBUG_LOGS_ENABLED)) {
            $module = Module::getInstanceByName('ciklik');
            $logger = $module->getLogger();
            $logger->debug('route: ' . $this->getRoute());
            $logger->debug('options: ' . var_export($options, true));
            $logger->debug('response: ' . var_export($response, true));
        }

        return $response;
    }

    /**
     * Setter for route
     *
     * @param string $route
     */
    protected function setRoute($route)
    {
        $this->route = $route;
    }

    /**
     * Setter for client
     *
     * @param Client $client
     */
    protected function setClient(Client $client)
    {
        $this->client = $client;
    }

    /**
     * Setter for link
     *
     * @param Link $link
     */
    protected function setLink(Link $link)
    {
        $this->link = $link;
    }

    /**
     * Setter for timeout
     *
     * @param int $timeout
     */
    protected function setTimeout($timeout)
    {
        $this->timeout = $timeout;
    }

    /**
     * Setter for exceptions mode
     *
     * @param bool $bool
     */
    protected function setExceptionsMode($bool)
    {
        $this->catchExceptions = $bool;
    }

    /**
     * Getter for route
     *
     * @return string
     */
    protected function getRoute()
    {
        return $this->route;
    }

    /**
     * Getter for client
     *
     * @return Client
     */
    protected function getClient()
    {
        return $this->client;
    }

    /**
     * Getter for Link
     *
     * @return Link
     */
    protected function getLink()
    {
        return $this->link;
    }

    /**
     * Getter for timeout
     *
     * @return int
     */
    protected function getTimeout()
    {
        return $this->timeout;
    }

    /**
     * Getter for exceptions mode
     *
     * @return bool
     */
    protected function getExceptionsMode()
    {
        return $this->catchExceptions;
    }
}
