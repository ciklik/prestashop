<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Data;

use Carbon\CarbonImmutable;

class SubscriptionData
{
    /**
     * @var string
     */
    public $uuid;
    /**
     * @var bool
     */
    public $active;
    /**
     * @var string
     */
    public $display_content;
    /**
     * @var string
     */
    public $display_interval;
    /**
     * @var SubscriptionDeliveryAddressData
     */
    public $address;
    /**
     * @var \DateTimeImmutable
     */
    public $next_billing;
    /**
     * @var \DateTimeImmutable
     */
    public $created_at;
    /**
     * @var \DateTimeImmutable
     */
    public $end_date;

    /**
     * @var string
     */
    public $external_fingerprint;

    private function __construct(string                          $uuid,
                                 bool                            $active,
                                 string                          $display_content,
                                 string                          $display_interval,
                                 SubscriptionDeliveryAddressData $address,
                                 \DateTimeImmutable              $next_billing,
                                 \DateTimeImmutable              $created_at,
                                 \DateTimeImmutable              $end_date,
                                 CartFingerprintData             $external_fingerprint
    )
    {
        $this->uuid = $uuid;
        $this->active = $active;
        $this->display_content = $display_content;
        $this->display_interval = $display_interval;
        $this->address = $address;
        $this->next_billing = $next_billing;
        $this->created_at = $created_at;
        $this->end_date = $end_date;
        $this->external_fingerprint = $external_fingerprint;
    }

    public static function create(array $data): SubscriptionData
    {
        $fingerprint = CartFingerprintData::unserialize($data['external_fingerprint']);

        return new self(
            $data['uuid'],
            $data['active'],
            $data['display_content'],
            $data['display_interval'],
            SubscriptionDeliveryAddressData::create($data['address']),
            new \DateTimeImmutable($data['next_billing'])
            new \DateTimeImmutable($data['next_billing']),
            CarbonImmutable::parse($data['created_at']),
            CarbonImmutable::parse($data['end_date']),
            $fingerprint
        );
    }

    public static function collection(array $data): array
    {
        $collection = [];

        foreach ($data as $item) {
            $collection[] = self::create($item);
        }

        return $collection;
    }
}
