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

class TransactionData
{
    /**
     * @var string
     */
    public $transaction_id;
    /**
     * @var string
     */
    public $gateway;
    /**
     * @var float
     */
    public $amount;
    /**
     * @var bool
     */
    public $paid;
    /**
     * @var bool
     */
    public $refunded;
    /**
     * @var float
     */
    public $amount_refunded;
    /**
     * @var string|null
     */
    public $failure_message;
    /**
     * @var string|null
     */
    public $failure_code;
    /**
     * @var \DateTimeImmutable
     */
    public $created_at;

    private function __construct(string             $transaction_id,
                                 string             $gateway,
                                 float              $amount,
                                 bool               $paid,
                                 bool               $refunded,
                                 float              $amount_refunded,
                                 ?string            $failure_message,
                                 ?string            $failure_code,
                                 \DateTimeImmutable $created_at)
    {
        $this->transaction_id = $transaction_id;
        $this->gateway = $gateway;
        $this->amount = $amount;
        $this->paid = $paid;
        $this->refunded = $refunded;
        $this->amount_refunded = $amount_refunded;
        $this->failure_message = $failure_message;
        $this->failure_code = $failure_code;
        $this->created_at = $created_at;
    }

    public static function create(array $data): TransactionData
    {
        return new self(
            $data['transaction_id'],
            $data['gateway'],
            $data['amount'],
            $data['paid'],
            $data['refunded'],
            $data['amount_refunded'],
            $data['failure_message'],
            $data['failure_code'],
            new \DateTimeImmutable($data['created_at'])
        );
    }
}
