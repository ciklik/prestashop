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
        $this->setRoute("transactions/{$ciklik_transaction_id}");

        $response = $this->get($options);

        if ($response['status']) {
            return TransactionData::create($response['body']);
        }

        return null;
    }

    public function refund(string $ciklik_transaction_id, float $amount)
    {
        $this->setRoute("transactions/{$ciklik_transaction_id}");

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
