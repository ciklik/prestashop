<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Data;

class SubscriptionDeliveryAddressData
{
    /**
     * @var string
     */
    public $first_name;
    /**
     * @var string
     */
    public $last_name;
    /**
     * @var string
     */
    public $address;
    /**
     * @var string|null
     */
    public $address1;
    /**
     * @var string
     */
    public $postcode;
    /**
     * @var string
     */
    public $city;
    /**
     * @var string
     */
    public $country;

    private function __construct(string      $first_name,
                                 string      $last_name,
                                 string      $address,
                                 ?string $address1,
                                 string      $postcode,
                                 string      $city,
                                 string      $country)
    {
        $this->first_name = $first_name;
        $this->last_name = $last_name;
        $this->address = $address;
        $this->address1 = $address1;
        $this->postcode = $postcode;
        $this->city = $city;
        $this->country = $country;
    }

    public static function create(array $data): SubscriptionDeliveryAddressData
    {
        return new self(
            $data['first_name'],
            $data['last_name'],
            $data['address'],
            $data['address1'],
            $data['postcode'],
            $data['city'],
            $data['country']['name']
        );
    }
}
