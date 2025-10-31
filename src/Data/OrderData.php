<?php
/**
 * @author    Metrogeek SAS <support@ciklik.co>
 * @copyright Since 2017 Metrogeek SAS
 * @license   https://opensource.org/license/afl-3-0-php/ Academic Free License (AFL 3.0)
 */

namespace PrestaShop\Module\Ciklik\Data;

use Ciklik;
use Configuration;
use DateTimeImmutable;

if (!defined('_PS_VERSION_')) {
    exit;
}

class OrderData
{
    const STATUS_COMPLETED = 'completed';
    /**
     * @var int
     */
    public $ciklik_order_id;
    /**
     * @var string
     */
    public $ciklik_user_uuid;
    /**
     * @var string
     */
    public $status;
    /**
     * @var string
     */
    public $paid_transaction_id;
    /**
     * @var string
     */
    public $paid_class_key;
    /**
     * @var DateTimeImmutable
     */
    public $created_at;
    /**
     * @var string
     */
    public $subscription_uuid;
    /**
     * @var string
     */
    public $total_paid;

    private function __construct(int $ciklik_order_id,
        string $ciklik_user_uuid,
        string $status,
        string $paid_transaction_id,
        string $paid_class_key,
        DateTimeImmutable $created_at,
        $subscription_uuid,
        $total_paid)
    {
        $this->ciklik_order_id = $ciklik_order_id;
        $this->ciklik_user_uuid = $ciklik_user_uuid;
        $this->status = $status;
        $this->paid_transaction_id = $paid_transaction_id;
        $this->paid_class_key = $paid_class_key;
        $this->created_at = $created_at;
        $this->subscription_uuid = $subscription_uuid;
        $this->total_paid = $total_paid;
    }

    public static function create(array $data): OrderData
    {
        return new self(
            $data['order_id'],
            $data['user_uuid'],
            $data['status'],
            $data['paid_transaction_id'],
            $data['paid_class_key'],
            new DateTimeImmutable($data['created_at']),
            $data['subscription_uuid'],
            self::formatPrice($data['total_paid'])
        );
    }

    public function getOrderState()
    {
        if ($this->status === self::STATUS_COMPLETED) {
            return (int) Configuration::get(Ciklik::CONFIG_ORDER_STATE);
        }

        return (int) Configuration::get('PS_OS_ERROR');
    }

    public function getPspName()
    {
        switch ($this->paid_class_key) {
            case 'PaypalVault':
                $name = 'Paypal (Ciklik)';
                break;
            case 'PayPlugCreditCard':
                $name = 'Payplug (Ciklik)';
                break;
            case 'StripeCreditCard':
                $name = 'Stripe (Ciklik)';
                break;
            default:
                $name = 'Ciklik';
        }

        return $name;
    }

    public function formatPrice($price) {
        $price = str_replace([' ', ','], ['', '.'], $price); // Nettoie espaces + virgule
        return number_format((float)$price, 2, '.', '');
    }
}
