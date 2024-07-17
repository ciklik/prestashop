<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Data;

if (!defined('_PS_VERSION_')) {
    exit;
}

class ShopData
{
    /**
     * @var int
     */
    public $id;
    /**
     * @var string
     */
    public $host;
    /**
     * @var string
     */
    public $name;
    /**
     * @var array
     */
    public $paymentMethods;
    /**
     * @var array
     */
    public $webhooks;
    /**
     * @var array
     */
    public $metadata;

    private function __construct(int $id,
                                 string $host,
                                 string $name,
                                 array $paymentMethods,
                                 array $webhooks,
                                 array $metadata)
    {
        $this->id = $id;
        $this->host = $host;
        $this->name = $name;
        $this->paymentMethods = $paymentMethods;
        $this->webhooks = $webhooks;
        $this->metadata = $metadata;
    }

    public static function create(array $data): ShopData
    {
        return new self(
            $data['id'],
            $data['host'],
            $data['name'],
            PaymentMethodData::collection($data['paymentMethods']),
            $data['webhooks'],
            $data['metadata']
        );
    }
}
