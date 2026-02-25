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

class PaymentMethodData
{
    /**
     * @var int
     */
    public $id;
    /**
     * @var array
     */
    public $name;
    /**
     * @var string
     */
    public $class_key;
    /**
     * @var array|null
     */
    public $description;

    private function __construct(int $id,
        array $name,
        string $class_key,
        ?array $description)
    {
        $this->id = $id;
        $this->name = $name;
        $this->class_key = $class_key;
        $this->description = $description;
    }

    public static function create(array $data): PaymentMethodData
    {
        return new self(
            $data['id'],
            $data['name'],
            $data['class_key'],
            $data['description'],
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
