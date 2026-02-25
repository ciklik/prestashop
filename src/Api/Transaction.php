<?php

/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Api;

use PrestaShop\Module\Ciklik\Data\TransactionData;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Transaction extends CiklikApiClient
{
    public function getAll(array $options = [])
    {
        $this->setRoute('transactions');

        return $this->get($options);
    }

    public function getOne(string $ciklik_transaction_id, array $options = [])
    {
        $error = $this->setRouteWithValidation(
            'transactions/%s',
            $ciklik_transaction_id,
            'alphanumeric',
            'Invalid transaction ID format',
        );

        if (null !== $error) {
            return null;
        }

        $response = $this->get($options);

        if ($response['status']) {
            return TransactionData::create($response['body']);
        }

        return null;
    }

    public function refund(string $ciklik_transaction_id, float $amount)
    {
        $error = $this->setRouteWithValidation(
            'transactions/%s',
            $ciklik_transaction_id,
            'alphanumeric',
            'Invalid transaction ID format',
        );

        if (null !== $error) {
            return null;
        }

        $response = $this->put([
            'json' => [
                'amount' => $amount,
            ],
        ]);

        if ($response['status']) {
            return TransactionData::create($response['body']);
        }

        return null;
    }
}
