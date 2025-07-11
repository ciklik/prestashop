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
    /**
     * @var array
     */
    public $upsells;
    /**
     * @var int|null
     */
    public $frequency_id;
    /**
     * @var array
     */
    public $customizations;

    /**
     * @param int $id_customer
     * @param int $id_address_delivery
     * @param int $id_address_invoice
     * @param int $id_lang
     * @param int $id_currency
     * @param int $id_carrier_reference
     * @param array $upsells
     * @param int|null $frequency_id
     * @param array $customizations
     */
    private function __construct(int $id_customer,
        int $id_address_delivery,
        int $id_address_invoice,
        int $id_lang,
        int $id_currency,
        int $id_carrier_reference,
        array $upsells = [],
        $frequency_id = null,
        array $customizations = [])
    {
        $this->id_customer = $id_customer;
        $this->id_address_delivery = $id_address_delivery;
        $this->id_address_invoice = $id_address_invoice;
        $this->id_lang = $id_lang;
        $this->id_currency = $id_currency;
        $this->id_carrier_reference = $id_carrier_reference;
        $this->upsells = $upsells;
        $this->frequency_id = $frequency_id;
        $this->customizations = $customizations;
    }

    public static function create(array $data): CartFingerprintData
    {
        return new self(
            $data['id_customer'],
            $data['id_address_delivery'],
            $data['id_address_invoice'],
            $data['id_lang'],
            $data['id_currency'],
            $data['id_carrier_reference'],
            isset($data['upsells']) ? $data['upsells'] : [],
            isset($data['frequency_id']) ? $data['frequency_id'] : null,
            isset($data['customizations']) ? $data['customizations'] : []
        );
    }

    public static function fromCart(Cart $cart, array $upsells = [], $frequency_id = null): CartFingerprintData
    {
        $carrier = new Carrier($cart->id_carrier);
        $customizations = \PrestaShop\Module\Ciklik\Managers\CiklikCustomization::getDetailedCustomizationDataFromCart($cart);

        return new self(
            $cart->id_customer,
            $cart->id_address_delivery,
            $cart->id_address_invoice,
            $cart->id_lang,
            $cart->id_currency,
            $carrier->id_reference,
            $upsells,
            $frequency_id,
            $customizations
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
            $data['id_carrier_reference'],
            isset($data['upsells']) ? $data['upsells'] : [],
            isset($data['frequency_id']) ? $data['frequency_id'] : null,
            isset($data['customizations']) ? $data['customizations'] : []
        );
    }

    public function encodeDatas(): string
    {
        $method = 'seria' . 'lize';

        return $method(get_object_vars($this));
    }
}
