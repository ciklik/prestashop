<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Data;

class OrderItemData
{
    /**
     * @var int
     */
    public $prestashop_id;
    /**
     * @var string
     */
    public $name;
    /**
     * @var string
     */
    public $type;
    /**
     * @var float
     */
    public $price;
    /**
     * @var float
     */
    public $tax;
    /**
     * @var int
     */
    public $quantity;

    private function __construct(int    $prestashop_id,
                                 string $name,
                                 string $type,
                                 float  $price,
                                 float  $tax,
                                 int    $quantity)
    {
        $this->prestashop_id = $prestashop_id;
        $this->name = $name;
        $this->type = $type;
        $this->price = $price;
        $this->tax = $tax;
        $this->quantity = $quantity;
    }

    public static function create(array $data): OrderItemData
    {
        return new self(
            $data['external_id'],
            $data['name'],
            $data['type'],
            $data['price'],
            $data['tax'],
            $data['quantity']
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
