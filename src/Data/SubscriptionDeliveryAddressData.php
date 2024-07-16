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

class SubscriptionDeliveryAddressData
{
    private function __construct(
        string      $first_name,
        string      $last_name,
        string      $address,
        ?string $address1,
        string      $postcode,
        string      $city,
        string      $country
    )
    {
        $this->first_name = $first_name;
        $this->last_name = $last_name;
        $this->address = $address;
        $this->address1 = $address1;
        $this->postcode = $postcode;
        $this->city = $city;
        $this->country = $country;
    }

    public static function create($address_id)
    {
        $address = new \Address($address_id);
        // Vérifier si l'adresse a été trouvée
        if (\Validate::isLoadedObject($address)) {
            $data['first_name'] = $address->firstname;
            $data['last_name'] = $address->lastname;
            $data['address'] = $address->address1;
            $data['address1'] = $address->address2;
            $data['postcode'] = $address->postcode;
            $data['city'] = $address->city;
            $data['country']['name'] = $address->country;
        } else {
            $data['first_name'] = '';
            $data['last_name'] = '';
            $data['address'] = '';
            $data['address1'] = '';
            $data['postcode'] = '';
            $data['city'] = '';
            $data['country']['name']= '';
        }

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
