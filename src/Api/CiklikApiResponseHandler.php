<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Api;

use Psr\Http\Message\ResponseInterface;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CiklikApiResponseHandler
{
    /**
     * Format api response
     *
     * @param ResponseInterface $response
     *
     * @return array
     */
    public function handleResponse($response)
    {
        $responseContents = json_decode($response->getBody()->getContents(), true);

        return [
            'status' => $this->responseIsSuccessful($responseContents, $response->getStatusCode()),
            'httpCode' => $response->getStatusCode(),
            'body' => array_key_exists('data', $responseContents) ? $responseContents['data'] : [],
            'message' => $response->getReasonPhrase(),
            'errors' => $responseContents['errors'] ?? [],
        ];
    }

    /**
     * Check if the response is successful or not (response code 200 to 299)
     *
     * @param array $responseContents
     * @param int $httpStatusCode
     *
     * @return bool
     */
    private function responseIsSuccessful($responseContents, $httpStatusCode)
    {
        // Directly return true, no need to check the body for a 204 status code
        // 204 status code is only send by /payments/order/update
        if ($httpStatusCode === 204) {
            return true;
        }

        return substr((string) $httpStatusCode, 0, 1) === '2' && $responseContents !== null;
    }
}
