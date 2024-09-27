<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Data;

use Carrier;
use Cart;
use Tools;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CartFingerprintData
{
    /**
     * @var int
     */
    public $id_customer;
    /**
     * @var int
     */
    public $id_address_delivery;
    /**
     * @var int
     */
    public $id_address_invoice;
    /**
     * @var int
     */
    public $id_lang;
    /**
     * @var int
     */
    public $id_currency;
    /**
     * @var int
     */
    public $id_carrier_reference;

    private function __construct(int $id_customer,
        int $id_address_delivery,
        int $id_address_invoice,
        int $id_lang,
        int $id_currency,
        int $id_carrier_reference)
    {
        $this->id_customer = $id_customer;
        $this->id_address_delivery = $id_address_delivery;
        $this->id_address_invoice = $id_address_invoice;
        $this->id_lang = $id_lang;
        $this->id_currency = $id_currency;
        $this->id_carrier_reference = $id_carrier_reference;
    }

    public static function create(array $data): CartFingerprintData
    {
        return new self(
            $data['id_customer'],
            $data['id_address_delivery'],
            $data['id_address_invoice'],
            $data['id_lang'],
            $data['id_currency'],
            $data['id_carrier_reference']
        );
    }

    public static function fromCart(Cart $cart): CartFingerprintData
    {
        $carrier = new Carrier($cart->id_carrier);

        return new self(
            $cart->id_customer,
            $cart->id_address_delivery,
            $cart->id_address_invoice,
            $cart->id_lang,
            $cart->id_currency,
            $carrier->id_reference
        );
    }

    public static function extractDatas(string $fingerprint): CartFingerprintData
    {
        $data = Tools::unSerialize($fingerprint);

        return new self(
            $data['id_customer'],
            $data['id_address_delivery'],
            $data['id_address_invoice'],
            $data['id_lang'],
            $data['id_currency'],
            $data['id_carrier_reference']
        );
    }

    public function encodeDatas(): string
    {
        $method = 'seria' . 'lize';

        return $method(get_object_vars($this));
    }
}
