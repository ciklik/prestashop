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

class OrderData
{
    const STATUS_COMPLETED = 'completed';

    // État du paiement exposé par Ciklik (champ absent sur une plateforme plus ancienne : comportement d'avant).
    const PAYMENT_PAID = 'paid';
    const PAYMENT_PENDING = 'pending';
    const PAYMENT_FAILED = 'failed';
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
     * @var string|null
     */
    public $paid_transaction_id;
    /**
     * @var string|null
     */
    public $paid_class_key;
    /**
     * @var \DateTimeImmutable
     */
    public $created_at;
    /**
     * @var string|null
     */
    public $subscription_uuid;
    /**
     * @var string
     */
    public $total_paid;

    /**
     * @var int|null
     */
    public $prestashop_order_id;

    /**
     * @var string|null paid, pending ou failed ; null si Ciklik ne l'expose pas
     */
    public $payment_status;

    /**
     * @var string|null passerelle de la dernière transaction, payée ou non
     */
    public $payment_class_key;

    /**
     * @var int|null panier PrestaShop lié à la commande par Ciklik ; null si Ciklik ne l'expose pas
     */
    public $prestashop_cart_id;

    private function __construct(int $ciklik_order_id,
        string $ciklik_user_uuid,
        string $status,
        $paid_transaction_id,
        $paid_class_key,
        \DateTimeImmutable $created_at,
        $subscription_uuid,
        $total_paid,
        $prestashop_order_id = null,
        $payment_status = null,
        $payment_class_key = null,
        $prestashop_cart_id = null)
    {
        $this->ciklik_order_id = $ciklik_order_id;
        $this->ciklik_user_uuid = $ciklik_user_uuid;
        $this->status = $status;
        $this->paid_transaction_id = $paid_transaction_id;
        $this->paid_class_key = $paid_class_key;
        $this->created_at = $created_at;
        $this->subscription_uuid = $subscription_uuid;
        $this->total_paid = $total_paid;
        $this->prestashop_order_id = $prestashop_order_id;
        $this->payment_status = $payment_status;
        $this->payment_class_key = $payment_class_key;
        $this->prestashop_cart_id = $prestashop_cart_id;
    }

    public static function create(array $data): OrderData
    {
        return new self(
            $data['order_id'],
            $data['user_uuid'],
            $data['status'],
            $data['paid_transaction_id'] ?? null,
            $data['paid_class_key'] ?? null,
            new \DateTimeImmutable($data['created_at']),
            $data['subscription_uuid'] ?? null,
            self::formatPrice($data['total_paid'] ?? '0'),
            isset($data['prestashop_order_id']) ? (int) $data['prestashop_order_id'] : null,
            isset($data['payment_status']) && is_string($data['payment_status']) ? $data['payment_status'] : null,
            isset($data['payment_class_key']) && is_string($data['payment_class_key']) ? $data['payment_class_key'] : null,
            isset($data['prestashop_cart_id']) ? (int) $data['prestashop_cart_id'] : null
        );
    }

    public function getOrderState()
    {
        // Paiement pas encore encaissé (prélèvement bancaire PayPal) : commande créée sans paiement, à préparer
        // seulement quand Ciklik confirme. Sans l'état (installation incomplète), comportement d'avant.
        if ($this->isPaymentPending() && self::awaitingPaymentState() > 0) {
            return self::awaitingPaymentState();
        }

        if ($this->status === self::STATUS_COMPLETED) {
            return (int) \Configuration::get(\Ciklik::CONFIG_ORDER_STATE);
        }

        return (int) \Configuration::get('PS_OS_ERROR');
    }

    /**
     * Commande terminée côté Ciklik dont le paiement n'est pas encore encaissé.
     *
     * @return bool
     */
    public function isPaymentPending()
    {
        return $this->payment_status === self::PAYMENT_PENDING;
    }

    /**
     * État vers lequel passer une commande PrestaShop « en attente de paiement » d'après le paiement relu chez
     * Ciklik : l'état payé configuré, l'état « erreur de paiement », ou null quand rien ne doit changer (paiement toujours en
     * attente, commande qui n'est plus en attente, réglages absents). Ne réécrit jamais un état choisi depuis.
     *
     * @param int $currentStateId état actuel de la commande PrestaShop
     *
     * @return int|null
     */
    public function paymentUpdateTargetState($currentStateId)
    {
        $awaiting = self::awaitingPaymentState();
        if ($awaiting <= 0 || (int) $currentStateId !== $awaiting) {
            return null;
        }

        if ($this->payment_status === self::PAYMENT_PAID && !empty($this->paid_transaction_id)) {
            $paid = (int) \Configuration::get(\Ciklik::CONFIG_ORDER_STATE);

            return $paid > 0 ? $paid : null;
        }

        if ($this->payment_status === self::PAYMENT_FAILED) {
            // Ni annulation ni remise en stock : l'abonnement Ciklik reste actif, le marchand régularise.
            $error = (int) \Configuration::get('PS_OS_ERROR');

            return $error > 0 ? $error : null;
        }

        return null;
    }

    /**
     * Garde anti-rejeu : une commande que Ciklik a liée à un panier ne se valide que sur ce panier. Sans lien
     * connu (plateforme plus ancienne, première validation), aucune restriction.
     *
     * @param int|string $cartId panier courant
     *
     * @return bool
     */
    public function isForCart($cartId)
    {
        // Panier inconnu : accepté seulement d'une plateforme qui n'expose pas encore l'état du paiement (ancienne
        // version). Une commande au paiement exposé sans panier n'est rattachée à aucun.
        if ($this->prestashop_cart_id === null || $this->prestashop_cart_id <= 0) {
            return $this->payment_status === null;
        }

        return $this->prestashop_cart_id === (int) $cartId;
    }

    /**
     * @return int identifiant de l'état « en attente de paiement » du module, 0 s'il n'existe pas
     */
    public static function awaitingPaymentState()
    {
        return (int) \Configuration::get(\Ciklik::CONFIG_OS_AWAITING_PAYMENT);
    }

    public function getPspName()
    {
        switch ($this->paid_class_key !== null ? $this->paid_class_key : $this->payment_class_key) {
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

    public static function formatPrice($price)
    {
        $price = str_replace([' ', ','], ['', '.'], $price); // Nettoie espaces + virgule

        return number_format((float) $price, 2, '.', '');
    }

    /**
     * Crée une collection d'instances de OrderData à partir d'un tableau de données.
     *
     * @param array $data Le tableau de données à partir duquel les instances seront créées
     *
     * @return array Un tableau contenant les instances de OrderData
     */
    public static function collection(array $data): array
    {
        $collection = [];

        foreach ($data as $item) {
            $collection[] = self::create($item);
        }

        return $collection;
    }
}
